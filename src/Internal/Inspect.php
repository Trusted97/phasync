<?php

namespace phasync\Internal;

/**
 * A small library of utility functions for enabling features in
 * phasync.
 */
final class Inspect
{
    /**
     * Get the reference count of a value. Do not use this function
     * extensively, and only for the purpose of protection against
     * deadlocks.
     */
    public static function getRefCount(mixed &$value): int
    {
        // This is quite horrible, but in lack of an API or alternative
        // ideas we resort to this. Benchmarking it reveals it can return
        // the reference count about 2500 times per millisecond, which is
        // surprising.
        if (\is_scalar($value) && !\is_string($value)) {
            return 0;
        }
        \ob_start();
        \debug_zval_dump($value);
        $output = \ob_get_contents();
        \ob_end_clean();
        $offset = \strpos($output, 'refcount(');
        if (false === $offset) {
            // This is not a variable, likely a constant/interned value
            return 0;
        }

        return \intval(\substr($output, $offset + 9, 10)) - 1;
    }
}
