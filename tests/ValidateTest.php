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
use Xcapher\Tests\Fixtures\Text;
use Xcapher\Type;
use Xcapher\Xcapher;

use function Xcapher\x;

#[CoversClass(Xcapher::class)]
#[CoversClass(Type::class)]
#[UsesClass(CastException::class)]
final class ValidateTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, Type}>
     */
    public static function typeProvider(): iterable
    {
        yield 'null' => [null, Type::Null];
        yield 'bool' => [false, Type::Bool];
        yield 'int' => [0, Type::Int];
        yield 'float' => [0.0, Type::Float];
        yield 'string' => ['', Type::String];
        yield 'array' => [[], Type::Array];
        yield 'object' => [new \stdClass(), Type::Object];
        yield 'closure' => [static fn(): null => null, Type::Object];
        yield 'resource' => [fopen('php://memory', 'r'), Type::Resource];
    }

    #[DataProvider('typeProvider')]
    public function testType(mixed $value, Type $expected): void
    {
        self::assertSame($expected, x($value)->type());
        self::assertTrue(x($value)->is($expected));
        self::assertTrue(x($value)->is(Type::Resource, $expected));
    }

    public function testClosedResourceIsStillAResource(): void
    {
        $resource = fopen('php://memory', 'r');
        self::assertIsResource($resource);
        fclose($resource);

        self::assertSame(Type::Resource, x($resource)->type());
        self::assertTrue(x($resource)->isResource());
    }

    public function testIsWithoutTypesIsFalse(): void
    {
        self::assertFalse(x('a')->is());
        self::assertFalse(x('a')->is(Type::Int, Type::Float));
    }

    public function testTypeIsScalar(): void
    {
        self::assertTrue(Type::String->isScalar());
        self::assertFalse(Type::Null->isScalar());
        self::assertFalse(Type::Array->isScalar());
    }

    public function testNativeTypeChecks(): void
    {
        self::assertTrue(x(null)->isNull());
        self::assertTrue(x(true)->isBool());
        self::assertTrue(x(1)->isInt());
        self::assertFalse(x('1')->isInt());
        self::assertTrue(x(1.0)->isFloat());
        self::assertTrue(x('')->isString());
        self::assertTrue(x([])->isArray());
        self::assertTrue(x([1, 2])->isList());
        self::assertFalse(x([1 => 1])->isList());
        self::assertFalse(x('a')->isList());
        self::assertTrue(x(new \stdClass())->isObject());
        self::assertTrue(x('a')->isScalar());
        self::assertFalse(x(null)->isScalar());
        self::assertTrue(x('strlen')->isCallable());
        self::assertTrue(x(new \ArrayIterator())->isIterable());
        self::assertTrue(x([])->isCountable());
        self::assertFalse(x('abc')->isCountable());
        self::assertTrue(x(new Text('a'))->isStringable());
        self::assertTrue(x(1)->isStringable());
        self::assertFalse(x(new BrokenText())->isStringable());
        self::assertFalse(x([])->isStringable());
    }

    /**
     * @return iterable<string, array{\Closure(Xcapher): bool, mixed, bool}>
     */
    public static function validationProvider(): iterable
    {
        $cases = [
            'isNumeric' => [static fn(Xcapher $x): bool => $x->isNumeric(), [[42, true], [1.5, true], ['1e3', true], [' 42 ', true], ['12test', false], ['', false], [null, false]]],
            'isInteger' => [static fn(Xcapher $x): bool => $x->isInteger(), [[42, true], ['42', true], ['-7', true], ['+3', true], ['007', true], ['1.0', false], [1.0, false], ['1e3', false], [' 1', false], ['99999999999999999999', false], ['', false]]],
            'isEmpty' => [static fn(Xcapher $x): bool => $x->isEmpty(), [[null, true], ['', true], [[], true], [new EmptyBag(), true], [0, false], ['0', false], [false, false], [' ', false]]],
            'isBlank' => [static fn(Xcapher $x): bool => $x->isBlank(), [[" \n\t", true], ['', true], [null, true], ['a', false], [0, false]]],
            'isEmail' => [static fn(Xcapher $x): bool => $x->isEmail(), [['john@example.com', true], ['bjørn@eksempel.no', true], ['john@', false], ['john.example.com', false], ['', false], [5, false]]],
            'isUrl' => [static fn(Xcapher $x): bool => $x->isUrl(), [['https://example.com', true], ['http://localhost:8080/a?b=c#d', true], ['https://bücher.de', true], ['ftp://example.com', false], ['javascript:alert(1)', false], ['http:example.com', false], ['https://exa mple.com', false], [' https://example.com', false], ['example.com', false], ['', false], [null, false]]],
            'isIp' => [static fn(Xcapher $x): bool => $x->isIp(), [['127.0.0.1', true], ['::1', true], ['256.0.0.1', false], ['abc', false]]],
            'isIpv4' => [static fn(Xcapher $x): bool => $x->isIpv4(), [['192.168.0.1', true], ['::1', false]]],
            'isIpv6' => [static fn(Xcapher $x): bool => $x->isIpv6(), [['2001:db8::1', true], ['127.0.0.1', false]]],
            'isPublicIp' => [static fn(Xcapher $x): bool => $x->isPublicIp(), [['8.8.8.8', true], ['192.168.0.1', false], ['127.0.0.1', false]]],
            'isMac' => [static fn(Xcapher $x): bool => $x->isMac(), [['00:1A:2B:3C:4D:5E', true], ['00:1A:2B', false]]],
            'isDomain' => [static fn(Xcapher $x): bool => $x->isDomain(), [['example.com', true], ['localhost', true], ['sub.example.co.uk', true], ['-bad.com', false], ['exa mple.com', false], ['https://example.com', false]]],
            'isUuid' => [static fn(Xcapher $x): bool => $x->isUuid(), [['550e8400-e29b-41d4-a716-446655440000', true], ['550E8400-E29B-41D4-A716-446655440000', true], ["550e8400-e29b-41d4-a716-446655440000\n", false], ['550e8400e29b41d4a716446655440000', false]]],
            'isJson' => [static fn(Xcapher $x): bool => $x->isJson(), [['{"a":1}', true], ['[]', true], ['"x"', true], ['1', true], [1, true], ['{a:1}', false], ['', false], [[], false]]],
            'isBase64' => [static fn(Xcapher $x): bool => $x->isBase64(), [['aGk=', true], ['', true], ['aGk', false], ['a$==', false]]],
            'isAlpha' => [static fn(Xcapher $x): bool => $x->isAlpha(), [['Blåbær', true], ['abc1', false], ['', false]]],
            'isAlnum' => [static fn(Xcapher $x): bool => $x->isAlnum(), [['Blåbær2', true], ['a b', false]]],
            'isDigits' => [static fn(Xcapher $x): bool => $x->isDigits(), [['0123', true], [123, true], [-1, false], ['12a', false], ['', false], ["12\n", false]]],
            'isHex' => [static fn(Xcapher $x): bool => $x->isHex(), [['deadBEEF', true], ['xyz', false]]],
            'isUtf8' => [static fn(Xcapher $x): bool => $x->isUtf8(), [['blåbær', true], ["\xFF", false], [null, false]]],
        ];

        foreach ($cases as $method => [$validate, $values]) {
            foreach ($values as $index => [$value, $expected]) {
                yield $method . ' #' . $index => [$validate, $value, $expected];
            }
        }
    }

    /**
     * @param \Closure(Xcapher): bool $validate
     */
    #[DataProvider('validationProvider')]
    public function testValidation(\Closure $validate, mixed $value, bool $expected): void
    {
        self::assertSame($expected, $validate(x($value)));
    }

    public function testValidatorsAcceptStringableObjects(): void
    {
        self::assertTrue(x(new Text('john@example.com'))->isEmail());
        self::assertFalse(x(new BrokenText())->isEmail());
    }

    public function testIsUrlWithCustomSchemes(): void
    {
        self::assertTrue(x('ftp://example.com/file')->isUrl(['ftp']));
        self::assertTrue(x('mailto:john@example.com')->isUrl([]));
        self::assertTrue(x('HTTPS://EXAMPLE.COM')->isUrl(['HTTPS']));
        self::assertFalse(x('https://example.com')->isUrl(['ftp']));
    }

    public function testIsDate(): void
    {
        self::assertTrue(x('2024-02-29')->isDate());
        self::assertFalse(x('2023-02-29')->isDate());
        self::assertFalse(x('2024-2-1')->isDate());
        self::assertTrue(x('29.02.2024 13:45')->isDate('d.m.Y H:i'));
        self::assertTrue(x(new \DateTimeImmutable())->isDate());
        self::assertFalse(x('')->isDate());
        self::assertFalse(x(null)->isDate());
        self::assertFalse(x("2024-01-01\0")->isDate());
    }

    public function testMatches(): void
    {
        self::assertTrue(x('abc')->matches('/^a/'));
        self::assertTrue(x(123)->matches('/^\d+$/'));
        self::assertFalse(x('abc')->matches('/^b/'));
        self::assertFalse(x([])->matches('/.*/'));
        self::assertFalse(x('abc')->matches('/(unclosed/'));
        self::assertFalse(x('abc')->matches('not a pattern'));
    }

    public function testIsIntegerRejectsTrailingCharacters(): void
    {
        self::assertFalse(x('12abc')->isInteger());
        self::assertFalse(x("12\n")->isInteger());
        self::assertFalse(x('abc12')->isInteger());
    }

    public function testIsBase64RejectsATrailingNewline(): void
    {
        self::assertFalse(x("aGk=\n")->isBase64());
    }

    public function testEveryScalarTypeIsScalar(): void
    {
        $scalars = array_filter(Type::cases(), static fn(Type $type): bool => $type->isScalar());

        self::assertSame([Type::Bool, Type::Int, Type::Float, Type::String], array_values($scalars));
    }
}
