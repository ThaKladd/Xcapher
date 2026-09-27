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
 * Runs the SQL helpers against a real MySQL or MariaDB server through both mysqli and PDO.
 *
 * Skipped unless XCAPHER_MYSQL_HOST is set (DDEV and CI set it). Optional: XCAPHER_MYSQL_PORT,
 * XCAPHER_MYSQL_USER, XCAPHER_MYSQL_PASSWORD, XCAPHER_MYSQL_DATABASE.
 */
#[CoversClass(Xcapher::class)]
#[CoversClass(Database::class)]
#[Group('database')]
final class MySqlTest extends TestCase
{
    /**
     * @return iterable<string, array{'mysqli'|'pdo'}>
     */
    public static function driverProvider(): iterable
    {
        yield 'mysqli' => ['mysqli'];
        yield 'PDO' => ['pdo'];
    }

    /**
     * @param 'mysqli'|'pdo' $driver
     */
    #[DataProvider('driverProvider')]
    public function testDetectsTheDialect(string $driver): void
    {
        self::assertSame(Database::MySql, Database::detect($this->connect($driver)));
    }

    /**
     * @param 'mysqli'|'pdo' $driver
     */
    #[DataProvider('driverProvider')]
    public function testQuotedAndEscapedStringsRoundTrip(string $driver): void
    {
        $connection = $this->connect($driver);
        $table = x('xcapher `test`')->identifier($connection);
        $column = x('the "value"')->identifier($connection);

        $this->execute($connection, \sprintf('CREATE TEMPORARY TABLE %s (id INT AUTO_INCREMENT PRIMARY KEY, %s LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin)', $table, $column));

        $values = LiveValues::strings(allowNul: true);

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
     * @param 'mysqli'|'pdo' $driver
     */
    #[DataProvider('driverProvider')]
    public function testQuotedScalarsAreReadBackUnchanged(string $driver): void
    {
        $connection = $this->connect($driver);
        $sql = \sprintf(
            'SELECT %s IS NULL, %s, %s, %s, %s',
            x(null)->quote($connection),
            x(true)->quote($connection),
            x(false)->quote($connection),
            x(\PHP_INT_MIN)->quote($connection),
            x(1.5)->quote($connection),
        );

        self::assertSame(['1', '1', '0', (string) \PHP_INT_MIN, '1.5'], $this->row($connection, $sql));
    }

    /**
     * @param 'mysqli'|'pdo' $driver
     */
    #[DataProvider('driverProvider')]
    public function testLikePatternsMatchLiterally(string $driver): void
    {
        $connection = $this->connect($driver);
        $this->execute($connection, 'CREATE TEMPORARY TABLE xcapher_like (v VARCHAR(50))');
        $this->execute($connection, "INSERT INTO xcapher_like VALUES ('50%_off'), ('50 percent off'), ('500_off'), ('a\\\\b'), ('ab')");

        $match = fn(string $search): array => $this->column($connection, \sprintf(
            'SELECT v FROM xcapher_like WHERE v LIKE %s ORDER BY v',
            x(x($search)->like() . '%')->quote($connection),
        ));

        self::assertSame(['50%_off'], $match('50%_'));
        self::assertSame(['a\b'], $match('a\b'));
        self::assertSame(['500_off'], $match('500_'));
    }

    /**
     * @param 'mysqli'|'pdo' $driver
     */
    private function connect(string $driver): \mysqli|\PDO
    {
        $host = getenv('XCAPHER_MYSQL_HOST');

        if (!\is_string($host) || $host === '') {
            self::markTestSkipped('Set XCAPHER_MYSQL_HOST to run the MySQL/MariaDB tests.');
        }

        $port = (int) LiveValues::env('XCAPHER_MYSQL_PORT', '3306');
        $user = LiveValues::env('XCAPHER_MYSQL_USER', 'root');
        $password = LiveValues::env('XCAPHER_MYSQL_PASSWORD', '');
        $database = LiveValues::env('XCAPHER_MYSQL_DATABASE', 'test');

        if (!\extension_loaded($driver === 'pdo' ? 'pdo_mysql' : 'mysqli')) {
            self::markTestSkipped(\sprintf('The %s extension is not installed.', $driver === 'pdo' ? 'pdo_mysql' : 'mysqli'));
        }

        try {
            if ($driver === 'pdo') {
                return new \PDO(
                    \sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database),
                    $user,
                    $password,
                    [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_STRINGIFY_FETCHES => true],
                );
            }

            $mysqli = new \mysqli($host, $user, $password, $database, $port);
            $mysqli->set_charset('utf8mb4');

            return $mysqli;
        } catch (\Throwable $e) {
            self::markTestSkipped('Could not connect to MySQL/MariaDB: ' . $e->getMessage());
        }
    }

    private function execute(\mysqli|\PDO $connection, string $sql): void
    {
        if ($connection instanceof \PDO) {
            $connection->exec($sql);

            return;
        }

        $connection->query($sql);
    }

    /**
     * @return list<?string>
     */
    private function row(\mysqli|\PDO $connection, string $sql): array
    {
        return $this->rows($connection, $sql)[0] ?? [];
    }

    /**
     * @return list<?string>
     */
    private function column(\mysqli|\PDO $connection, string $sql): array
    {
        return array_map(static fn(array $row): ?string => $row[0] ?? null, $this->rows($connection, $sql));
    }

    /**
     * @return list<list<?string>>
     */
    private function rows(\mysqli|\PDO $connection, string $sql): array
    {
        if ($connection instanceof \PDO) {
            $statement = $connection->query($sql);
            self::assertNotFalse($statement);
            $rows = $statement->fetchAll(\PDO::FETCH_NUM);
        } else {
            $result = $connection->query($sql);
            self::assertInstanceOf(\mysqli_result::class, $result);
            $rows = $result->fetch_all(\MYSQLI_NUM);
        }

        return LiveValues::stringRows($rows);
    }
}
