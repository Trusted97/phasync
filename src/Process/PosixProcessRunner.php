<?php

namespace phasync\Process;

final class PosixProcessRunner implements ProcessInterface
{
    /**
     * The command and arguments.
     *
     * @var string[]
     */
    private array $command;

    /**
     * The proc_open resource.
     *
     * @var resource
     */
    private mixed $process = null;

    /**
     * The stream resources to communicate with the process.
     *
     * @var resource[]
     */
    private array $pipes;

    /**
     * The last retrieved status from proc_get_status().
     *
     * @var array{command: string, pid: int, running: bool, signaled: bool, stopped: bool, exitcode: int, termsig: int, stopsig: int}
     */
    private ?array $status = null;

    /**
     * Launch a new process.
     *
     * @param string[]              $command The command and arguments as array elements
     * @param string                $cwd     The working directory of the process
     * @param array<string, string> $env     The environment for the process (or null to inherit env from the PHP process)
     *
     * @return void
     */
    public function __construct(array $command, ?string $cwd = null, ?array $env = null)
    {
        $this->command = $command;

        \set_error_handler(static function (int $code, string $message): never {
            throw new \RuntimeException("Process could not be started: Errno: {$code}; {$message}");
        });

        try {
            $process = @\proc_open($command, [
                ['pipe', 'r'],
                ['pipe', 'w'],
                ['pipe', 'w'],
            ], $pipes, $cwd, $env);
        } finally {
            \restore_error_handler();
        }

        if (!\is_resource($process)) {
            throw new \RuntimeException("Failed to launch process '" . \implode(' ', $command) . "'");
        }

        $this->process = $process;
        $this->pipes   = $pipes;

        foreach ($this->pipes as $pipe) {
            \stream_set_blocking($pipe, false);
            \stream_set_read_buffer($pipe, 0);
            \stream_set_write_buffer($pipe, 0);
        }

        $this->poll();
    }

    public function getStream(int $fd): mixed
    {
        return $this->pipes[$fd] ?? null;
    }

    public function stop(): bool
    {
        if ($this->isRunning()) {
            $this->sigterm();
            $t = \microtime(true);
            while ($this->isRunning() && \microtime(true) - $t < 1) {
                if (\phasync::isRunning()) {
                    \phasync::sleep(0.05);
                } else {
                    \usleep(50000);
                }
            }
            if ($this->isRunning()) {
                $this->sigkill();
            }
            while ($this->isRunning() && \microtime(true) - $t < 5) {
                \phasync::sleep(0.05);
            }
        }

        return !$this->isRunning();
    }

    /**
     * Returns true if the process is still running.
     */
    public function isRunning(): bool
    {
        $this->poll();

        return $this->status['running'];
    }

    /**
     * Returns true if the process is stopped (generally via
     * {@see Process::sigstop()}).
     */
    public function isStopped(): bool
    {
        $this->poll();

        return $this->status['stopped'];
    }

    /**
     * Return the exitcode of the process, or false if the process
     * is still running.
     */
    public function getExitCode(): int|false
    {
        if (!$this->isRunning()) {
            return $this->status['exitcode'];
        }

        return false;
    }

    /**
     * Send a POSIX signal to the process.
     *
     * Sends a specified POSIX signal to the running process. If the process
     * is not running, the function will return false without sending a signal.
     *
     * @param int $signal the signal number to send (default: SIGTERM)
     *
     * @return bool true if the signal was successfully sent, false otherwise
     */
    public function sendSignal(int $signal = 15): bool
    {
        if (!$this->isRunning()) {
            return false;
        }

        return \proc_terminate($this->process, $signal);
    }

    /**
     * SIGTERM requests a process to terminate. It is a polite way to tell
     * a process to stop running. Unlike SIGKILL, this signal can be caught,
     * handled, and ignored, allowing a process to shut down gracefully.
     *
     * @throws \LogicException
     */
    public function sigterm(): bool
    {
        return $this->sendSignal(15);
    }

    /**
     * This signal forces a process to terminate immediately. Operating
     * systems typically use SIGKILL to deal with unresponsive processes.
     * It cannot be caught, handled, or ignored, making it a surefire
     * but potentially unsafe way to stop a process as it does not allow
     * for clean-up operations.
     *
     * @throws \LogicException
     */
    public function sigkill(): bool
    {
        return $this->sendSignal(\SIGKILL);
    }

    /**
     * This signal is typically sent when the user types the interrupt
     * character (usually Ctrl+C). It tells the process to interrupt
     * its current activity. SIGINT allows the process to clean up
     * nicely, releasing resources and saving state if necessary before
     * exiting.
     *
     * @throws \LogicException
     */
    public function sigint(): bool
    {
        return $this->sendSignal(\SIGINT);
    }

    /**
     * This signal pauses a process's execution. It can be used to
     * temporarily stop a process for later resumption with SIGCONT. Like
     * SIGKILL, SIGSTOP cannot be caught, handled, or ignored.
     *
     * @throws \LogicException
     */
    public function sigstop(): bool
    {
        return $this->sendSignal(\SIGSTOP);
    }

    /**
     * SIGCONT is used to resume a process previously stopped by SIGSTOP
     * or another stop signal. This signal allows for job control as well
     * as pausing and resuming processes.
     *
     * @throws \LogicException
     */
    public function sigcont(): bool
    {
        return $this->sendSignal(\SIGCONT);
    }

    /**
     * Originally sent when a terminal was closed, today SIGHUP is often
     * used to instruct background processes to reload their configuration
     * files or to gracefully restart. It can be caught and handled, which
     * allows applications to perform specific actions, such as re-reading
     * a configuration file.
     */
    public function sighup(): bool
    {
        return $this->sendSignal(\SIGHUP);
    }

    /**
     * Update the process status, unless the process is no longer running.
     */
    private function poll(): void
    {
        if (null !== $this->status && !$this->status['running']) {
            // process has terminated
            return;
        }
        $this->status = \proc_get_status($this->process);

        // Free the resources
        if (!$this->status['running']) {
            $this->process = null;
            foreach ($this->pipes as $pipe) {
                if (\is_resource($pipe)) {
                    \fclose($pipe);
                }
            }
        }
    }

    /**
     * Perform a Fiber-blocking read from the given process pipe.
     *
     * @param int $fd The file descriptor number
     *
     * @return string|null
     */
    public function read(int $fd = ProcessInterface::STDOUT): string|false
    {
        \phasync::readable($this->pipes[$fd]);

        return \stream_get_contents($this->pipes[$fd]);
    }

    /**
     * Perform a Fiber-blocking write to the given process pipe.
     *
     * @throws \FiberError
     * @throws \Throwable
     */
    public function write(string $data, int $fd = ProcessInterface::STDIN): int|false
    {
        if (!$this->isRunning()) {
            return false;
        }
        \phasync::writable($this->pipes[$fd]);

        return \fwrite($this->pipes[$fd], $data);
    }
}
