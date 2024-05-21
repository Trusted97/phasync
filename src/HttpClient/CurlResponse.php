<?php
namespace phasync\HttpClient;

use CurlHandle;
use InvalidArgumentException;
use JsonSerializable;
use phasync;
use phasync\Psr\ComposableStream;
use phasync\Services\CurlMulti;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Represents an HTTP response generated by a curl request.
 * 
 * @package phasync\HttpClient
 */
class CurlResponse implements ResponseInterface {

    public readonly HttpClientOptions $options;
    public readonly string $url;
    public readonly string $method;
    private CurlHandle $curl;
    private array $responseHeaders = [];
    private string $buffer = '';
    private ?int $downloadSize = null;
    private ?int $downloaded = null;
    private ?int $uploadSize = null;
    private ?int $uploaded = null;
    private bool $done = false;
    private ?StreamInterface $body = null;
    private bool $haveHeaders = false;
    private ?string $protocolVersion = null;
    private ?int $statusCode = null;
    private ?string $reasonPhrase = null;
    private ?int $errorNumber = null;
    private ?string $errorMessage = null;

    public function __construct(string $method, string $url, mixed $requestData = null, HttpClientOptions $options) {
        $this->curl = \curl_init($url);
        switch (\strtoupper($method)) {
            case 'GET': 
                // For GET requests, if there is any request data, append it to the URL
                if ($requestData !== null) {
                    if ($requestData instanceof JsonSerializable) {
                        $requestData = $requestData->jsonSerialize();
                    }
                    if (is_array($requestData)) {
                        $requestData = \http_build_query($requestData);
                    } elseif (!is_string($requestData)) {
                        throw new InvalidArgumentException("Request data must be provided as an array, a jsonSerializable object or a string");
                    }
                    if (\str_contains($url, '?')) {
                        $url .= '&' . $requestData;
                    } else {
                        $url .= '?' . $requestData;
                    }
                }
                curl_setopt($this->curl, CURLOPT_URL, $url);
                break;
            case 'POST':
                // For POST requests, set the request data as the POSTFIELDS option
                \curl_setopt($this->curl, \CURLOPT_POST, true);
                \curl_setopt($this->curl, \CURLOPT_POSTFIELDS, $requestData);
                break;
            case 'PUT':
                // For PUT requests, set the request data as the PUTFIELDS option
                \curl_setopt($this->curl, \CURLOPT_PUT, true);
                \curl_setopt($this->curl, \CURLOPT_POSTFIELDS, $requestData);
                break;
            default: 
                // For other request methods, set the request data as the custom request body
                \curl_setopt($this->curl, \CURLOPT_CUSTOMREQUEST, $method);
                \curl_setopt($this->curl, \CURLOPT_POSTFIELDS, $requestData);
                break;
        }
        $this->url = $url;
        $this->method = $method;
        $this->applyOptions($options);
        \curl_setopt($this->curl, \CURLOPT_HEADERFUNCTION, $this->curlHeaderFunction(...));
        \curl_setopt($this->curl, \CURLOPT_WRITEFUNCTION, $this->curlWriteFunction(...));
        \curl_setopt($this->curl, \CURLOPT_XFERINFOFUNCTION, $this->curlXferInfoFunction(...));
        $this->body = new ComposableStream(
            readFunction: function(int $length) {
                if ($this->errorNumber !== null) {
                    $this->throwError();
                }
                while ($this->buffer === '' && !$this->done) {
                    // Block the current Fiber until something happens
                    // with $this->stream
                    phasync::awaitFlag($this);
                    if ($this->errorNumber !== null) {
                        $this->throwError();
                    }
                }
                if ($this->buffer === '' && $this->done) {
                    return null;
                }
                $chunk = \substr($this->buffer, 0, $length);
                $this->buffer = \substr($this->buffer, \strlen($chunk));
                return $chunk;
            },
            getSizeFunction: $this->getDownloadSize()
        );
        //\curl_multi_add_handle($this->curlMulti, $this->curl);
        phasync::go(function() {
            /**
             * Start the curl handle via the event loop, and return when it is
             * done.
             */
            CurlMulti::await($this->curl);
            $this->done = true;
            phasync::raiseFlag($this);
            if (0 !== ($errorNumber = \curl_errno($this->curl))) {
                $this->errorNumber = $errorNumber;
                $this->errorMessage = \curl_error($this->curl);
            }
        });
    }

    public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface {
        $this->waitForHeaders();

        $c = clone $this;
        $c->statusCode = $code;
        $c->reasonPhrase = $reasonPhrase;

        return $c;
    }

    public function withProtocolVersion(string $version): MessageInterface {
        $this->waitForHeaders();
        
        $c = clone $this;
        $c->protocolVersion = $version;

        return $c;
    }

    public function withHeader(string $name, $value): MessageInterface {
        $this->waitForHeaders();

        $c = clone $this;
        $c->responseHeaders[\strtolower($name)] = \is_array($value) ? $value : [ (string) $value ];

        return $c;
    }

    public function withAddedHeader(string $name, $value): MessageInterface {
        $this->waitForHeaders();

        $c = clone $this;
        $name = \strtolower($name);
        if (\is_array($value)) {
            foreach ($value as $v) {
                $c->responseHeaders[$name][] = $v;
            }
        } else {
            $c->responseHeaders[$name][] = (string) $value;
        }

        return $c;
    }

    public function withoutHeader(string $name): MessageInterface {
        $this->waitForHeaders();

        $c = clone $this;
        unset($c->responseHeaders[\strtolower($name)]);

        return $c;
    }

    public function withBody(StreamInterface $body): MessageInterface {
        $this->waitForHeaders();

        $c = clone $this;
        $c->body = $body;

        return $c;
    }

    public function getProtocolVersion(): string {
        $this->waitForHeaders();

        return $this->protocolVersion;
    }

    public function getStatusCode(): int {
        $this->waitForHeaders();
        return $this->statusCode;
    }

    public function getReasonPhrase(): string {
        $this->waitForHeaders();
        return $this->reasonPhrase;
    }

    public function hasHeader(string $name): bool {
        $this->waitForheaders();
        return isset($this->responseHeaders[\strtolower($name)]);
    }

    public function getHeader(string $name): array {
        $this->waitForHeaders();
        $name = \strtolower($name);
        return isset($this->responseHeaders[$name]) ? [$this->responseHeaders[$name]] : [];
    }

    public function getHeaderLine(string $name): string {
        $this->waitForHeaders();
        return implode(', ', $this->getHeader($name));
    }

    public function getHeaders(): array {
        $this->waitForHeaders();
        return $this->responseHeaders;
    }

    public function getBody(): StreamInterface {
        return $this->body;
    }


    public function getDownloadSize(): ?int {
        return $this->downloadSize;
    }

    public function getDownloadedBytes(): ?int {
        return $this->downloaded;
    }

    public function getUploadSize(): ?int {
        return $this->uploadSize;
    }

    public function getUploaded(): ?int {
        return $this->uploaded;
    }

    private function throwError(): void {
        throw new RuntimeException($this->errorMessage, $this->errorNumber);
    }

    private function curlHeaderFunction($curl, $header) {
        $trimmed = trim($header);
        if (empty($trimmed)) {
            return strlen($header);  // Ignore empty lines, which can occur in HTTP responses.
        }
    
        if ($this->statusCode === null && str_starts_with($trimmed, 'HTTP/')) {
            // Parse status line
            [$protocol, $code, $phrase] = explode(" ", $trimmed, 3) + [null, null, null];
            $this->protocolVersion = substr($protocol, strpos($protocol, '/') + 1);
            $this->statusCode = intval($code);
            $this->reasonPhrase = $phrase ?: '';
            return strlen($header);
        }
    
        // Parse headers
        $parts = explode(':', $trimmed, 2);
        if (count($parts) === 2) {
            list($key, $value) = $parts;
            $key = strtolower(trim($key));
            $value = trim($value);
            $this->responseHeaders[$key][] = $value;
        }
    
        return strlen($header);
    }

    private function curlWriteFunction(CurlHandle $curl, string $chunk): int {
        $this->haveHeaders = true;
        $this->buffer .= $chunk;
        phasync::raiseFlag($this);
        return \strlen($chunk);
    }

    private function curlXferInfoFunction(CurlHandle $curl, $downloadSize, $downloaded, $uploadSize, $uploaded) {
        // This function don't appear to be invoked.
        $this->downloadSize = $downloadSize;
        $this->downloaded = $downloaded;
        $this->uploadSize = $uploadSize;
        $this->uploaded = $uploaded;
        // Notify the event loop that something happened with $this->stream
        phasync::raiseFlag($this);
    }

    private function waitForHeaders(): void {
        while (!$this->haveHeaders && !$this->done && \is_resource($this->curl)) {
            phasync::awaitFlag($this);
            if ($this->errorNumber !== null) {
                $this->throwError();
            }
        }
    }

    private function applyOptions(HttpClientOptions $options): void {
        foreach ([
            'userAgent' => \CURLOPT_USERAGENT,
            'autoReferer' => \CURLOPT_AUTOREFERER,
            'crlf' => \CURLOPT_CRLF,
            'disallowUsernameInUrl' => \CURLOPT_DISALLOW_USERNAME_IN_URL,
            'dnsShuffleAddresses' => \CURLOPT_DNS_SHUFFLE_ADDRESSES,
            'haProxyProtocol' => \CURLOPT_HAPROXYPROTOCOL,
            'followLocation' => \CURLOPT_FOLLOWLOCATION,
            'forbidReuse' => \CURLOPT_FORBID_REUSE,
            'freshConnect' => \CURLOPT_FRESH_CONNECT,
            'tcpNoDelay' => \CURLOPT_TCP_NODELAY,
            'httpProxyTunnel' => \CURLOPT_HTTPPROXYTUNNEL,
            'httpContentDecoding' => \CURLOPT_HTTP_CONTENT_DECODING,
            'sslVerifyPeer' => \CURLOPT_SSL_VERIFYPEER,
            'timeoutMs' => \CURLOPT_TIMEOUT_MS,
            'connectTimeoutMs' => \CURLOPT_CONNECTTIMEOUT_MS,
            'maxRedirs' => \CURLOPT_MAXREDIRS,
            'cookie' => \CURLOPT_COOKIE,
            'cookieFile' => \CURLOPT_COOKIEFILE,
            'cookieJar' => \CURLOPT_COOKIEJAR,
            'encoding' => \CURLOPT_ENCODING,
            'postFields' => \CURLOPT_POSTFIELDS,
            'referer' => \CURLOPT_REFERER,
            'range' => \CURLOPT_RANGE,
            'username' => \CURLOPT_USERNAME,
            'password' => \CURLOPT_PASSWORD,
            'headers' => \CURLOPT_HEADER,
            'resolve' => \CURLOPT_RESOLVE,
            'pathAsIs' => \CURLOPT_PATH_AS_IS,
            'sslEnableAlpn' => \CURLOPT_SSL_ENABLE_ALPN,
            'sslEnableNpn' => \CURLOPT_SSL_ENABLE_NPN,
            'proxySslVerifyPeer' => \CURLOPT_PROXY_SSL_VERIFYPEER,
            'proxySslVerifyHost' => \CURLOPT_PROXY_SSL_VERIFYHOST,
            'proxy' => \CURLOPT_PROXY,
            'proxyAuth' => \CURLOPT_PROXYAUTH,
            'proxyPort' => \CURLOPT_PROXYPORT,
            'proxyType' => \CURLOPT_PROXYTYPE,
            'resumeFrom' => \CURLOPT_RESUME_FROM,
            'ipResolve' => \CURLOPT_IPRESOLVE,
        ] as $prop => $opt) {
            if ($options[$prop] !== null) {
                \curl_setopt($this->curl, $opt, $options[$prop]);
            }
        }
    }
}
