<?php

declare(strict_types=1);

namespace Xcapher\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Xcapher\Exception\CastException;
use Xcapher\Tests\Fixtures\EmptyBag;
use Xcapher\Tests\Fixtures\Level;
use Xcapher\Tests\Fixtures\Point;
use Xcapher\Tests\Fixtures\Pure;
use Xcapher\Tests\Fixtures\Suit;
use Xcapher\Tests\Fixtures\Text;
use Xcapher\Type;
use Xcapher\Xcapher;

use function Xcapher\x;

/**
 * Enums, class checks, dates, allowlists, callables and resources.
 */
#[CoversClass(Xcapher::class)]
#[CoversClass(CastException::class)]
#[UsesClass(Type::class)]
final class ObjectTest extends TestCase
{
    // ------------------------------------------------------------------
    // Enums
    // ------------------------------------------------------------------

    /**
     * @return iterable<string, array{mixed, class-string<\UnitEnum>, \UnitEnum}>
     */
    public static function enumProvider(): iterable
    {
        yield 'string-backed by value' => ['H', Suit::class, Suit::Hearts];
        yield 'string-backed numeric value from int' => [42, Suit::class, Suit::Numeric];
        yield 'string-backed numeric value from string' => ['42', Suit::class, Suit::Numeric];
        yield 'string-backed from Stringable' => [new Text('false'), Suit::class, Suit::False];
        yield 'int-backed from int' => [10, Level::class, Level::High];
        yield 'int-backed from string' => ['10', Level::class, Level::High];
        yield 'int-backed from padded string' => ['010', Level::class, Level::High];
        yield 'int-backed zero' => ['0', Level::class, Level::Off];
        yield 'pure by name' => ['Alpha', Pure::class, Pure::Alpha];
        yield 'case passes through' => [Level::High, Level::class, Level::High];
    }

    /**
     * @param class-string<\UnitEnum> $enum
     */
    #[DataProvider('enumProvider')]
    public function testEnum(mixed $value, string $enum, \UnitEnum $expected): void
    {
        self::assertSame($expected, x($value)->enum($enum));
        self::assertSame($expected, x($value)->tryEnum($enum));
        self::assertTrue(x($value)->isEnumValue($enum));
    }

    /**
     * @return iterable<string, array{mixed, class-string<\UnitEnum>}>
     */
    public static function noEnumCaseProvider(): iterable
    {
        yield 'unknown value' => ['X', Suit::class];
        yield 'backed enums do not match case names' => ['Hearts', Suit::class];
        yield 'pure enums are case-sensitive' => ['alpha', Pure::class];
        yield 'int-backed rejects decimals' => ['10.0', Level::class];
        yield 'int-backed rejects floats' => [10.0, Level::class];
        yield 'int-backed rejects prefixes' => ['10abc', Level::class];
        yield 'bools are not values' => [true, Level::class];
        yield 'null' => [null, Suit::class];
        yield 'array' => [['H'], Suit::class];
        yield 'case of another enum' => [Level::High, Suit::class];
    }

    /**
     * @param class-string<\UnitEnum> $enum
     */
    #[DataProvider('noEnumCaseProvider')]
    public function testEnumWithoutMatchingCase(mixed $value, string $enum): void
    {
        self::assertNull(x($value)->tryEnum($enum));
        self::assertFalse(x($value)->isEnumValue($enum));
        $default = $enum::cases()[0];
        self::assertSame($default, x($value)->enum($enum, $default));

        $this->expectException(CastException::class);
        $this->expectExceptionMessage('no matching case');
        x($value)->enum($enum);
    }

    public function testEnumDefault(): void
    {
        self::assertSame(Suit::Hearts, x('nope')->enum(Suit::class, Suit::Hearts));
    }

    public function testEnumRejectsClassesThatAreNotEnums(): void
    {
        self::assertFalse(x('H')->isEnumValue(\stdClass::class));
        self::assertFalse(x('H')->isEnumValue('No\Such\Enum'));

        $this->expectException(CastException::class);
        $this->expectExceptionMessage('stdClass is not an enum.');
        // Through reflection, because static analysis rightly forbids passing a non-enum class.
        new \ReflectionMethod(Xcapher::class, 'enum')->invoke(x('H'), \stdClass::class);
    }

    public function testIsEnum(): void
    {
        self::assertTrue(x(Suit::Hearts)->isEnum());
        self::assertTrue(x(Suit::Hearts)->isEnum(Suit::class));
        self::assertTrue(x(Suit::Hearts)->isEnum(\BackedEnum::class));
        self::assertFalse(x(Suit::Hearts)->isEnum(Level::class));
        self::assertFalse(x('H')->isEnum());
        self::assertFalse(x(new \stdClass())->isEnum(\stdClass::class));
    }

    // ------------------------------------------------------------------
    // Classes
    // ------------------------------------------------------------------

    public function testIsInstanceOf(): void
    {
        $bag = new EmptyBag();

        self::assertTrue(x($bag)->isInstanceOf(EmptyBag::class));
        self::assertTrue(x($bag)->isInstanceOf(\Countable::class));
        self::assertTrue(x($bag)->isInstanceOf(\Traversable::class));
        self::assertTrue(x($bag)->isInstanceOf(Point::class, \Countable::class));
        self::assertFalse(x($bag)->isInstanceOf(Point::class));
        self::assertFalse(x($bag)->isInstanceOf());
        self::assertFalse(x($bag)->isInstanceOf('No\Such\ClassName'));
        self::assertFalse(x(EmptyBag::class)->isInstanceOf(EmptyBag::class));
        self::assertTrue(x(new \DateTimeImmutable())->isInstanceOf(\DateTimeInterface::class));
    }

    public function testInstanceOf(): void
    {
        $bag = new EmptyBag();
        $point = new Point();

        self::assertSame($bag, x($bag)->instanceOf(\Countable::class));
        self::assertSame($bag, x($bag)->tryInstanceOf(EmptyBag::class));
        self::assertNull(x($point)->tryInstanceOf(\Countable::class));
        self::assertNull(x('EmptyBag')->tryInstanceOf(EmptyBag::class));
        self::assertSame($bag, x($point)->instanceOf(\Countable::class, $bag));
    }

    public function testInstanceOfFailsForOtherClasses(): void
    {
        $this->expectException(CastException::class);
        $this->expectExceptionMessage('Cannot convert value of type Xcapher\Tests\Fixtures\Point to Countable.');
        x(new Point())->instanceOf(\Countable::class);
    }

    public function testDebugType(): void
    {
        self::assertSame('int', x(1)->debugType());
        self::assertSame('null', x(null)->debugType());
        self::assertSame(Point::class, x(new Point())->debugType());
        self::assertSame(Suit::class, x(Suit::Hearts)->debugType());
        self::assertSame('resource (stream)', x(fopen('php://memory', 'r'))->debugType());
    }

    // ------------------------------------------------------------------
    // Dates
    // ------------------------------------------------------------------

    public function testDateFromDates(): void
    {
        $immutable = new \DateTimeImmutable('2024-05-06 07:08:09');
        $mutable = new \DateTime('2024-05-06 07:08:09');

        self::assertSame($immutable, x($immutable)->date());
        self::assertEquals($immutable, x($mutable)->date());
    }

    public function testDateFromTimestamps(): void
    {
        $date = x(1700000000)->date();

        self::assertSame(1700000000, $date->getTimestamp());
        self::assertSame(date_default_timezone_get(), $date->getTimezone()->getName());
        self::assertSame('1700000000.250000', x(1700000000.25)->date()->format('U.u'));
        self::assertSame(-1, x(-1)->date()->getTimestamp());
    }

    public function testDateFromStringsKeepsAnExplicitOffset(): void
    {
        self::assertSame(18000, x('2024-01-01T00:00:00+05:00')->date()->getOffset());
    }

    public function testDateFromStrings(): void
    {
        self::assertSame('2024-02-29 13:45:00', x('2024-02-29 13:45')->date()->format('Y-m-d H:i:s'));
        self::assertSame('2024-02-29 00:00:00', x('29.02.2024')->date('d.m.Y')->format('Y-m-d H:i:s'));
        self::assertSame('2024-01-02', x(new Text('2024-01-02'))->date()->format('Y-m-d'));
        self::assertEqualsWithDelta(time() + 86400, x('+1 day')->date()->getTimestamp(), 5);
    }

    /**
     * @return iterable<string, array{mixed, ?string}>
     */
    public static function invalidDateProvider(): iterable
    {
        yield 'impossible date' => ['2023-02-30', null];
        yield 'impossible date with format' => ['30.02.2023', 'd.m.Y'];
        yield 'wrong format' => ['2024-02-29', 'd.m.Y'];
        yield 'garbage' => ['not a date', null];
        yield 'empty string' => ['', null];
        yield 'whitespace' => ['   ', null];
        yield 'NUL byte' => ["2024-01-01\0", null];
        yield 'NUL byte in format' => ['2024-01-01', "Y-m-d\0"];
        yield 'timestamp as string' => ['1700000000', null];
        yield 'NaN' => [\NAN, null];
        yield 'infinity' => [\INF, null];
        yield 'bool' => [true, null];
        yield 'null' => [null, null];
        yield 'array' => [['2024-01-01'], null];
        yield 'object' => [new \stdClass(), null];
    }

    #[DataProvider('invalidDateProvider')]
    public function testInvalidDates(mixed $value, ?string $format): void
    {
        $fallback = new \DateTimeImmutable('2000-01-01');

        self::assertNull(x($value)->tryDate($format));
        self::assertSame($fallback, x($value)->date($format, $fallback));

        $this->expectException(CastException::class);
        x($value)->date($format);
    }

    // ------------------------------------------------------------------
    // Allowlists
    // ------------------------------------------------------------------

    public function testOneOfReturnsTheAllowedElement(): void
    {
        self::assertSame('desc', x('desc')->oneOf(['asc', 'desc']));
        self::assertSame(5, x('5')->oneOf([1, 5, 10]));
        self::assertSame('5', x(5)->oneOf(['1', '5']));
        self::assertSame(Suit::Hearts, x(Suit::Hearts)->oneOf(['H', Suit::Hearts]));
        self::assertNull(x(null)->oneOf([null, 'a']));
        self::assertTrue(x(null)->isOneOf([null]));
    }

    /**
     * @return iterable<string, array{mixed, array<mixed>}>
     */
    public static function notAllowedProvider(): iterable
    {
        yield 'different case' => ['DESC', ['asc', 'desc']];
        yield 'leading zero' => ['05', [5]];
        yield 'padded' => [' 5', [5]];
        yield 'float is not int' => [5.0, [5]];
        yield 'bool is not string' => [true, ['1']];
        yield 'null is not empty string' => [null, ['']];
        yield 'empty string is not null' => ['', [null]];
        yield 'string is not bool' => ['1', [true]];
        yield 'empty allowlist' => ['a', []];
        yield 'SQL injection' => ['id; DROP TABLE users', ['id', 'name']];
    }

    /**
     * @param array<mixed> $allowed
     */
    #[DataProvider('notAllowedProvider')]
    public function testOneOfRejectsEverythingElse(mixed $value, array $allowed): void
    {
        self::assertFalse(x($value)->isOneOf($allowed));
        self::assertNull(x($value)->tryOneOf($allowed));
        self::assertSame('fallback', x($value)->oneOf($allowed, 'fallback'));

        $this->expectException(CastException::class);
        x($value)->oneOf($allowed);
    }

    // ------------------------------------------------------------------
    // Callables
    // ------------------------------------------------------------------

    public function testClosure(): void
    {
        $closure = static fn(): int => 1;
        $invokable = new class {
            public function __invoke(): string
            {
                return 'invoked';
            }
        };

        self::assertSame($closure, x($closure)->closure());
        self::assertSame(3, x('strlen')->closure()('abc'));
        self::assertSame(2, x([new \ArrayObject([1, 2]), 'count'])->closure()());
        self::assertSame('invoked', x($invokable)->closure()());
        self::assertInstanceOf(\DateTimeImmutable::class, x('DateTimeImmutable::createFromFormat')->closure()('Y', '2024'));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function notCallableProvider(): iterable
    {
        yield 'unknown function' => ['no_such_function'];
        yield 'non-static method string' => ['DateTime::format'];
        yield 'language construct' => ['echo'];
        yield 'int' => [42];
        yield 'null' => [null];
        yield 'plain object' => [new \stdClass()];
        yield 'broken array' => [['a', 'b', 'c']];
    }

    #[DataProvider('notCallableProvider')]
    public function testClosureRejectsNonCallables(mixed $value): void
    {
        $fallback = static fn(): null => null;

        self::assertNull(x($value)->tryClosure());
        self::assertSame($fallback, x($value)->closure($fallback));

        $this->expectException(CastException::class);
        x($value)->closure();
    }

    // ------------------------------------------------------------------
    // Resources
    // ------------------------------------------------------------------

    public function testResources(): void
    {
        $stream = fopen('php://memory', 'r');
        self::assertIsResource($stream);

        self::assertSame('stream', x($stream)->resourceType());
        self::assertTrue(x($stream)->isStream());

        fclose($stream);

        self::assertSame('Unknown', x($stream)->resourceType());
        self::assertFalse(x($stream)->isStream());
        self::assertNull(x('stream')->resourceType());
        self::assertFalse(x('stream')->isStream());
    }
}
