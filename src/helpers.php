<?php

declare(strict_types=1);

if (!function_exists('x')) {
    /**
     * Global shortcut for {@see \Xcapher\x()}. Only defined when no other `x()` function exists.
     */
    function x(mixed $value): Xcapher\Xcapher
    {
        return new Xcapher\Xcapher($value);
    }
}
