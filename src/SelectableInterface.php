<?php

namespace phasync;

/**
 * Selectable objects can be used together with {@see phasync::select()} to wait for
 * multiple events simultaneously.
 */
interface SelectableInterface
{
    /**
     * Wait for the resource to be non-blocking.
     */
    public function await(float $timeout = \PHP_FLOAT_MAX): void;

    /**
     * Returns true when accessing the object will not block (for example if
     * data is available or if the source is closed or in a failed state).
     */
    public function isReady(): bool;
}
