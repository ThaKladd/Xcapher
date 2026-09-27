# Xcapher

[![CI](https://github.com/ThaKladd/Xcapher/actions/workflows/ci.yml/badge.svg)](https://github.com/ThaKladd/Xcapher/actions/workflows/ci.yml)
![PHP](https://img.shields.io/badge/php-%E2%89%A5%208.5-777bb4)
![License](https://img.shields.io/badge/license-MIT-blue)

Escape, cast, sanitize and validate any PHP value through one small, fluent API.

```php
use function Xcapher\x;

x('12test')->int();                    // 12
x('<b>"Hi"</b>')->html();              // &lt;b&gt;&quot;Hi&quot;&lt;/b&gt;
x('Blåbær Syltetøy!')->slug();         // blabaer-syltetoy
x('bjørn@eksempel.no')->isEmail();     // true
x("O'Reilly")->quote($pdo);            // 'O''Reilly'
```

Wrap any value with `x()` and call the method for the result you need. Every method works on every input: it
either returns a value of the promised type or throws an `Xcapher\Exception\XcapherException`. It never
emits a PHP warning, notice or deprecation, and never leaks a `TypeError` or `ValueError`. A test in the suite
checks this for every method against more than 40 edge-case values, including NaN, invalid UTF-8, recursive arrays,
closed resources and generators that throw.

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Usage](#usage)
- [Features](#features)
  - [Casting](#casting)
  - [HTML, XML, JavaScript and CSS](#html-xml-javascript-and-css)
  - [URLs, JSON and Base64](#urls-json-and-base64)
  - [SQL](#sql)
  - [Shell and regular expressions](#shell-and-regular-expressions)
  - [Sanitizing](#sanitizing)
  - [Validation](#validation)
  - [Types](#types)
- [Error handling](#error-handling)
- [Upgrading from 0.x](#upgrading-from-0x)
- [Development](#development)
- [License](#license)

## Requirements

- PHP 8.5 or newer
- `ext-mbstring`
- Optional: `ext-intl` (better transliteration in `slug()`), and `ext-mysqli`, `ext-pgsql` or `ext-pdo`
  for the SQL helpers

## Installation

```bash
composer require xcapher/xcapher
```

## Usage

There are four equivalent ways to create an instance:

```php
use Xcapher\Xcapher;
use function Xcapher\x;

x($value);               // namespaced helper (recommended)
\x($value);              // global helper, only defined if no other x() function exists
Xcapher::from($value);
new Xcapher($value);
```

Instances are immutable (`final readonly`), so they are safe to reuse and share. `value()` returns the
original value and `type()` returns its [`Type`](#types).

## Features

### Casting

| Method | Returns | Notes |
| --- | --- | --- |
| `string(?string $default = null)` | `string` | Scalars as PHP converts them (`true` → `"1"`, `false`/`null` → `""`, `NAN` → `"NAN"`), backed enums → value, pure enums → name, dates → ATOM, `Stringable` → `__toString()`. |
| `int(?int $default = null)` | `int` | Parses the numeric prefix (`"12test"` → 12, `"abc"` → 0, `"1e3"` → 1000). Floats truncate towards zero and clamp to `PHP_INT_MIN`/`PHP_INT_MAX`. Arrays → 0/1, dates → timestamp. |
| `float(?float $default = null)` | `float` | Same rules as `int()` without truncation; dates keep microseconds. |
| `bool()` | `bool` | Reads strings semantically: `"1"`, `"true"`, `"on"`, `"yes"` → `true`; `"0"`, `"false"`, `"off"`, `"no"`, `""` → `false`. Case-insensitive and ignores surrounding whitespace; everything else follows PHP truthiness. Never throws. |
| `array(?array $default = null)` | `array` | `null` → `[]`, `Traversable` → `iterator_to_array()` (keys kept), objects → public properties, scalars → `[$value]`. |
| `list()` | `list` | `array()` re-indexed. |
| `object()` | `object` | Arrays → `stdClass`, `null` → empty `stdClass`, scalars → `stdClass{scalar}`. Never throws. |
| `to(Type $type)` | `mixed` | Generic form of the methods above. |
| `map(callable $fn)` | `array` | Wraps each element in a new `Xcapher` and maps it, preserving keys. |
| `tryString()`, `tryInt()`, `tryFloat()`, `tryArray()` | `?T` | Return `null` instead of throwing. |

A value that cannot be converted throws a `CastException`. This happens for arrays and plain objects in
`string()`, for `NAN`, resources and non-numeric objects in `int()`, and for a `Traversable` that throws while
being iterated. Pass a default, or use a `try*()` method, to avoid the exception:

```php
x([1, 2])->int();                       // 1 (non-empty array)
x(3.99)->int();                         // 3
x(1e30)->int();                         // PHP_INT_MAX (clamped, no warning)
x(new stdClass())->int(default: 0);     // 0
x(new stdClass())->tryInt();            // null
x('  YES ')->bool();                    // true
x('off')->bool();                       // false (PHP's (bool) "off" would be true)
x(['a' => '1', 'b' => 'x'])->map(fn ($v) => $v->int()); // ['a' => 1, 'b' => 0]
```

### HTML, XML, JavaScript and CSS

Choose the method for the context the output will land in. These methods convert their input with
`string()` first, so ints, enums and `Stringable` objects work as well. Invalid UTF-8 is replaced with U+FFFD.

| Method | Use for | Example |
| --- | --- | --- |
| `html(bool $doubleEncode = true)` | HTML body text and quoted attributes | `<a href="x">` → `&lt;a href=&quot;x&quot;&gt;` |
| `htmlAttr()` | Any attribute value, even unquoted (OWASP rules) | `a b=c` → `a&#x20;b&#x3D;c` |
| `htmlEntityEncode($flags, $encoding, $doubleEncode)` | Encoding all characters that have named entities | `blåbær` → `bl&aring;b&aelig;r` |
| `htmlEntityDecode($flags, $encoding)` | Decoding entities | `&lt;b&gt;` → `<b>` |
| `xml()` | XML text and attributes; removes characters that are illegal in XML 1.0 | `<a>&` → `&lt;a&gt;&amp;` |
| `js()` | Inside a quoted JavaScript string | `</script>` → `\x3C\x2Fscript\x3E` |
| `jsValue()` | A value embedded as a JS/JSON literal in `<script>` or an attribute | `['a' => '</b>']` → `{"a":"\u003C/b\u003E"}` |
| `css()` | CSS strings and identifiers | `a b;` → `a\20 b\3B ` |

```php
<p title="<?= x($title)->html() ?>"><?= x($body)->html() ?></p>
<div data-id=<?= x($id)->htmlAttr() ?>></div>
<script>
    const user = <?= x($user)->jsValue() ?>;
    const name = '<?= x($name)->js() ?>';
</script>
```

### URLs, JSON and Base64

| Method | Description |
| --- | --- |
| `urlEncode()` / `urlDecode()` | Query-string encoding (`urlencode`, spaces become `+`). Works on any string, not only valid URLs. |
| `rawUrlEncode()` / `rawUrlDecode()` | RFC 3986 encoding for path segments (spaces become `%20`). |
| `query()` | RFC 3986 query string built from `array()`: `['a' => 1, 'b' => 'x y']` → `a=1&b=x%20y`. |
| `json(int $flags = 0, bool $pretty = false)` | JSON with unescaped slashes and Unicode, `1.0` kept as `1.0`, and invalid UTF-8 substituted. Throws on `NAN`, `INF` or recursion. |
| `jsonDecode(bool $associative = true, int $depth = 512)` | Decodes JSON (big integers become strings). Throws on invalid JSON. |
| `base64Encode()` / `base64Decode()` | Strict Base64. Decoding throws on invalid input. |
| `base64UrlEncode()` / `base64UrlDecode()` | URL-safe Base64 (RFC 4648 §5). Encoding omits padding; decoding accepts it with or without. |

### SQL

Prepared statements are always the first choice. When you cannot use them, for example to build an
identifier, a `LIKE` pattern or a dynamic `ORDER BY`, use these methods. They accept a `mysqli`,
`PgSql\Connection` or `PDO` (MySQL, PostgreSQL or SQLite) connection, and some also accept an
`Xcapher\Database` value.

| Method | Description |
| --- | --- |
| `escape($connection)` | Escapes with the connection's own escaping, for use inside `'…'`. Needs a live connection. |
| `quote($connection)` | Returns a complete literal: `NULL`, a number, `TRUE`/`FALSE` (PostgreSQL) or `1`/`0`, or a quoted string. Strings need a live connection; other values also accept a `Database`. |
| `identifier($connection, bool $qualified = false)` | Quotes an identifier: `` `name` `` for MySQL, `"name"` for PostgreSQL and SQLite, doubling any embedded quotes. With `$qualified`, `schema.table` becomes `"schema"."table"`. |
| `like(string $escape = '\\')` | Escapes `%`, `_` and the escape character so the value matches literally. |

```php
use Xcapher\Database;

$sql = sprintf(
    'SELECT * FROM %s WHERE name LIKE ? ORDER BY %s',
    x('users')->identifier($pdo),
    x($_GET['sort'] ?? 'id')->identifier(Database::MySql),
);
$pdo->prepare($sql)->execute([x($search)->like() . '%']);

x(null)->quote($pdo);                 // NULL
x(true)->quote(Database::PostgreSql); // TRUE
x('public.users')->identifier(Database::PostgreSql, qualified: true); // "public"."users"
```

### Shell and regular expressions

| Method | Description |
| --- | --- |
| `shellArg()` | One safe shell argument (`escapeshellarg`). Throws `EscapeException` on NUL bytes or arguments longer than 131071 bytes (the Linux per-argument limit), consistently across PHP builds. |
| `regex(?string $delimiter = '/')` | Escapes regex metacharacters (`preg_quote`). |

### Sanitizing

These methods return a cleaned string and do not validate it; use the [validators](#validation) for that.

| Method | Description | Example |
| --- | --- | --- |
| `trim(?string $characters = null)` | Trims Unicode whitespace, or the given characters | `"\u{00A0} a "` → `a` |
| `squish()` | Trims and collapses internal whitespace | `" a \n b "` → `a b` |
| `stripTags($allowedTags = null)` | Removes HTML and PHP tags | `<p>Hi</p>` → `Hi` |
| `lower()` / `upper()` | Multibyte-safe case conversion | `BLÅBÆR` → `blåbær` |
| `digits()` | ASCII digits only | `+47 123 45` → `4712345` |
| `alpha()` / `alnum()` | Letters, or letters and digits, in any script | `Blåbær 2!` → `Blåbær2` |
| `email()` | `FILTER_SANITIZE_EMAIL` | `john@exa mple.com` → `john@example.com` |
| `url()` | `FILTER_SANITIZE_URL` | |
| `slug(string $separator = '-')` | Lowercase ASCII slug with built-in Latin transliteration, plus ext-intl for other scripts when installed | `Größe & Maß` → `grosse-mass` |
| `filename(string $replacement = '_')` | A safe single file name: no path separators, control or reserved characters, no leading dots and no Windows device names. At most 255 bytes, keeping the extension. | `../../etc/passwd` → `_.._etc_passwd` |

### Validation

Validators return a `bool` and never throw. Text validators accept strings, ints and `Stringable` objects.

| Method | True for |
| --- | --- |
| `is(Type ...$types)` | The value's type is one of the given types |
| `isNull()`, `isBool()`, `isInt()`, `isFloat()`, `isString()`, `isArray()`, `isObject()`, `isResource()` | Native types (closed resources count as resources) |
| `isScalar()`, `isList()`, `isCallable()`, `isIterable()`, `isCountable()` | Native checks |
| `isStringable()` | `string()` would succeed |
| `isNumeric()` | `42`, `1.5`, `"1e3"`, `" 42 "` |
| `isInteger()` | Ints, and strings holding a whole number in the int range (`"42"`, `"-7"`, `"007"`) |
| `isEmpty()` | `null`, `""`, `[]` and empty `Countable` objects, but not `0`, `"0"` or `false` |
| `isBlank()` | Empty, or whitespace only |
| `isEmail()` | Valid e-mail address, including Unicode local parts |
| `isUrl(array $schemes = ['http', 'https'])` | Absolute URL with an allowed scheme, checked with the PHP 8.5 WHATWG URL parser. Internationalized domains pass; input that the parser would have to correct (`http:example.com`, spaces) fails. Pass `[]` to allow any scheme. |
| `isIp()`, `isIpv4()`, `isIpv6()`, `isPublicIp()` | IP addresses; `isPublicIp()` rejects private and reserved ranges |
| `isMac()`, `isDomain()` | MAC address; host name |
| `isUuid()` | UUID of any version, case-insensitive |
| `isJson()`, `isBase64()`, `isUtf8()` | Valid JSON; strict Base64; valid UTF-8 |
| `isAlpha()`, `isAlnum()`, `isDigits()`, `isHex()` | Non-empty strings of those characters only |
| `isDate(string $format = 'Y-m-d')` | A real date in exactly that format (`2023-02-29` fails), or any `DateTimeInterface` |
| `matches(string $pattern)` | Matches the regex; an invalid pattern returns `false` |

```php
x('https://bücher.de')->isUrl();                // true
x('javascript:alert(1)')->isUrl();              // false
x('mailto:john@example.com')->isUrl(['mailto']); // true
x('29.02.2024')->isDate('d.m.Y');               // true
x('0')->isEmpty();                              // false
```

### Types

`Xcapher\Type` is a string-backed enum of PHP's native types: `Null`, `Bool`, `Int`, `Float`, `String`,
`Array`, `Object` and `Resource`.

```php
use Xcapher\Type;

x(1.5)->type();                        // Type::Float
x('a')->is(Type::String, Type::Int);   // true
x('12')->to(Type::Int);                // 12
Type::of($anything);                   // never throws
```

## Error handling

Every exception implements `Xcapher\Exception\XcapherException`:

| Exception | Extends | Thrown when |
| --- | --- | --- |
| `CastException` | `UnexpectedValueException` | A value cannot be converted, encoded or decoded |
| `EscapeException` | `RuntimeException` | A value cannot be escaped safely (unsupported connection, invalid identifier, NUL bytes in a shell argument, …) |

```php
use Xcapher\Exception\XcapherException;

try {
    $html = x($input)->html();
} catch (XcapherException $e) {
    // $input was an array, a non-stringable object or a resource
}
```

## Upgrading from 0.x

- The classes are now in the `Xcapher` namespace and autoloaded with PSR-4. The global `x()` helper is still
  available, and `Xcapher\x()` is added alongside it.
- `DataType`, `VariableType` and `DataBaseType` are replaced by the `Type` and `Database` enums, and
  `to()`/`is()` now take a `Type`.
- `Error\XcapherError` is replaced by `Xcapher\Exception\CastException` and `EscapeException`, which share the
  `XcapherException` interface.
- `setDb()`/`getDb()` are removed. Pass the connection straight to `escape()`, `quote()` or `identifier()`.
- `escape()` now raises the correct error. It used to report "No database connection." when the value was not
  scalar, and the reverse.
- `urlEncode()`/`urlDecode()` now work on any string. Before, they only accepted valid URLs.
- `htmlEntityEncode()`/`htmlEntityDecode()` now accept anything with a string form (for example ints).
- `array()` on an object now returns its public properties instead of PHP's mangled `(array)` cast.
- `string()` on a float no longer produces PHP 8.5's "NAN coerced to string" warning.
- PHP 8.5 is required.

## Development

```bash
composer install
composer test       # PHPUnit
composer analyse    # PHPStan, level max with strict rules
composer lint       # PHP-CS-Fixer (PER-CS 3.0 + PHP 8.5 migration), dry run
composer fix        # apply the coding standard
composer check      # lint + analyse + test
```

Run a single test or test class with `vendor/bin/phpunit --filter testSlug`. The runnable examples are in
`examples/index.php` (`php examples/index.php`).

## License

MIT. See [LICENSE](LICENSE).
