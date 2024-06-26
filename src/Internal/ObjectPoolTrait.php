<?php

namespace phasync\Internal;

trait ObjectPoolTrait
{
    private static array $pool        = [];
    private static int $instanceCount = 0;

    protected static function popInstance(): ?static
    {
        if (0 === self::$instanceCount) {
            return null;
        }

        return self::$pool[--self::$instanceCount];
    }

    protected function pushInstance(): void
    {
        self::$pool[self::$instanceCount++] = $this;
    }

    abstract public function returnToPool(): void;
}
