<?php

declare(strict_types=1);

namespace Xcapher\Exception;

/**
 * Thrown when a value cannot be converted, encoded or decoded into the requested form.
 */
final class CastException extends \UnexpectedValueException implements XcapherException
{
    public static function create(mixed $value, string $target, string $reason = ''): self
    {
        $message = \sprintf('Cannot convert value of type %s to %s', get_debug_type($value), $target);

        return new self($reason === '' ? $message . '.' : $message . ': ' . $reason);
    }

}
