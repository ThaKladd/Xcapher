<?php

declare(strict_types=1);

namespace Xcapher\Tests\Live;

/**
 * Shared data for the live database tests.
 */
final class LiveValues
{
    /**
     * Strings that break naive SQL escaping.
     *
     * @return list<string>
     */
    public static function strings(bool $allowNul): array
    {
        $values = [
            "O'Reilly",
            "''",
            "\\'",
            '\\',
            'back\\slash\\\\',
            '"double" `back` quotes',
            "multi\nline\r\ntext\t",
            "'; DROP TABLE users; --",
            '/* comment */ -- comment # comment',
            '%_ wildcards',
            "\x1A control-Z",
            "Blåbær \u{1F600} \u{00A0}",
            '',
            str_repeat("'\\", 500),
        ];

        if ($allowNul) {
            $values[] = "nul\0byte";
        }

        return $values;
    }

    /**
     * Reads an environment variable, falling back to the default when it is unset or empty.
     */
    public static function env(string $name, string $default): string
    {
        $value = getenv($name);

        return \is_string($value) && $value !== '' ? $value : $default;
    }

    /**
     * Normalizes fetched rows to lists of nullable strings, whatever types the driver returned.
     *
     * @param array<mixed> $rows
     *
     * @return list<list<?string>>
     */
    public static function stringRows(array $rows): array
    {
        $result = [];

        foreach ($rows as $row) {
            $values = [];

            foreach (\is_array($row) ? $row : [$row] as $value) {
                $values[] = match (true) {
                    $value === null => null,
                    \is_bool($value) => $value ? '1' : '0',
                    \is_scalar($value) => (string) $value,
                    default => get_debug_type($value),
                };
            }

            $result[] = $values;
        }

        return $result;
    }
}
