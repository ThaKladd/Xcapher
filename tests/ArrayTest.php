<?php

declare(strict_types=1);

namespace Xcapher\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Xcapher\Exception\CastException;
use Xcapher\Exception\EscapeException;
use Xcapher\Tests\Fixtures\BrokenText;
use Xcapher\Tests\Fixtures\EmptyBag;
use Xcapher\Tests\Fixtures\Level;
use Xcapher\Tests\Fixtures\Point;
use Xcapher\Tests\Fixtures\Suit;
use Xcapher\Tests\Fixtures\Text;
use Xcapher\Type;
use Xcapher\Xcapher;

use function Xcapher\x;

/**
 * Nested access, key allowlists, typed lists, structure checks, reshaping, CSV and HTML attributes.
 */
#[CoversClass(Xcapher::class)]
#[CoversClass(CastException::class)]
#[UsesClass(Type::class)]
final class ArrayTest extends TestCase
{
    // ------------------------------------------------------------------
    // get() and has()
    // ------------------------------------------------------------------

    public function testGetWalksArraysObjectsAndArrayAccess(): void
    {
        $data = [
            'user' => [
                'name' => 'Bjørn',
                'address' => (object) ['city' => 'Oslo'],
                'tags' => new \ArrayObject(['admin' => false]),
                'nothing' => null,
            ],
            'list' => ['a', 'b'],
            'dotted.key' => 'literal',
        ];

        self::assertSame('Bjørn', x($data)->get('user.name')->value());
        self::assertSame('Oslo', x($data)->get('user.address.city')->value());
        self::assertFalse(x($data)->get('user.tags.admin')->value());
        self::assertSame('b', x($data)->get('list.1')->value());
        self::assertSame('a', x($data['list'])->get(0)->value());
        self::assertSame('literal', x($data)->get('dotted.key')->value());
        self::assertSame('Oslo', x($data)->get('user/address/city', separator: '/')->value());
    }

    public function testGetReturnsAWrappedNullForMissingPaths(): void
    {
        $data = ['user' => ['name' => 'Bjørn', 'age' => '42']];

        self::assertNull(x($data)->get('user.email')->value());
        self::assertNull(x($data)->get('user.name.first')->value());
        self::assertNull(x($data)->get('nope.deeper')->value());
        self::assertNull(x('text')->get('0')->value());
        self::assertNull(x(null)->get('a')->value());
        self::assertNull(x($data)->get('user.name', separator: '')->value());
        self::assertSame(42, x($data)->get('user.age')->int(0));
        self::assertSame(0, x($data)->get('user.missing')->int(0));
    }

    public function testGetDefault(): void
    {
        $data = ['user' => ['name' => 'Bjørn', 'nickname' => null]];

        self::assertSame('none', x($data)->get('user.email', 'none')->string());
        self::assertSame('Bjørn', x($data)->get('user.name', 'none')->string());
        self::assertNull(x($data)->get('user.nickname', 'none')->value());
        self::assertSame(['x'], x($data)->get('missing', ['x'])->array());
    }

    public function testGetDoesNotReadPrivateProperties(): void
    {
        self::assertSame(1, x(new Point())->get('x')->value());
        self::assertNull(x(new Point())->get('secret')->value());
    }

    public function testGetSurvivesArrayAccessThatThrows(): void
    {
        $broken = new class implements \ArrayAccess {
            public function offsetExists(mixed $offset): bool
            {
                throw new \RuntimeException('Broken.');
            }

            public function offsetGet(mixed $offset): mixed
            {
                return null;
            }

            public function offsetSet(mixed $offset, mixed $value): void {}

            public function offsetUnset(mixed $offset): void {}
        };

        self::assertNull(x($broken)->get('a')->value());
        self::assertFalse(x($broken)->has('a'));
    }

    public function testHas(): void
    {
        $data = ['user' => ['name' => null], 'dotted.key' => 1];

        self::assertTrue(x($data)->has('user'));
        self::assertTrue(x($data)->has('user.name'));
        self::assertTrue(x($data)->has('dotted.key'));
        self::assertFalse(x($data)->has('user.email'));
        self::assertFalse(x($data)->has('user.name.first'));
        self::assertFalse(x('string')->has('0'));
    }

    // ------------------------------------------------------------------
    // only(), except(), hasKeys()
    // ------------------------------------------------------------------

    public function testOnlyKeepsTheAllowedKeysInOriginalOrder(): void
    {
        $input = ['email' => 'a@b.c', 'is_admin' => '1', 'name' => 'Bjørn', 0 => 'zero'];

        self::assertSame(['email' => 'a@b.c', 'name' => 'Bjørn'], x($input)->only('name', 'email'));
        self::assertSame([0 => 'zero'], x($input)->only('0'));
        self::assertSame([], x($input)->only());
        self::assertSame(['x' => 1], x(new Point())->only('x', 'secret'));
    }

    public function testExcept(): void
    {
        self::assertSame(['name' => 'Bjørn'], x(['name' => 'Bjørn', 'is_admin' => '1'])->except('is_admin', 'missing'));
        self::assertSame(['a' => 1], x(['a' => 1])->except());
    }

    public function testHasKeys(): void
    {
        $data = ['name' => 'Bjørn', 'email' => null, 3 => 'x'];

        self::assertTrue(x($data)->hasKeys('name', 'email', 3, '3'));
        self::assertFalse(x($data)->hasKeys('name', 'phone'));
        self::assertTrue(x($data)->hasKeys());
        self::assertTrue(x(new \ArrayObject(['a' => 1]))->hasKeys('a'));
        self::assertTrue(x(new Point())->hasKeys('x', 'y'));
        self::assertFalse(x(new Point())->hasKeys('secret'));
        self::assertFalse(x('abc')->hasKeys(0));
    }

    // ------------------------------------------------------------------
    // Typed lists
    // ------------------------------------------------------------------

    public function testTypedLists(): void
    {
        self::assertSame(['a' => 1, 'b' => 2, 'c' => 0], x(['a' => '1', 'b' => 2.9, 'c' => 'x'])->ints());
        self::assertSame([5], x('5')->ints());
        self::assertSame([], x(null)->ints());
        self::assertSame([1.5, 2.0], x(['1.5', 2])->floats());
        self::assertSame(['1', '', 'H'], x([1, false, Suit::Hearts])->strings());
        self::assertSame([true, false, false], x(['yes', 'off', 0])->bools());
        self::assertSame([Level::High, Level::Off], x(['10', 0])->enums(Level::class));
    }

    public function testTypedListsFailWhenAnyElementFails(): void
    {
        self::assertNull(x([1, new \stdClass()])->tryInts());
        self::assertNull(x([1.5, \NAN, new BrokenText()])->tryFloats());
        self::assertNull(x(['a', []])->tryStrings());
        self::assertNull(x(['10', 'nope'])->tryEnums(Level::class));
        self::assertSame([1, 2], x(['1', '2'])->tryInts());
        self::assertSame([1.0], x(['1'])->tryFloats());
        self::assertSame(['1'], x([1])->tryStrings());
        self::assertSame([Suit::Hearts], x(['H'])->tryEnums(Suit::class));

        $this->expectException(CastException::class);
        x([1, [2]])->strings();
    }

    // ------------------------------------------------------------------
    // Structure
    // ------------------------------------------------------------------

    public function testIsAssoc(): void
    {
        self::assertTrue(x(['a' => 1])->isAssoc());
        self::assertTrue(x([1 => 'a'])->isAssoc());
        self::assertFalse(x([1, 2])->isAssoc());
        self::assertFalse(x([])->isAssoc());
        self::assertFalse(x('a')->isAssoc());
    }

    /**
     * @return iterable<string, array{mixed, int}>
     */
    public static function depthProvider(): iterable
    {
        yield 'not an array' => ['a', 0];
        yield 'object' => [new \ArrayObject([[1]]), 0];
        yield 'empty' => [[], 1];
        yield 'flat' => [[1, 2], 1];
        yield 'nested' => [[1, [2]], 2];
        yield 'uneven' => [[[1], [[2, [3]]]], 4];
        yield 'objects do not count' => [[new \ArrayObject([[1]])], 1];
    }

    #[DataProvider('depthProvider')]
    public function testDepth(mixed $value, int $expected): void
    {
        self::assertSame($expected, x($value)->depth());
    }

    public function testDepthStopsCountingAtTheLimit(): void
    {
        $deep = [];

        for ($i = 0; $i < 600; ++$i) {
            $deep = [$deep];
        }

        $recursive = [1];
        $recursive[] = &$recursive;

        self::assertSame(513, x($deep)->depth());
        self::assertSame(513, x($recursive)->depth());
    }

    public function testCount(): void
    {
        self::assertSame(2, x([1, 2])->count());
        self::assertSame(0, x(new EmptyBag())->count());
        self::assertNull(x('ab')->count());
        self::assertNull(x(null)->count());
    }

    public function testEveryAndSome(): void
    {
        $isInteger = static fn(Xcapher $item): bool => $item->isInteger();

        self::assertTrue(x(['1', 2, '+3'])->every($isInteger));
        self::assertFalse(x(['1', 'x'])->every($isInteger));
        self::assertTrue(x([])->every($isInteger));
        self::assertFalse(x('5')->every($isInteger));
        self::assertFalse(x(null)->every($isInteger));
        self::assertTrue(x(new \ArrayIterator([1, 2]))->every($isInteger));

        self::assertTrue(x(['x', '2'])->some($isInteger));
        self::assertFalse(x(['x', 'y'])->some($isInteger));
        self::assertFalse(x([])->some($isInteger));
        self::assertFalse(x('5')->some($isInteger));

        self::assertTrue(x(['a' => 1, 'b' => 2])->every(static fn(Xcapher $item, int|string $key): bool => \is_string($key)));
    }

    // ------------------------------------------------------------------
    // Reshaping
    // ------------------------------------------------------------------

    public function testFlatten(): void
    {
        $nested = ['a' => 1, 'b' => [2, [3, [4]]], 'c' => []];

        self::assertSame([1, 2, 3, 4], x($nested)->flatten());
        self::assertSame([1, 2, [3, [4]]], x($nested)->flatten(1));
        self::assertSame([1, [2, [3, [4]]], []], x($nested)->flatten(0));
        self::assertSame(['a'], x('a')->flatten());

        $object = new \ArrayObject([1]);
        self::assertSame([$object], x([[$object]])->flatten());
    }

    public function testDot(): void
    {
        $nested = ['user' => ['name' => 'Bjørn', 'tags' => ['a', 'b'], 'empty' => []], 'top' => 1];

        self::assertSame(['user.name' => 'Bjørn', 'user.tags.0' => 'a', 'user.tags.1' => 'b', 'user.empty' => [], 'top' => 1], x($nested)->dot());
        self::assertSame(['a/b' => 1], x(['a' => ['b' => 1]])->dot('/'));
        self::assertSame([0 => 'x'], x(['x'])->dot());
    }

    public function testRecursiveStructuresThrowInsteadOfLoopingForever(): void
    {
        $recursive = [1];
        $recursive[] = &$recursive;

        self::assertSame(['fallback'], x($recursive)->array(['fallback'], deep: true));

        $this->expectException(CastException::class);
        x($recursive)->flatten();
    }

    public function testDotThrowsForRecursiveArrays(): void
    {
        $recursive = [1];
        $recursive[] = &$recursive;

        $this->expectException(CastException::class);
        x($recursive)->dot();
    }

    public function testDeepArray(): void
    {
        $date = new \DateTimeImmutable();
        $object = (object) [
            'point' => new Point(),
            'items' => new \ArrayIterator(['k' => (object) ['v' => 1]]),
            'date' => $date,
            'suit' => Suit::Hearts,
            'list' => [(object) ['a' => 1]],
        ];

        self::assertSame([
            'point' => ['x' => 1, 'y' => 2],
            'items' => ['k' => ['v' => 1]],
            'date' => $date,
            'suit' => Suit::Hearts,
            'list' => [['a' => 1]],
        ], x($object)->array(deep: true));

        self::assertInstanceOf(Point::class, x($object)->array()['point']);
    }

    public function testDeepArrayAllowsTheSameObjectTwiceButNotCycles(): void
    {
        $shared = (object) ['a' => 1];
        self::assertSame(['x' => ['a' => 1], 'y' => ['a' => 1]], x(['x' => $shared, 'y' => $shared])->array(deep: true));

        $cycle = new \stdClass();
        $cycle->self = $cycle;

        $this->expectException(CastException::class);
        $this->expectExceptionMessage('refers to itself');
        x($cycle)->array(deep: true);
    }

    public function testDeepArrayDoesNotModifyTheInput(): void
    {
        $object = (object) ['a' => 1];
        $inner = ['o' => $object];
        $input = ['ref' => &$inner];

        self::assertSame(['ref' => ['o' => ['a' => 1]]], x($input)->array(deep: true));
        self::assertSame(['o' => $object], $inner);
    }

    // ------------------------------------------------------------------
    // CSV
    // ------------------------------------------------------------------

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function csvFieldProvider(): iterable
    {
        yield 'plain' => ['abc', 'abc'];
        yield 'empty' => ['', ''];
        yield 'null' => [null, ''];
        yield 'delimiter' => ['a,b', '"a,b"'];
        yield 'enclosure' => ['say "hi"', '"say ""hi"""'];
        yield 'newline' => ["a\nb", "\"a\nb\""];
        yield 'carriage return' => ["a\rb", "\"a\rb\""];
        yield 'leading space' => [' a', '" a"'];
        yield 'trailing space' => ['a ', '"a "'];
        yield 'formula' => ['=SUM(A1:A9)', "'=SUM(A1:A9)"];
        yield 'plus' => ['+47 123', "'+47 123"];
        yield 'minus string' => ['-2+3', "'-2+3"];
        yield 'at' => ['@cmd', "'@cmd"];
        yield 'tab' => ["\tx", "'\tx"];
        yield 'formula with delimiter' => ['=HYPERLINK("http://x","y")', "\"'=HYPERLINK(\"\"http://x\"\",\"\"y\"\")\""];
        yield 'negative int stays a number' => [-5, '-5'];
        yield 'negative float stays a number' => [-1.5, '-1.5'];
        yield 'formula in the middle is fine' => ['a=b', 'a=b'];
        yield 'stringable' => [new Text('x,y'), '"x,y"'];
    }

    #[DataProvider('csvFieldProvider')]
    public function testCsvField(mixed $value, string $expected): void
    {
        self::assertSame($expected, x($value)->csvField());
    }

    public function testCsvFieldOptions(): void
    {
        self::assertSame('a,b', x('a,b')->csvField(';'));
        self::assertSame('"a;b"', x('a;b')->csvField(';'));
        self::assertSame("'a''b'", x("a'b")->csvField(',', "'"));
        self::assertSame('=1+1', x('=1+1')->csvField(formulaSafe: false));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidCsvControlsProvider(): iterable
    {
        yield 'long delimiter' => [',,', '"'];
        yield 'empty delimiter' => ['', '"'];
        yield 'long enclosure' => [',', '""'];
        yield 'same characters' => ['"', '"'];
        yield 'newline delimiter' => ["\n", '"'];
        yield 'carriage return enclosure' => [',', "\r"];
    }

    #[DataProvider('invalidCsvControlsProvider')]
    public function testCsvRejectsInvalidControls(string $delimiter, string $enclosure): void
    {
        $this->expectException(EscapeException::class);
        x('a')->csvField($delimiter, $enclosure);
    }

    public function testCsv(): void
    {
        $rows = [
            ['name', 'phone', 'note'],
            ['Bjørn', '+47 123', 'says "hi", often'],
            ['Eve', null, '=cmd|\' /C calc\'!A0'],
        ];

        self::assertSame(
            "name,phone,note\nBjørn,'+47 123,\"says \"\"hi\"\", often\"\nEve,,'=cmd|' /C calc'!A0\n",
            x($rows)->csv(),
        );
        self::assertSame("a;1\r\n", x(['a', 1])->csv(';', '"', "\r\n"));
        self::assertSame('', x([])->csv());
        self::assertSame("x\n", x('x')->csv());
    }

    public function testCsvRejectsNestedFields(): void
    {
        $this->expectException(CastException::class);
        x(['a', ['b']])->csv();
    }

    public function testCsvRejectsInvalidControlsEvenWithoutData(): void
    {
        $this->expectException(EscapeException::class);
        x([])->csv('');
    }

    public function testHasOnArrayAccessWithAMissingKey(): void
    {
        self::assertFalse(x(new \ArrayObject(['a' => 1]))->has('b'));
        self::assertNull(x(new \ArrayObject(['a' => 1]))->get('b')->value());
    }

    public function testHtmlAttributeNameCannotEndInANewline(): void
    {
        $this->expectException(EscapeException::class);
        x(["title\n" => 'x'])->htmlAttributes();
    }

    public function testHtmlAttributeListOfBooleansIsNotAMap(): void
    {
        self::assertSame('data-flags="1"', x(['data-flags' => [true, false]])->htmlAttributes());
    }

    // ------------------------------------------------------------------
    // HTML attributes
    // ------------------------------------------------------------------

    public function testHtmlAttributes(): void
    {
        self::assertSame(
            'class="btn active" disabled data-id="5" title="&quot;Tom&quot; &amp; &lt;Jerry&gt;" required',
            x([
                'class' => ['btn', 'active', '', null],
                'disabled' => true,
                'hidden' => false,
                'data-id' => 5,
                'title' => '"Tom" & <Jerry>',
                'placeholder' => null,
                'required',
            ])->htmlAttributes(),
        );
    }

    public function testHtmlAttributeArrays(): void
    {
        self::assertSame('class="btn primary"', x(['class' => ['btn' => true, 'active' => false, 'primary' => true]])->htmlAttributes());
        self::assertSame('data-config="{&quot;a&quot;:1,&quot;b&quot;:[2]}"', x(['data-config' => ['a' => 1, 'b' => [2]]])->htmlAttributes());
        self::assertSame('data-list="[[1]]"', x(['data-list' => [[1]]])->htmlAttributes());
        self::assertSame('class=""', x(['class' => []])->htmlAttributes());
        self::assertSame('', x([])->htmlAttributes());
        self::assertSame('', x([false, null])->htmlAttributes());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidAttributeNameProvider(): iterable
    {
        yield 'space' => ['a b'];
        yield 'quote' => ['a"b'];
        yield 'apostrophe' => ["a'b"];
        yield 'greater than' => ['a>b'];
        yield 'slash' => ['a/b'];
        yield 'equals' => ['a=b'];
        yield 'control character' => ["a\x01b"];
        yield 'empty' => [''];
        yield 'invalid UTF-8' => ["\xFF"];
        yield 'injection' => ['x onload=alert(1) y'];
    }

    #[DataProvider('invalidAttributeNameProvider')]
    public function testHtmlAttributesRejectInvalidNames(string $name): void
    {
        $this->expectException(EscapeException::class);
        x([$name => 'v'])->htmlAttributes();
    }

    public function testHtmlAttributesRejectInvalidBareNames(): void
    {
        $this->expectException(EscapeException::class);
        x(['a b'])->htmlAttributes();
    }
}
