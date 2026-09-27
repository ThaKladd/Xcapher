<?php

declare(strict_types=1);

namespace Xcapher\Tests\Live;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Xcapher\Database;
use Xcapher\Xcapher;

use function Xcapher\x;

/**
 * Runs the SQL helpers against a real PostgreSQL server through both ext-pgsql and PDO.
 *
 * Skipped unless XCAPHER_PGSQL_HOST is set (CI sets it). Optional: XCAPHER_PGSQL_PORT,
 * XCAPHER_PGSQL_USER, XCAPHER_PGSQL_PASSWORD, XCAPHER_PGSQL_DATABASE.
 */
#[CoversClass(Xcapher::class)]
#[CoversClass(Database::class)]
#[Group('database')]
final class PostgreSqlTest extends TestCase
{
    /**
     * @return iterable<string, array{'pgsql'|'pdo'}>
     */
    public static function driverProvider(): iterable
    {
        yield 'pgsql' => ['pgsql'];
        yield 'PDO' => ['pdo'];
    }

    /**
     * @param 'pgsql'|'pdo' $driver
     */
    #[DataProvider('driverProvider')]
    public function testDetectsTheDialect(string $driver): void
    {
        self::assertSame(Database::PostgreSql, Database::detect($this->connect($driver)));
    }

    /**
     * @param 'pgsql'|'pdo' $driver
     */
    #[DataProvider('driverProvider')]
    public function testQuotedAndEscapedStringsRoundTrip(string $driver): void
    {
        $connection = $this->connect($driver);
        $table = x('xcapher "test"')->identifier($connection);
        $column = x('the `value`')->identifier($connection);

        $this->execute($connection, \sprintf('CREATE TEMPORARY TABLE %s (id SERIAL PRIMARY KEY, %s TEXT)', $table, $column));

        // PostgreSQL text cannot contain NUL bytes at all.
        $values = LiveValues::strings(allowNul: false);

        foreach ($values as $value) {
            $this->execute($connection, \sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, $column, x($value)->quote($connection)));
            $this->execute($connection, \sprintf("INSERT INTO %s (%s) VALUES ('%s')", $table, $column, x($value)->escape($connection)));
        }

        $expected = [];

        foreach ($values as $value) {
            $expected[] = $value;
            $expected[] = $value;
        }

        self::assertSame($expected, $this->column($connection, \sprintf('SELECT %s FROM %s ORDER BY id', $column, $table)));
    }

    /**
     * @param 'pgsql'|'pdo' $driver
     */
    #[DataProvider('driverProvider')]
    public function testQuotedScalarsAreReadBackUnchanged(string $driver): void
    {
        $connection = $this->connect($driver);
        $sql = \sprintf(
            'SELECT (%s IS NULL)::text, (%s)::text, (%s)::text, (%s)::text, (%s)::text',
            x(null)->quote($connection),
            x(true)->quote($connection),
            x(false)->quote($connection),
            x(\PHP_INT_MIN)->quote($connection),
            x(1.5)->quote($connection),
        );

        self::assertSame(['true', 'true', 'false', (string) \PHP_INT_MIN, '1.5'], $this->row($connection, $sql));
    }

    /**
     * @param 'pgsql'|'pdo' $driver
     */
    #[DataProvider('driverProvider')]
    public function testLikePatternsMatchLiterally(string $driver): void
    {
        $connection = $this->connect($driver);
        $this->execute($connection, 'CREATE TEMPORARY TABLE xcapher_like (v TEXT)');
        $this->execute($connection, "INSERT INTO xcapher_like VALUES ('50%_off'), ('50 percent off'), ('500_off'), ('a\\b'), ('ab')");

        $match = fn(string $search): array => $this->column($connection, \sprintf(
            'SELECT v FROM xcapher_like WHERE v LIKE %s ORDER BY v',
            x(x($search)->like() . '%')->quote($connection),
        ));

        self::assertSame(['50%_off'], $match('50%_'));
        self::assertSame(['a\b'], $match('a\b'));
        self::assertSame(['500_off'], $match('500_'));
    }

    /**
     * @param 'pgsql'|'pdo' $driver
     */
    private function connect(string $driver): \PgSql\Connection|\PDO
    {
        $host = getenv('XCAPHER_PGSQL_HOST');

        if (!\is_string($host) || $host === '') {
            self::markTestSkipped('Set XCAPHER_PGSQL_HOST to run the PostgreSQL tests.');
        }

        $port = (int) LiveValues::env('XCAPHER_PGSQL_PORT', '5432');
        $user = LiveValues::env('XCAPHER_PGSQL_USER', 'postgres');
        $password = LiveValues::env('XCAPHER_PGSQL_PASSWORD', '');
        $database = LiveValues::env('XCAPHER_PGSQL_DATABASE', 'postgres');

        if (!\extension_loaded($driver === 'pdo' ? 'pdo_pgsql' : 'pgsql')) {
            self::markTestSkipped(\sprintf('The %s extension is not installed.', $driver === 'pdo' ? 'pdo_pgsql' : 'pgsql'));
        }

        if ($driver === 'pdo') {
            try {
                return new \PDO(
                    \sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $database),
                    $user,
                    $password,
                    [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_STRINGIFY_FETCHES => true],
                );
            } catch (\Throwable $e) {
                self::markTestSkipped('Could not connect to PostgreSQL: ' . $e->getMessage());
            }
        }

        $connection = @pg_connect(\sprintf(
            "host='%s' port=%d dbname='%s' user='%s' password='%s'",
            addcslashes($host, "'\\"),
            $port,
            addcslashes($database, "'\\"),
            addcslashes($user, "'\\"),
            addcslashes($password, "'\\"),
        ));

        if ($connection === false) {
            self::markTestSkipped('Could not connect to PostgreSQL.');
        }

        return $connection;
    }

    private function execute(\PgSql\Connection|\PDO $connection, string $sql): void
    {
        if ($connection instanceof \PDO) {
            $connection->exec($sql);

            return;
        }

        self::assertNotFalse(pg_query($connection, $sql), pg_last_error($connection));
    }

    /**
     * @return list<?string>
     */
    private function row(\PgSql\Connection|\PDO $connection, string $sql): array
    {
        return $this->rows($connection, $sql)[0] ?? [];
    }

    /**
     * @return list<?string>
     */
    private function column(\PgSql\Connection|\PDO $connection, string $sql): array
    {
        return array_map(static fn(array $row): ?string => $row[0] ?? null, $this->rows($connection, $sql));
    }

    /**
     * @return list<list<?string>>
     */
    private function rows(\PgSql\Connection|\PDO $connection, string $sql): array
    {
        if ($connection instanceof \PDO) {
            $statement = $connection->query($sql);
            self::assertNotFalse($statement);
            $rows = $statement->fetchAll(\PDO::FETCH_NUM);
        } else {
            $result = pg_query($connection, $sql);
            self::assertNotFalse($result, pg_last_error($connection));
            $rows = pg_fetch_all($result, \PGSQL_NUM);
        }

        return LiveValues::stringRows($rows);
    }
}
