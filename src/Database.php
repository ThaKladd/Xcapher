<?php

declare(strict_types=1);

namespace Xcapher;

use Xcapher\Exception\EscapeException;

/**
 * Supported database dialects for escaping, quoting and identifier quoting.
 */
enum Database: string
{
    case MySql = 'mysql';
    case PostgreSql = 'pgsql';
    case Sqlite = 'sqlite';

    /**
     * Resolves the dialect of a connection (mysqli, PgSql\Connection or PDO), or passes a dialect through.
     *
     * @throws EscapeException when the connection is not supported
     */
    public static function detect(mixed $connection): self
    {
        if ($connection instanceof self) {
            return $connection;
        }

        if ($connection instanceof \mysqli) {
            return self::MySql;
        }

        if ($connection instanceof \PgSql\Connection) {
            return self::PostgreSql;
        }

        if ($connection instanceof \PDO) {
            try {
                $driver = $connection->getAttribute(\PDO::ATTR_DRIVER_NAME);
            } catch (\Throwable $e) {
                throw new EscapeException('Could not read the PDO driver name.', 0, $e);
            }

            $name = \is_string($driver) ? $driver : get_debug_type($driver);

            return self::tryFrom($name) ?? throw new EscapeException(\sprintf('Unsupported PDO driver "%s".', $name));
        }

        throw new EscapeException(\sprintf('Unsupported database connection of type %s; expected mysqli, PgSql\Connection, PDO or %s.', get_debug_type($connection), self::class));
    }

    /**
     * Quotes an identifier (table, column, ...) for this dialect, doubling any embedded quote character.
     *
     * @throws EscapeException when the identifier is empty or contains a NUL byte
     */
    public function quoteIdentifier(string $identifier): string
    {
        if ($identifier === '' || str_contains($identifier, "\0")) {
            throw new EscapeException('An identifier must be a non-empty string without NUL bytes.');
        }

        $quote = $this === self::MySql ? '`' : '"';

        return $quote . str_replace($quote, $quote . $quote, $identifier) . $quote;
    }
}
