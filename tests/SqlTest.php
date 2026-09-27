<?php

declare(strict_types=1);

namespace Xcapher\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Xcapher\Database;
use Xcapher\Exception\CastException;
use Xcapher\Exception\EscapeException;
use Xcapher\Tests\Fixtures\Suit;
use Xcapher\Xcapher;

use function Xcapher\x;

#[CoversClass(Xcapher::class)]
#[CoversClass(Database::class)]
#[CoversClass(EscapeException::class)]
#[RequiresPhpExtension('pdo_sqlite')]
#[UsesClass(CastException::class)]
final class SqlTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE "we""ird" ("the value" TEXT)');
    }

    public function testEscapeWithPdo(): void
    {
        self::assertSame("O''Reilly", x("O'Reilly")->escape($this->pdo));
        self::assertSame('42', x(42)->escape($this->pdo));
        self::assertSame('H', x(Suit::Hearts)->escape($this->pdo));
        self::assertSame('', x(null)->escape($this->pdo));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function quoteProvider(): iterable
    {
        yield 'string' => ["O'Reilly", "'O''Reilly'"];
        yield 'null' => [null, 'NULL'];
        yield 'true' => [true, '1'];
        yield 'false' => [false, '0'];
        yield 'int' => [-5, '-5'];
        yield 'float' => [1.5, '1.5'];
        yield 'stringable enum' => [Suit::Hearts, "'H'"];
    }

    #[DataProvider('quoteProvider')]
    public function testQuoteWithPdo(mixed $value, string $expected): void
    {
        self::assertSame($expected, x($value)->quote($this->pdo));
    }

    public function testQuotedValuesRoundTripThroughTheDatabase(): void
    {
        $nasty = "'; DROP TABLE \"we\"\"ird\"; -- \\ \u{1F600} \" %_";
        $table = x('we"ird')->identifier($this->pdo);
        $column = x('the value')->identifier($this->pdo);

        $this->pdo->exec(\sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, $column, x($nasty)->quote($this->pdo)));
        $this->pdo->exec(\sprintf("INSERT INTO %s (%s) VALUES ('%s')", $table, $column, x($nasty)->escape($this->pdo)));

        $statement = $this->pdo->query(\sprintf('SELECT %s FROM %s', $column, $table));
        self::assertNotFalse($statement);
        self::assertSame([$nasty, $nasty], $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testLikeMatchesLiterally(): void
    {
        $this->pdo->exec("INSERT INTO \"we\"\"ird\" VALUES ('50%_off'), ('50 percent off'), ('a\\b')");
        $query = $this->pdo->prepare("SELECT \"the value\" FROM \"we\"\"ird\" WHERE \"the value\" LIKE ? ESCAPE '\\'");
        self::assertNotFalse($query);

        $query->execute([x('50%_')->like() . '%']);
        self::assertSame(['50%_off'], $query->fetchAll(\PDO::FETCH_COLUMN));

        $query->execute([x('a\b')->like()]);
        self::assertSame(['a\b'], $query->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testLike(): void
    {
        self::assertSame('100\%\_\\\\', x('100%_\\')->like());
        self::assertSame('a!%b!!', x('a%b!')->like('!'));
    }

    public function testLikeRejectsLongEscapeCharacter(): void
    {
        $this->expectException(EscapeException::class);
        x('a')->like('!!');
    }

    public function testQuoteForPostgreSqlBooleans(): void
    {
        self::assertSame('TRUE', x(true)->quote(Database::PostgreSql));
        self::assertSame('FALSE', x(false)->quote(Database::PostgreSql));
        self::assertSame('1', x(true)->quote(Database::MySql));
        self::assertSame('NULL', x(null)->quote(Database::Sqlite));
        self::assertSame('7', x(7)->quote(Database::MySql));
    }

    public function testQuotingAStringNeedsALiveConnection(): void
    {
        $this->expectException(EscapeException::class);
        x('text')->quote(Database::MySql);
    }

    public function testQuoteRejectsNonFiniteFloats(): void
    {
        $this->expectException(EscapeException::class);
        x(\INF)->quote($this->pdo);
    }

    public function testQuoteRejectsArrays(): void
    {
        $this->expectException(CastException::class);
        x([1])->quote($this->pdo);
    }

    public function testEscapeRejectsArrays(): void
    {
        $this->expectException(CastException::class);
        x([1])->escape($this->pdo);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidConnectionProvider(): iterable
    {
        yield 'null' => [null];
        yield 'string' => ['mysql'];
        yield 'object' => [new \stdClass()];
    }

    #[DataProvider('invalidConnectionProvider')]
    public function testEscapeRejectsUnsupportedConnections(mixed $connection): void
    {
        $this->expectException(EscapeException::class);
        x('a')->escape($connection);
    }

    #[DataProvider('invalidConnectionProvider')]
    public function testQuoteRejectsUnsupportedConnections(mixed $connection): void
    {
        $this->expectException(EscapeException::class);
        x('a')->quote($connection);
    }

    public function testEscapeRejectsADatabaseDialect(): void
    {
        $this->expectException(EscapeException::class);
        x('a')->escape(Database::MySql);
    }

    public function testIdentifier(): void
    {
        self::assertSame('`user`', x('user')->identifier(Database::MySql));
        self::assertSame('`a``b`', x('a`b')->identifier(Database::MySql));
        self::assertSame('"a""b"', x('a"b')->identifier(Database::PostgreSql));
        self::assertSame('"a.b"', x('a.b')->identifier(Database::Sqlite));
        self::assertSame('"public"."users"', x('public.users')->identifier(Database::PostgreSql, qualified: true));
        self::assertSame('"t"', x('t')->identifier($this->pdo));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function invalidIdentifierProvider(): iterable
    {
        yield 'empty' => ['', false];
        yield 'NUL byte' => ["a\0b", false];
        yield 'empty qualified part' => ['schema.', true];
    }

    #[DataProvider('invalidIdentifierProvider')]
    public function testIdentifierRejectsInvalidNames(string $name, bool $qualified): void
    {
        $this->expectException(EscapeException::class);
        x($name)->identifier(Database::MySql, $qualified);
    }

    public function testDatabaseDetection(): void
    {
        self::assertSame(Database::Sqlite, Database::detect($this->pdo));
        self::assertSame(Database::MySql, Database::detect(Database::MySql));
    }

    public function testDatabaseDetectionRejectsUnknownConnections(): void
    {
        $this->expectException(EscapeException::class);
        $this->expectExceptionMessage('Unsupported database connection of type stdClass');
        Database::detect(new \stdClass());
    }
}
