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

/**
 * Read properties outside class scope so only public properties are visible.
 *
 * @internal
 *
 * @return array<mixed>
 */
function publicObjectProperties(object $object): array
{
    return get_object_vars($object);
}
