<?php

declare(strict_types=1);

namespace Xcapher;

/**
 * The native PHP type of a value.
 *
 * Callables and iterables are not types of their own in PHP, so they are
 * checked with {@see Xcapher::isCallable()} and {@see Xcapher::isIterable()}.
 */
enum Type: string
{
    case Null = 'null';
    case Bool = 'bool';
    case Int = 'int';
    case Float = 'float';
    case String = 'string';
    case Array = 'array';
    case Object = 'object';
    case Resource = 'resource';

    /**
     * Detects the type of any value. Never throws; closed resources are reported as {@see Type::Resource}.
     */
    public static function of(mixed $value): self
    {
        return match (true) {
            $value === null => self::Null,
            \is_bool($value) => self::Bool,
            \is_int($value) => self::Int,
            \is_float($value) => self::Float,
            \is_string($value) => self::String,
            \is_array($value) => self::Array,
            \is_object($value) => self::Object,
            default => self::Resource,
        };
    }

    public function isScalar(): bool
    {
        return match ($this) {
            self::Bool, self::Int, self::Float, self::String => true,
            default => false,
        };
    }
}
