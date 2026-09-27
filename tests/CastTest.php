<?php

declare(strict_types=1);

namespace Xcapher\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Xcapher\Exception\CastException;
use Xcapher\Tests\Fixtures\BrokenText;
use Xcapher\Tests\Fixtures\EmptyBag;
use Xcapher\Tests\Fixtures\Level;
use Xcapher\Tests\Fixtures\Point;
use Xcapher\Tests\Fixtures\Pure;
use Xcapher\Tests\Fixtures\Suit;
use Xcapher\Tests\Fixtures\Text;
use Xcapher\Type;
use Xcapher\Xcapher;

use function Xcapher\x;

#[CoversClass(Xcapher::class)]
#[CoversClass(CastException::class)]
#[UsesClass(Type::class)]
final class CastTest extends TestCase
{
    public function testHelpersAndConstructorsWrapTheValue(): void
    {
        self::assertSame('a', x('a')->value());
        self::assertSame('a', x('a')->value());
        self::assertSame('a', Xcapher::from('a')->value());
        self::assertSame('a', new Xcapher('a')->value());
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function stringProvider(): iterable
    {
        yield 'string' => ['abc', 'abc'];
        yield 'empty string' => ['', ''];
        yield 'int' => [42, '42'];
        yield 'negative int' => [-7, '-7'];
        yield 'float' => [1.5, '1.5'];
        yield 'whole float' => [2.0, '2'];
        yield 'infinite float' => [\INF, 'INF'];
        yield 'true' => [true, '1'];
        yield 'false' => [false, ''];
        yield 'null' => [null, ''];
        yield 'stringable' => [new Text('hi'), 'hi'];
        yield 'backed enum' => [Suit::Hearts, 'H'];
        yield 'int backed enum' => [Level::High, '10'];
        yield 'pure enum' => [Pure::Alpha, 'Alpha'];
        yield 'date' => [new \DateTimeImmutable('2024-01-02 03:04:05', new \DateTimeZone('UTC')), '2024-01-02T03:04:05+00:00'];
    }

    #[DataProvider('stringProvider')]
    public function testString(mixed $value, string $expected): void
    {
        self::assertSame($expected, x($value)->string());
        self::assertSame($expected, x($value)->tryString());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unstringableProvider(): iterable
    {
        yield 'array' => [[1, 2]];
        yield 'plain object' => [new \stdClass()];
        yield 'closure' => [static fn(): int => 1];
        yield 'broken stringable' => [new BrokenText()];
        yield 'resource' => [fopen('php://memory', 'r')];
    }

    #[DataProvider('unstringableProvider')]
    public function testStringFailsForValuesWithoutAStringForm(mixed $value): void
    {
        self::assertNull(x($value)->tryString());
        self::assertSame('fallback', x($value)->string('fallback'));

        $this->expectException(CastException::class);
        x($value)->string();
    }

    /**
     * @return iterable<string, array{mixed, int}>
     */
    public static function intProvider(): iterable
    {
        yield 'int' => [5, 5];
        yield 'numeric string' => ['42', 42];
        yield 'numeric prefix' => ['12test', 12];
        yield 'padded' => ['  42  ', 42];
        yield 'signed' => ['-17', -17];
        yield 'decimal string' => ['3.99', 3];
        yield 'exponent' => ['1e3', 1000];
        yield 'leading dot' => ['.5', 0];
        yield 'non numeric' => ['abc', 0];
        yield 'hex is not parsed' => ['0x1A', 0];
        yield 'empty string' => ['', 0];
        yield 'float truncates' => [9.99, 9];
        yield 'negative float truncates' => [-9.99, -9];
        yield 'huge float clamps' => [1e30, \PHP_INT_MAX];
        yield 'huge negative float clamps' => [-1e30, \PHP_INT_MIN];
        yield 'infinity clamps' => [\INF, \PHP_INT_MAX];
        yield 'huge numeric string clamps' => ['99999999999999999999', \PHP_INT_MAX];
        yield 'overflowing exponent clamps' => ['1e999', \PHP_INT_MAX];
        yield 'true' => [true, 1];
        yield 'false' => [false, 0];
        yield 'null' => [null, 0];
        yield 'empty array' => [[], 0];
        yield 'non-empty array' => [[1, 2], 1];
        yield 'int backed enum' => [Level::High, 10];
        yield 'numeric string enum' => [Suit::Numeric, 42];
        yield 'stringable' => [new Text('7 apples'), 7];
        yield 'date' => [new \DateTimeImmutable('@1700000000'), 1700000000];
    }

    #[DataProvider('intProvider')]
    public function testInt(mixed $value, int $expected): void
    {
        self::assertSame($expected, x($value)->int());
        self::assertSame($expected, x($value)->tryInt());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function nonIntProvider(): iterable
    {
        yield 'NaN' => [\NAN];
        yield 'plain object' => [new \stdClass()];
        yield 'pure enum' => [Pure::Alpha];
        yield 'broken stringable' => [new BrokenText()];
        yield 'resource' => [fopen('php://memory', 'r')];
    }

    #[DataProvider('nonIntProvider')]
    public function testIntFailsWithoutANumericForm(mixed $value): void
    {
        self::assertNull(x($value)->tryInt());
        self::assertSame(-1, x($value)->int(-1));

        $this->expectException(CastException::class);
        x($value)->int();
    }

    public function testNanFloatIsKept(): void
    {
        self::assertNan(x(\NAN)->float());
        self::assertSame('NAN', x(\NAN)->string());
    }

    public function testFloatFailsWithoutANumericForm(): void
    {
        self::assertNull(x(new \stdClass())->tryFloat());
        self::assertSame(-1.5, x(Pure::Alpha)->float(-1.5));

        $this->expectException(CastException::class);
        x(new BrokenText())->float();
    }

    /**
     * @return iterable<string, array{mixed, float}>
     */
    public static function floatProvider(): iterable
    {
        yield 'float' => [1.25, 1.25];
        yield 'int' => [3, 3.0];
        yield 'decimal string' => ['3.99', 3.99];
        yield 'comma is not a decimal separator' => ['3,99', 3.0];
        yield 'numeric prefix' => ['2.5kg', 2.5];
        yield 'exponent' => ['1.5e2', 150.0];
        yield 'null' => [null, 0.0];
        yield 'true' => [true, 1.0];
        yield 'date keeps microseconds' => [new \DateTimeImmutable('@1700000000.25'), 1700000000.25];
    }

    #[DataProvider('floatProvider')]
    public function testFloat(mixed $value, float $expected): void
    {
        self::assertSame($expected, x($value)->float());
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function boolProvider(): iterable
    {
        foreach (['1', 'true', 'TRUE', 'on', 'yes', ' Yes ', 'abc', 'x'] as $true) {
            yield 'string ' . $true => [$true, true];
        }

        foreach (['0', 'false', 'False', 'off', 'no', ' NO ', '', '   '] as $false) {
            yield 'string "' . $false . '"' => [$false, false];
        }

        yield 'true' => [true, true];
        yield 'false' => [false, false];
        yield 'null' => [null, false];
        yield 'zero' => [0, false];
        yield 'int' => [-3, true];
        yield 'float zero' => [0.0, false];
        yield 'NaN' => [\NAN, true];
        yield 'empty array' => [[], false];
        yield 'array' => [[0], true];
        yield 'object' => [new \stdClass(), true];
        yield 'stringable false' => [new Text('off'), false];
        yield 'broken stringable' => [new BrokenText(), true];
        yield 'string enum' => [Suit::False, false];
        yield 'int enum zero' => [Level::Off, false];
        yield 'int enum' => [Level::High, true];
        yield 'pure enum' => [Pure::Alpha, true];
    }

    #[DataProvider('boolProvider')]
    public function testBool(mixed $value, bool $expected): void
    {
        self::assertSame($expected, x($value)->bool());
    }

    public function testArray(): void
    {
        $date = new \DateTimeImmutable();
        $closure = static fn(): null => null;

        self::assertSame([], x(null)->array());
        self::assertSame(['a' => 1], x(['a' => 1])->array());
        self::assertSame(['a'], x('a')->array());
        self::assertSame([0], x(0)->array());
        self::assertSame([false], x(false)->array());
        self::assertSame(['x' => 1, 'y' => 2], x(new Point())->array());
        self::assertSame(['a' => 1], x((object) ['a' => 1])->array());
        self::assertSame(['k' => 'v'], x(new \ArrayIterator(['k' => 'v']))->array());
        self::assertSame([], x(new EmptyBag())->array());
        self::assertSame([Pure::Alpha], x(Pure::Alpha)->array());
        self::assertSame([$date], x($date)->array());
        self::assertSame([$closure], x($closure)->array());
    }

    public function testArrayFromGenerator(): void
    {
        $generator = (static function (): \Generator {
            yield 'a' => 1;
            yield 'b' => 2;
        })();

        self::assertSame(['a' => 1, 'b' => 2], x($generator)->array());
    }

    public function testArrayFailsWhenIterationFails(): void
    {
        $failing = (static function (): \Generator {
            yield 1;

            throw new \RuntimeException('Iteration failed.');
        })();

        self::assertNull(x($failing)->tryArray());

        $invalidKeys = (static function (): \Generator {
            yield [] => 1;
        })();

        self::assertSame(['fallback'], x($invalidKeys)->array(['fallback']));
    }

    public function testList(): void
    {
        self::assertSame([1, 2], x(['a' => 1, 'b' => 2])->list());
        self::assertSame(['a'], x('a')->list());
    }

    public function testObject(): void
    {
        $object = new \stdClass();

        self::assertSame($object, x($object)->object());
        self::assertEquals(new \stdClass(), x(null)->object());
        self::assertEquals((object) ['a' => 1], x(['a' => 1])->object());
        self::assertEquals((object) ['scalar' => 'a'], x('a')->object());
        self::assertEquals((object) ['scalar' => 5], x(5)->object());

        $result = x([1, 2])->object();
        self::assertEquals((object) [0 => 1, 1 => 2], $result);
    }

    public function testMap(): void
    {
        self::assertSame(['a' => 1, 'b' => 0], x(['a' => '1x', 'b' => 'y'])->map(static fn(Xcapher $item): int => $item->int()));
        self::assertSame([0 => '0:5'], x(5)->map(static fn(Xcapher $item, int|string $key): string => $key . ':' . $item->string()));
    }

    /**
     * @return iterable<string, array{Type, mixed, mixed}>
     */
    public static function toProvider(): iterable
    {
        yield 'string' => [Type::String, 12, '12'];
        yield 'int' => [Type::Int, '12test', 12];
        yield 'float' => [Type::Float, '1.5', 1.5];
        yield 'bool' => [Type::Bool, 'yes', true];
        yield 'array' => [Type::Array, 'a', ['a']];
        yield 'null' => [Type::Null, null, null];
    }

    #[DataProvider('toProvider')]
    public function testTo(Type $type, mixed $value, mixed $expected): void
    {
        self::assertSame($expected, x($value)->to($type));
    }

    public function testToObjectAndResource(): void
    {
        $resource = fopen('php://memory', 'r');

        self::assertEquals((object) ['a' => 1], x(['a' => 1])->to(Type::Object));
        self::assertSame($resource, x($resource)->to(Type::Resource));
    }

    public function testToResourceAcceptsClosedResourcesLikeType(): void
    {
        $resource = fopen('php://memory', 'r');
        self::assertIsResource($resource);
        fclose($resource);

        self::assertSame(Type::Resource, x($resource)->type());
        self::assertSame($resource, x($resource)->to(Type::Resource));
    }

    public function testToNullFailsForOtherValues(): void
    {
        $this->expectException(CastException::class);
        x('')->to(Type::Null);
    }

    public function testToResourceFailsForOtherValues(): void
    {
        $this->expectException(CastException::class);
        x('file')->to(Type::Resource);
    }

    public function testCastExceptionMessageNamesTheTypes(): void
    {
        $this->expectExceptionMessage('Cannot convert value of type array to string.');
        x([])->string();
    }

    public function testIntClampsExactlyAtTheBoundary(): void
    {
        self::assertSame(\PHP_INT_MAX, x(9.2233720368547758E+18)->int());
        self::assertSame(\PHP_INT_MIN, x(-9.2233720368547758E+18)->int());
        self::assertSame(9007199254740992, x(9007199254740992.0)->int());
    }
}
