<?php

declare(strict_types=1);

namespace Xcapher;

/**
 * Wraps any value in an {@see Xcapher} instance.
 */
function x(mixed $value): Xcapher
{
    return new Xcapher($value);
}
