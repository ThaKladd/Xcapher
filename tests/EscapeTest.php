<?php

declare(strict_types=1);

namespace Xcapher\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Xcapher\Exception\CastException;
use Xcapher\Exception\EscapeException;
use Xcapher\Xcapher;

use function Xcapher\x;

#[CoversClass(Xcapher::class)]
#[UsesClass(CastException::class)]
final class EscapeTest extends TestCase
{
    public function testHtml(): void
    {
        self::assertSame('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', x('<script>alert("x")</script>')->html());
        self::assertSame('Tom &amp; Jerry&apos;s', x("Tom & Jerry's")->html());
        self::assertSame('&amp;amp;', x('&amp;')->html());
        self::assertSame('&amp;', x('&amp;')->html(doubleEncode: false));
        self::assertSame('42', x(42)->html());
        self::assertSame('', x(null)->html());
        self::assertSame("\u{FFFD}ok", x("\xC3ok")->html());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function htmlAttrProvider(): iterable
    {
        yield 'safe characters' => ['abc-XYZ_0.9,', 'abc-XYZ_0.9,'];
        yield 'space and equals' => ['a b=c', 'a&#x20;b&#x3D;c'];
        yield 'quotes' => ['"\'', '&quot;&#x27;'];
        yield 'named entities' => ['<&>', '&lt;&amp;&gt;'];
        yield 'latin-1' => ['é', '&#xE9;'];
        yield 'beyond latin-1' => ['€', '&#x20AC;'];
        yield 'astral' => ["\u{1F600}", '&#x1F600;'];
        yield 'control character' => ["\x01", '&#xFFFD;'];
        yield 'C1 control character' => ["\u{0085}", '&#xFFFD;'];
        yield 'tab is kept as entity' => ["\t", '&#x09;'];
        yield 'invalid UTF-8' => ["\xFF", '&#xFFFD;'];
        yield 'empty' => ['', ''];
    }

    #[DataProvider('htmlAttrProvider')]
    public function testHtmlAttr(string $value, string $expected): void
    {
        self::assertSame($expected, x($value)->htmlAttr());
    }

    public function testHtmlEntities(): void
    {
        self::assertSame('&lt;div&gt;test&lt;/div&gt;', x('<div>test</div>')->htmlEntityEncode());
        self::assertSame('bl&aring;b&aelig;r', x('blåbær')->htmlEntityEncode());
        self::assertSame('<div>blåbær</div>', x('&lt;div&gt;bl&aring;b&aelig;r&lt;/div&gt;')->htmlEntityDecode());
        self::assertSame('5', x(5)->htmlEntityEncode());
    }

    public function testHtmlEntitiesRejectUnknownEncoding(): void
    {
        $this->expectException(CastException::class);
        x('a')->htmlEntityEncode(encoding: 'not-an-encoding');
    }

    public function testXml(): void
    {
        self::assertSame('&lt;a b=&quot;c&quot;&gt;&amp;&apos;', x('<a b="c">&\'')->xml());
        self::assertSame("a\tb\nc", x("a\x00\tb\x0B\nc\x1F")->xml());
        self::assertSame("\u{FFFD}", x("\xFF")->xml());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function jsProvider(): iterable
    {
        yield 'safe' => ['abc,._123', 'abc,._123'];
        yield 'quotes' => ['"\'', '\x22\x27'];
        yield 'script close' => ['</script>', '\x3C\x2Fscript\x3E'];
        yield 'newline' => ["\n", '\x0A'];
        yield 'BMP' => ['é', '\u00E9'];
        yield 'line separator' => ["\u{2028}", '\u2028'];
        yield 'astral uses surrogate pair' => ["\u{1F600}", '\uD83D\uDE00'];
    }

    #[DataProvider('jsProvider')]
    public function testJs(string $value, string $expected): void
    {
        self::assertSame($expected, x($value)->js());
    }

    public function testJsValue(): void
    {
        self::assertSame('"\u003C/script\u003E\u0026\u0027\u0022"', x('</script>&\'"')->jsValue());
        self::assertSame('{"a":[1,2.0,null,true]}', x(['a' => [1, 2.0, null, true]])->jsValue());
    }

    public function testCss(): void
    {
        self::assertSame('a\20 b\3B \7D ', x('a b;}')->css());
        self::assertSame('\E9 ', x('é')->css());
        self::assertSame('abc123', x('abc123')->css());
    }

    public function testUrlEncoding(): void
    {
        self::assertSame('https%3A%2F%2Ftest.com%2F', x('https://test.com/')->urlEncode());
        self::assertSame('a+b%26c', x('a b&c')->urlEncode());
        self::assertSame('a b&c', x('a+b%26c')->urlDecode());
        self::assertSame('a%20b%2Bc', x('a b+c')->rawUrlEncode());
        self::assertSame('a b+c', x('a%20b+c')->rawUrlDecode());
        self::assertSame('12', x(12)->urlEncode());
    }

    public function testQuery(): void
    {
        self::assertSame('a=1&b=x%20y&c%5B0%5D=z', x(['a' => 1, 'b' => 'x y', 'c' => ['z']])->query());
        self::assertSame('a=1', x((object) ['a' => 1])->query());
        self::assertSame('', x(null)->query());
    }

    public function testJson(): void
    {
        self::assertSame('{"url":"https://x.no/","name":"Blåbær","n":1.0}', x(['url' => 'https://x.no/', 'name' => 'Blåbær', 'n' => 1.0])->json());
        self::assertSame("[\n    1\n]", x([1])->json(pretty: true));
        self::assertSame('"\u003Cb\u003E"', x('<b>')->json(\JSON_HEX_TAG));
        self::assertSame("\"\u{FFFD}\"", x("\xFF")->json());
    }

    public function testJsonFailsForUnencodableValues(): void
    {
        $this->expectException(CastException::class);
        x(\NAN)->json();
    }

    public function testJsonDecode(): void
    {
        self::assertSame(['a' => [1, 2]], x('{"a":[1,2]}')->jsonDecode());
        self::assertEquals((object) ['a' => 1], x('{"a":1}')->jsonDecode(associative: false));
        self::assertSame('123456789012345678901234567890', x('123456789012345678901234567890')->jsonDecode());
        self::assertNull(x('null')->jsonDecode());
    }

    public function testJsonDecodeFailsForInvalidJson(): void
    {
        $this->expectException(CastException::class);
        x('{invalid')->jsonDecode();
    }

    public function testJsonDecodeFailsBeyondDepth(): void
    {
        $this->expectException(CastException::class);
        x('[[1]]')->jsonDecode(depth: 1);
    }

    public function testBase64(): void
    {
        self::assertSame('aGk/Pz4+', x('hi??>>')->base64Encode());
        self::assertSame('hi??>>', x('aGk/Pz4+')->base64Decode());
        self::assertSame('hi??>>', x(" aGk/Pz4+\n")->base64Decode());
        self::assertSame('aGk_Pz4-', x('hi??>>')->base64UrlEncode());
        self::assertSame('YQ', x('a')->base64UrlEncode());
        self::assertSame('a', x('YQ')->base64UrlDecode());
        self::assertSame('a', x('YQ==')->base64UrlDecode());
        self::assertSame('hi??>>', x('aGk_Pz4-')->base64UrlDecode());
        self::assertSame('', x('')->base64Decode());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidBase64Provider(): iterable
    {
        yield 'invalid character' => ['ab$d'];
        yield 'missing padding' => ['YQ'];
        yield 'url alphabet' => ['aGk_Pz4-'];
    }

    #[DataProvider('invalidBase64Provider')]
    public function testInvalidBase64Throws(string $value): void
    {
        $this->expectException(CastException::class);
        x($value)->base64Decode();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidBase64UrlProvider(): iterable
    {
        yield 'standard alphabet' => ['aGk/Pz4+'];
        yield 'impossible length' => ['abcde'];
    }

    #[DataProvider('invalidBase64UrlProvider')]
    public function testInvalidBase64UrlThrows(string $value): void
    {
        $this->expectException(CastException::class);
        x($value)->base64UrlDecode();
    }

    public function testShellArg(): void
    {
        self::assertSame("'it'\\''s; rm -rf /'", x("it's; rm -rf /")->shellArg());
        self::assertSame("''", x('')->shellArg());
    }

    public function testShellArgAcceptsTheMaximumLength(): void
    {
        self::assertSame(131_073, \strlen(x(str_repeat('a', 131_071))->shellArg()));
    }

    public function testShellArgRejectsOverlongArguments(): void
    {
        $this->expectException(EscapeException::class);
        $this->expectExceptionMessage('Shell arguments cannot be longer than 131071 bytes.');
        x(str_repeat('a', 131_072))->shellArg();
    }

    public function testShellArgRejectsNulBytes(): void
    {
        $this->expectException(EscapeException::class);
        x("a\0b")->shellArg();
    }

    public function testRegex(): void
    {
        self::assertSame('1\.5\+\(a\)\/b', x('1.5+(a)/b')->regex());
        self::assertSame('a\#b/c', x('a#b/c')->regex('#'));
        self::assertSame('a/b', x('a/b')->regex(null));
        self::assertSame(1, preg_match('/^' . x('a.b*')->regex() . '$/', 'a.b*'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function htmlAttrBoundaryProvider(): iterable
    {
        yield 'last C0 control' => ["\x1F", '&#xFFFD;'];
        yield 'DEL' => ["\x7F", '&#xFFFD;'];
        yield 'last C1 control' => ["\u{9F}", '&#xFFFD;'];
        yield 'first after C1' => ["\u{A0}", '&#xA0;'];
        yield 'last two-digit entity' => ["\u{FF}", '&#xFF;'];
        yield 'first four-digit entity' => ["\u{100}", '&#x0100;'];
    }

    #[DataProvider('htmlAttrBoundaryProvider')]
    public function testHtmlAttrBoundaries(string $value, string $expected): void
    {
        self::assertSame($expected, x($value)->htmlAttr());
    }

    public function testHtmlEntityDefaults(): void
    {
        self::assertSame('&#039;&quot;', x('\'"')->htmlEntityEncode());
        self::assertSame('&amp;amp;', x('&amp;')->htmlEntityEncode());
        self::assertSame("\u{FFFD}", x("\xFF")->htmlEntityEncode());
        self::assertSame('\'"', x('&#039;&quot;')->htmlEntityDecode());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function jsBoundaryProvider(): iterable
    {
        yield 'last ASCII' => ["\x7F", '\x7F'];
        yield 'first non-ASCII' => ["\u{80}", '\u0080'];
        yield 'last BMP' => ["\u{FFFF}", '\uFFFF'];
        yield 'first astral' => ["\u{10000}", '\uD800\uDC00'];
        yield 'odd astral' => ["\u{10437}", '\uD801\uDC37'];
        yield 'last code point' => ["\u{10FFFF}", '\uDBFF\uDFFF'];
    }

    #[DataProvider('jsBoundaryProvider')]
    public function testJsBoundaries(string $value, string $expected): void
    {
        self::assertSame($expected, x($value)->js());
    }

    public function testJsonDefaults(): void
    {
        self::assertSame('"<a>"', x('<a>')->json());
        self::assertSame("{\n    \"a\": \"/å\"\n}", x(['a' => '/å'])->json(pretty: true));
    }

    public function testJsonDecodeDefaultDepthAllows511NestedArrays(): void
    {
        self::assertIsArray(x(str_repeat('[', 511) . str_repeat(']', 511))->jsonDecode());

        $this->expectException(CastException::class);
        x(str_repeat('[', 512) . str_repeat(']', 512))->jsonDecode();
    }

    public function testCastExceptionMessageIncludesTheReason(): void
    {
        $this->expectExceptionMessage('Cannot convert value of type string to decoded JSON: Syntax error');
        x('{invalid')->jsonDecode();
    }

    public function testBase64UrlDecodePadsEveryLength(): void
    {
        self::assertSame('ab', x('YWI')->base64UrlDecode());
        self::assertSame('ab', x('YWI=')->base64UrlDecode());
        self::assertSame('abc', x('YWJj')->base64UrlDecode());
        self::assertSame('a', x(" YQ\n")->base64UrlDecode());
    }

    public function testInvalidUtf8HandlingRestoresTheSubstituteCharacter(): void
    {
        $before = mb_substitute_character();

        self::assertSame("\u{FFFD}", x("\xFF")->lower());
        self::assertSame($before, mb_substitute_character());
    }
}
