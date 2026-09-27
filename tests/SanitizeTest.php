<?php

declare(strict_types=1);

namespace Xcapher\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Xcapher\Xcapher;

use function Xcapher\x;

#[CoversClass(Xcapher::class)]
final class SanitizeTest extends TestCase
{
    public function testTrim(): void
    {
        self::assertSame('a b', x(" \t a b \n\u{00A0}\u{3000}")->trim());
        self::assertSame('a', x('--a--')->trim('-'));
        self::assertSame('5', x(5)->trim());
    }

    public function testSquish(): void
    {
        self::assertSame('a b c', x("  a \n\n b\t\tc  ")->squish());
        self::assertSame('', x(" \n ")->squish());
    }

    public function testStripTags(): void
    {
        self::assertSame('Hello world', x('<p>Hello <b>world</b></p><script></script>')->stripTags());
        self::assertSame('Hello <b>world</b>', x('<p>Hello <b>world</b></p>')->stripTags(['b']));
        self::assertSame('Hello <b>world</b>', x('<p>Hello <b>world</b></p>')->stripTags('<b>'));
    }

    public function testCase(): void
    {
        self::assertSame('blåbær', x('BLÅBÆR')->lower());
        self::assertSame('BLÅBÆR', x('blåbær')->upper());
    }

    public function testCharacterFilters(): void
    {
        self::assertSame('4712345678', x('+47 123 45 678')->digits());
        self::assertSame('15', x(-1.50)->digits());
        self::assertSame('Blåbærsyltetøy', x('Blåbær-syltetøy 2!')->alpha());
        self::assertSame('Blåbær2', x('Blåbær 2!')->alnum());
        self::assertSame('Ωμέγα', x('Ωμέγα!')->alpha());
    }

    public function testEmailAndUrlSanitizing(): void
    {
        self::assertSame('john@example.com', x(' john@exa mple.com ')->email());
        self::assertSame('https://example.com/a?b=c', x(" https://exa\tmple.com/a?b=c ")->url());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function slugProvider(): iterable
    {
        yield 'norwegian' => ['Blåbær Syltetøy!', 'blabaer-syltetoy'];
        yield 'german' => ['Größe & Maß', 'grosse-mass'];
        yield 'french' => ['Crème Brûlée', 'creme-brulee'];
        yield 'polish' => ['Łódź', 'lodz'];
        yield 'collapses separators' => ['  --Hello,   World--  ', 'hello-world'];
        yield 'digits kept' => ['PHP 8.5', 'php-8-5'];
        yield 'nothing usable' => ['!!!', ''];
    }

    #[DataProvider('slugProvider')]
    public function testSlug(string $value, string $expected): void
    {
        self::assertSame($expected, x($value)->slug());
    }

    public function testSlugSeparator(): void
    {
        self::assertSame('hello_world', x('Hello World')->slug('_'));
        self::assertSame('helloworld', x('Hello World')->slug(''));
        self::assertSame('a.b', x('..a b..')->slug('.'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function filenameProvider(): iterable
    {
        yield 'plain' => ['report 2024.pdf', 'report 2024.pdf'];
        yield 'path traversal' => ['../../etc/passwd', '_.._etc_passwd'];
        yield 'windows path' => ['C:\\Windows\\win.ini', 'C_Windows_win.ini'];
        yield 'reserved characters' => ['a<b>c:d"e|f?g*h', 'a_b_c_d_e_f_g_h'];
        yield 'control characters' => ["a\0b\nc", 'a_b_c'];
        yield 'hidden file' => ['.htaccess', 'htaccess'];
        yield 'trailing dots and spaces' => ['name. . ', 'name'];
        yield 'windows device' => ['CON', '_CON'];
        yield 'windows device with extension' => ['lpt1.txt', '_lpt1.txt'];
        yield 'unicode kept' => ['blåbær.txt', 'blåbær.txt'];
        yield 'nothing usable' => ['..', ''];
    }

    #[DataProvider('filenameProvider')]
    public function testFilename(string $value, string $expected): void
    {
        self::assertSame($expected, x($value)->filename());
    }

    public function testFilenameReplacementIsSanitizedToo(): void
    {
        self::assertSame('a-b', x('a/b')->filename('-'));
        self::assertSame('ab', x('a/b')->filename('/'));
    }

    public function testFilenameIsLimitedTo255BytesKeepingTheExtension(): void
    {
        $name = x(str_repeat('ø', 200) . '.txt')->filename();

        self::assertSame(254, \strlen($name));
        self::assertStringEndsWith('ø.txt', $name);
        self::assertTrue(mb_check_encoding($name, 'UTF-8'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function reservedNameProvider(): iterable
    {
        yield 'lower case device' => ['con', '_con'];
        yield 'numbered device' => ['COM1.log', '_COM1.log'];
        yield 'device as suffix' => ['XCON', 'XCON'];
        yield 'device as prefix' => ['CONSOLE', 'CONSOLE'];
    }

    #[DataProvider('reservedNameProvider')]
    public function testFilenameReservedNames(string $value, string $expected): void
    {
        self::assertSame($expected, x($value)->filename());
    }

    public function testFilenameLengthBoundaries(): void
    {
        $exact = str_repeat('a', 251) . '.txt';
        self::assertSame($exact, x($exact)->filename());

        self::assertSame(str_repeat('a', 251) . '.txt', x(str_repeat('a', 300) . '.txt')->filename());
        self::assertSame(str_repeat('a', 255), x(str_repeat('a', 300))->filename());

        $longExtension = '.' . str_repeat('e', 15);
        self::assertSame(str_repeat('a', 239) . $longExtension, x(str_repeat('a', 300) . $longExtension)->filename());

        $tooLongExtension = '.' . str_repeat('e', 16);
        self::assertSame(str_repeat('a', 255), x(str_repeat('a', 300) . $tooLongExtension)->filename());
    }

    #[RequiresPhpExtension('intl')]
    public function testSlugTransliteratesOtherScriptsWithIntl(): void
    {
        self::assertSame('privet-mir', x('Привет, мир')->slug());
        self::assertSame('aaeo', x('ÅÆØ')->slug());
    }
}
