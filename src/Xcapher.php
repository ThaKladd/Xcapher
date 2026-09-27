<?php

declare(strict_types=1);

namespace Xcapher;

use Xcapher\Exception\CastException;
use Xcapher\Exception\EscapeException;

/**
 * Wraps a single value and escapes, casts, sanitizes or validates it on demand.
 *
 * Instances are immutable, and every method is safe to call on any value. Methods either
 * return a result or throw an {@see Exception\XcapherException}. They never emit PHP
 * warnings, notices or deprecations, and never leak a TypeError or ValueError.
 *
 * Usually created through the `x()` helper: `x($input)->int()`.
 */
final readonly class Xcapher
{
    private const float INT_BOUND = 9.2233720368547758E+18;

    /**
     * Linux MAX_ARG_STRLEN (32 pages of 4 KiB) minus the terminating NUL byte.
     */
    private const int MAX_SHELL_ARG_BYTES = 131_071;

    private const string NUMBER_PREFIX = '/^\s*[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?/';

    /**
     * Lower-case transliterations applied by {@see slug()} before any optional ext-intl pass.
     */
    private const array TRANSLITERATION = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ā' => 'a', 'ă' => 'a', 'ą' => 'a',
        'æ' => 'ae', 'ç' => 'c', 'ć' => 'c', 'ĉ' => 'c', 'ċ' => 'c', 'č' => 'c', 'ď' => 'd', 'đ' => 'd', 'ð' => 'd',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ĕ' => 'e', 'ė' => 'e', 'ę' => 'e', 'ě' => 'e',
        'ĝ' => 'g', 'ğ' => 'g', 'ġ' => 'g', 'ģ' => 'g', 'ĥ' => 'h', 'ħ' => 'h',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ĩ' => 'i', 'ī' => 'i', 'ĭ' => 'i', 'į' => 'i', 'ı' => 'i',
        'ĳ' => 'ij', 'ĵ' => 'j', 'ķ' => 'k', 'ĺ' => 'l', 'ļ' => 'l', 'ľ' => 'l', 'ŀ' => 'l', 'ł' => 'l',
        'ñ' => 'n', 'ń' => 'n', 'ņ' => 'n', 'ň' => 'n', 'ŉ' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ō' => 'o', 'ŏ' => 'o', 'ő' => 'o',
        'œ' => 'oe', 'ŕ' => 'r', 'ŗ' => 'r', 'ř' => 'r', 'ś' => 's', 'ŝ' => 's', 'ş' => 's', 'š' => 's', 'ș' => 's',
        'ß' => 'ss', 'ţ' => 't', 'ť' => 't', 'ŧ' => 't', 'ț' => 't', 'þ' => 'th',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ũ' => 'u', 'ū' => 'u', 'ŭ' => 'u', 'ů' => 'u', 'ű' => 'u', 'ų' => 'u',
        'ŵ' => 'w', 'ý' => 'y', 'ÿ' => 'y', 'ŷ' => 'y', 'ź' => 'z', 'ż' => 'z', 'ž' => 'z',
    ];

    public function __construct(
        private mixed $value,
    ) {}

    public static function from(mixed $value): self
    {
        return new self($value);
    }

    // ------------------------------------------------------------------
    // Introspection
    // ------------------------------------------------------------------

    /**
     * Returns the wrapped value untouched.
     */
    public function value(): mixed
    {
        return $this->value;
    }

    public function type(): Type
    {
        return Type::of($this->value);
    }

    /**
     * True when the value's native type is any of the given types.
     */
    public function is(Type ...$types): bool
    {
        return \in_array($this->type(), $types, true);
    }

    // ------------------------------------------------------------------
    // Casting
    // ------------------------------------------------------------------

    /**
     * Casts to the given type, like calling the matching method ({@see string()}, {@see int()}, ...).
     *
     * @throws CastException
     */
    public function to(Type $type): mixed
    {
        return match ($type) {
            Type::String => $this->string(),
            Type::Int => $this->int(),
            Type::Float => $this->float(),
            Type::Bool => $this->bool(),
            Type::Array => $this->array(),
            Type::Object => $this->object(),
            Type::Null => $this->value === null ? null : throw CastException::create($this->value, 'null'),
            Type::Resource => \is_resource($this->value) ? $this->value : throw CastException::create($this->value, 'resource'),
        };
    }

    /**
     * Converts to a string.
     *
     * Scalars use PHP's own conversion (true → "1", false → "", null → ""). Backed enums give their value,
     * pure enums their name, dates an ATOM timestamp, and Stringable objects their string form.
     * Arrays, other objects and resources cannot be converted.
     *
     * @param string|null $default returned instead of throwing when the value cannot be converted
     *
     * @throws CastException when the value cannot be converted and no default is given
     */
    public function string(?string $default = null): string
    {
        return $this->orDefault($this->castString(...), $default);
    }

    public function tryString(): ?string
    {
        return $this->orNull($this->castString(...));
    }

    /**
     * Converts to an integer.
     *
     * Numeric strings are parsed from their numeric prefix ("12test" → 12, "abc" → 0, "1e3" → 1000).
     * Floats are truncated towards zero and clamped to PHP_INT_MIN..PHP_INT_MAX. Booleans and null
     * become 1/0, arrays 0 when empty and 1 otherwise, backed enums their value and dates their
     * Unix timestamp. Stringable objects are parsed like strings.
     *
     * @param int|null $default returned instead of throwing when the value cannot be converted
     *
     * @throws CastException for NaN, resources and objects without a numeric form, unless a default is given
     */
    public function int(?int $default = null): int
    {
        return $this->orDefault($this->castInt(...), $default);
    }

    public function tryInt(): ?int
    {
        return $this->orNull($this->castInt(...));
    }

    /**
     * Converts to a float, using the same rules as {@see int()} without truncation.
     * Dates keep their microseconds.
     *
     * @param float|null $default returned instead of throwing when the value cannot be converted
     *
     * @throws CastException for resources and objects without a numeric form, unless a default is given
     */
    public function float(?float $default = null): float
    {
        return $this->orDefault($this->castFloat(...), $default);
    }

    public function tryFloat(): ?float
    {
        return $this->orNull($this->castFloat(...));
    }

    /**
     * Converts to a boolean. Never throws.
     *
     * Strings are read semantically: "1", "true", "on" and "yes" are true, and "0", "false", "off",
     * "no" and "" are false (case-insensitive, surrounding whitespace ignored). Any other string
     * follows PHP truthiness. Other values follow PHP truthiness, so an empty array is false and any
     * object is true (except Stringable objects, which are read like strings).
     */
    public function bool(): bool
    {
        $value = $this->value;

        return match (true) {
            \is_float($value) && is_nan($value) => true,
            \is_string($value) => self::parseBool($value),
            $value instanceof \BackedEnum => \is_string($value->value) ? self::parseBool($value->value) : $value->value !== 0,
            $value instanceof \Stringable => self::parseBool(self::stringify($value, 'bool', true)),
            default => (bool) $value,
        };
    }

    /**
     * Converts to an array.
     *
     * Null becomes [], arrays are returned as-is, and Traversable objects are iterated with their keys
     * preserved. Other objects give their public properties, while enums, dates and closures are wrapped
     * like scalars. Any scalar or resource is wrapped as [$value].
     *
     * @param array<mixed>|null $default returned instead of throwing when the value cannot be converted
     *
     * @throws CastException when iterating a Traversable fails, unless a default is given
     *
     * @return array<mixed>
     */
    public function array(?array $default = null): array
    {
        return $this->orDefault($this->castArray(...), $default);
    }

    /**
     * @return array<mixed>|null
     */
    public function tryArray(): ?array
    {
        return $this->orNull($this->castArray(...));
    }

    /**
     * Like {@see array()}, but re-indexed as a list.
     *
     * @throws CastException
     *
     * @return list<mixed>
     */
    public function list(): array
    {
        return array_values($this->array());
    }

    /**
     * Converts to an object. Never throws.
     *
     * Objects are returned as-is, null becomes an empty stdClass, arrays become a stdClass
     * (nested arrays stay arrays), and scalars become a stdClass with a single `scalar` property.
     */
    public function object(): object
    {
        $value = $this->value;

        return match (true) {
            \is_object($value) => $value,
            $value === null => new \stdClass(),
            \is_array($value) => (object) $value,
            default => (object) ['scalar' => $value],
        };
    }

    /**
     * Wraps each element of {@see array()} in a new Xcapher and maps it through the callback, preserving keys.
     *
     * @template T
     *
     * @param callable(self, array-key): T $callback
     *
     * @throws CastException
     *
     * @return array<T>
     */
    public function map(callable $callback): array
    {
        $result = [];

        foreach ($this->array() as $key => $item) {
            $result[$key] = $callback(new self($item), $key);
        }

        return $result;
    }

    // ------------------------------------------------------------------
    // HTML, XML, JavaScript, CSS
    // ------------------------------------------------------------------

    /**
     * Escapes for HTML body text and quoted attribute values (htmlspecialchars, HTML5, UTF-8).
     * Invalid UTF-8 is replaced with U+FFFD.
     *
     * @throws CastException when the value cannot be converted to a string
     */
    public function html(bool $doubleEncode = true): string
    {
        return htmlspecialchars($this->castString(), \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5, 'UTF-8', $doubleEncode);
    }

    /**
     * Escapes for an HTML attribute value, including unquoted attributes. Every character except
     * [A-Za-z0-9,.-_] is encoded as an entity, following the OWASP recommendation.
     *
     * @throws CastException when the value cannot be converted to a string
     */
    public function htmlAttr(): string
    {
        return self::replace('/[^a-z0-9,.\-_]/iu', $this->utf8(), static function (string $char): string {
            $ord = mb_ord($char, 'UTF-8');

            if (($ord <= 0x1F && $char !== "\t" && $char !== "\n" && $char !== "\r") || ($ord >= 0x7F && $ord <= 0x9F)) {
                return '&#xFFFD;';
            }

            return match ($ord) {
                0x22 => '&quot;',
                0x26 => '&amp;',
                0x3C => '&lt;',
                0x3E => '&gt;',
                default => \sprintf($ord > 0xFF ? '&#x%04X;' : '&#x%02X;', $ord),
            };
        });
    }

    /**
     * Encodes every character that has an HTML entity (htmlentities).
     * Prefer {@see html()} unless you specifically need named entities.
     *
     * @throws CastException when the value cannot be converted to a string
     */
    public function htmlEntityEncode(int $flags = \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML401, ?string $encoding = null, bool $doubleEncode = true): string
    {
        $text = $this->castString();

        return self::guard(static fn(): string => htmlentities($text, $flags, $encoding, $doubleEncode));
    }

    /**
     * Decodes HTML entities (html_entity_decode).
     *
     * @throws CastException when the value cannot be converted to a string
     */
    public function htmlEntityDecode(int $flags = \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML401, ?string $encoding = null): string
    {
        $text = $this->castString();

        return self::guard(static fn(): string => html_entity_decode($text, $flags, $encoding));
    }

    /**
     * Escapes for XML text and attribute values. Characters that are illegal in XML 1.0 are removed,
     * and invalid UTF-8 is replaced with U+FFFD.
     *
     * @throws CastException when the value cannot be converted to a string
     */
    public function xml(): string
    {
        $text = self::replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x{FFFE}\x{FFFF}]/u', $this->utf8(), static fn(): string => '');

        return htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_XML1, 'UTF-8');
    }

    /**
     * Escapes for use inside a quoted JavaScript string literal. Every character except
     * [A-Za-z0-9,._] becomes a \xHH or \uHHHH escape, so the result is also safe in HTML attributes.
     *
     * @throws CastException when the value cannot be converted to a string
     */
    public function js(): string
    {
        return self::replace('/[^a-z0-9,._]/iu', $this->utf8(), static function (string $char): string {
            $ord = mb_ord($char, 'UTF-8');

            if ($ord < 0x80) {
                return \sprintf('\x%02X', $ord);
            }

            if ($ord <= 0xFFFF) {
                return \sprintf('\u%04X', $ord);
            }

            $ord -= 0x10000;

            return \sprintf('\u%04X\u%04X', 0xD800 | ($ord >> 10), 0xDC00 | ($ord & 0x3FF));
        });
    }

    /**
     * Encodes the value as a JavaScript/JSON literal that is safe to embed in a <script> block or
     * an HTML attribute (<, >, &, ' and " are hex-escaped).
     *
     * @throws CastException when the value cannot be JSON encoded
     */
    public function jsValue(): string
    {
        return $this->json(\JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT);
    }

    /**
     * Escapes for a CSS string or identifier. Every non-alphanumeric character becomes a `\HEX ` escape.
     *
     * @throws CastException when the value cannot be converted to a string
     */
    public function css(): string
    {
        return self::replace('/[^a-z0-9]/iu', $this->utf8(), static fn(string $char): string => \sprintf('\%X ', mb_ord($char, 'UTF-8')));
    }

    // ------------------------------------------------------------------
    // URL, JSON, Base64
    // ------------------------------------------------------------------

    /**
     * Encodes for a query string component (urlencode, spaces become "+").
     *
     * @throws CastException when the value cannot be converted to a string
     */
    public function urlEncode(): string
    {
        return urlencode($this->castString());
    }

    /**
     * @throws CastException when the value cannot be converted to a string
     */
    public function urlDecode(): string
    {
        return urldecode($this->castString());
    }

    /**
     * Encodes for a URL path segment (RFC 3986, spaces become "%20").
     *
     * @throws CastException when the value cannot be converted to a string
     */
    public function rawUrlEncode(): string
    {
        return rawurlencode($this->castString());
    }

    /**
     * @throws CastException when the value cannot be converted to a string
     */
    public function rawUrlDecode(): string
    {
        return rawurldecode($this->castString());
    }

    /**
     * Builds an RFC 3986 query string from {@see array()}, e.g. ['a' => 1, 'b' => 'x y'] → "a=1&b=x%20y".
     *
     * @throws CastException
     */
    public function query(): string
    {
        $data = $this->array();

        return self::guard(static fn(): string => http_build_query($data, '', '&', \PHP_QUERY_RFC3986));
    }

    /**
     * Encodes as JSON. Slashes and Unicode are left unescaped, float zero fractions are kept,
     * and invalid UTF-8 is replaced with U+FFFD. Extra JSON_* flags are added to these defaults.
     *
     * @throws CastException when the value cannot be encoded (for example NaN, INF or a resource)
     */
    public function json(int $flags = 0, bool $pretty = false): string
    {
        $flags |= \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION | \JSON_INVALID_UTF8_SUBSTITUTE;

        if ($pretty) {
            $flags |= \JSON_PRETTY_PRINT;
        }

        try {
            $json = json_encode($this->value, $flags);
        } catch (\Throwable $e) {
            throw CastException::create($this->value, 'JSON', $e->getMessage());
        }

        return $json !== false ? $json : throw CastException::create($this->value, 'JSON', json_last_error_msg());
    }

    /**
     * Decodes a JSON string. Objects become associative arrays unless $associative is false.
     *
     * @param int<1, max> $depth
     *
     * @throws CastException when the value is not valid JSON
     */
    public function jsonDecode(bool $associative = true, int $depth = 512): mixed
    {
        $text = $this->castString();

        try {
            return json_decode($text, $associative, $depth, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
        } catch (\Throwable $e) {
            throw CastException::create($this->value, 'decoded JSON', $e->getMessage());
        }
    }

    /**
     * @throws CastException when the value cannot be converted to a string
     */
    public function base64Encode(): string
    {
        return base64_encode($this->castString());
    }

    /**
     * Strictly decodes Base64; surrounding whitespace is ignored.
     *
     * @throws CastException when the value is not valid Base64
     */
    public function base64Decode(): string
    {
        $text = trim($this->castString());

        if (!self::isBase64String($text)) {
            throw CastException::create($this->value, 'decoded Base64', 'invalid Base64 input');
        }

        return (string) base64_decode($text, true);
    }

    /**
     * URL- and filename-safe Base64 (RFC 4648 §5) without padding.
     *
     * @throws CastException when the value cannot be converted to a string
     */
    public function base64UrlEncode(): string
    {
        return rtrim(strtr(base64_encode($this->castString()), '+/', '-_'), '=');
    }

    /**
     * Decodes URL-safe Base64, with or without padding.
     *
     * @throws CastException when the value is not valid URL-safe Base64
     */
    public function base64UrlDecode(): string
    {
        $text = trim($this->castString());

        if (preg_match('/^[A-Za-z0-9_-]*={0,2}$/', $text) !== 1) {
            throw CastException::create($this->value, 'decoded Base64URL', 'invalid Base64URL input');
        }

        $text = strtr(rtrim($text, '='), '-_', '+/');
        $text = str_pad($text, \strlen($text) + (4 - \strlen($text) % 4) % 4, '=');

        if (!self::isBase64String($text)) {
            throw CastException::create($this->value, 'decoded Base64URL', 'invalid Base64URL input');
        }

        return (string) base64_decode($text, true);
    }

    // ------------------------------------------------------------------
    // SQL, shell, regex
    // ------------------------------------------------------------------

    /**
     * Escapes a string for use inside a quoted SQL string literal, using the connection's own escaping.
     * Prefer prepared statements, or {@see quote()}, which also adds the quotes.
     *
     * @param mixed $connection a mysqli, PgSql\Connection or PDO connection
     *
     * @throws CastException when the value cannot be converted to a string
     * @throws EscapeException when the connection is unsupported or escaping fails
     */
    public function escape(mixed $connection): string
    {
        $text = $this->castString();

        return self::escaping(static function () use ($connection, $text): string {
            if ($connection instanceof \mysqli) {
                return mysqli_real_escape_string($connection, $text);
            }

            if ($connection instanceof \PgSql\Connection) {
                return pg_escape_string($connection, $text);
            }

            if ($connection instanceof \PDO) {
                $quoted = $connection->quote($text);

                if (!\is_string($quoted) || preg_match("/^'(.*)'$/s", $quoted, $match) !== 1) {
                    throw new EscapeException('The PDO driver cannot escape this value without quoting it; use quote() instead.');
                }

                return $match[1];
            }

            throw new EscapeException(\sprintf('Unsupported database connection of type %s; expected mysqli, PgSql\Connection or PDO.', get_debug_type($connection)));
        });
    }

    /**
     * Returns a complete SQL literal: NULL for null, a number for int/float, TRUE/FALSE (PostgreSQL)
     * or 1/0 for booleans, and a quoted, escaped string for anything else.
     * Strings require a live connection; a {@see Database} value is enough for everything else.
     *
     * @param mixed $connection a mysqli, PgSql\Connection or PDO connection, or a {@see Database}
     *
     * @throws CastException when the value cannot be converted to a string
     * @throws EscapeException when the connection is unsupported, the float is not finite, or quoting fails
     */
    public function quote(mixed $connection): string
    {
        $value = $this->value;
        $database = Database::detect($connection);

        if ($value === null) {
            return 'NULL';
        }

        if (\is_bool($value)) {
            return match ($database) {
                Database::PostgreSql => $value ? 'TRUE' : 'FALSE',
                default => $value ? '1' : '0',
            };
        }

        if (\is_int($value)) {
            return (string) $value;
        }

        if (\is_float($value)) {
            return is_finite($value) ? (string) $value : throw new EscapeException('Only finite floats can be quoted as SQL literals.');
        }

        $text = $this->castString();

        return self::escaping(static function () use ($connection, $text): string {
            $quoted = match (true) {
                $connection instanceof \mysqli => "'" . mysqli_real_escape_string($connection, $text) . "'",
                $connection instanceof \PgSql\Connection => pg_escape_literal($connection, $text),
                $connection instanceof \PDO => $connection->quote($text),
                default => throw new EscapeException('Quoting a string requires a live mysqli, PgSql\Connection or PDO connection.'),
            };

            return \is_string($quoted) ? $quoted : throw new EscapeException('The database connection failed to quote the value.');
        });
    }

    /**
     * Quotes an SQL identifier (table, column, ...): `name` for MySQL, "name" for PostgreSQL and SQLite.
     * With $qualified, dots separate parts that are quoted individually ("schema.table" → "schema"."table").
     *
     * @param mixed $connection a mysqli, PgSql\Connection or PDO connection, or a {@see Database}
     *
     * @throws CastException when the value cannot be converted to a string
     * @throws EscapeException when the identifier is empty, contains NUL bytes or the connection is unsupported
     */
    public function identifier(mixed $connection, bool $qualified = false): string
    {
        $database = Database::detect($connection);
        $text = $this->castString();
        $parts = $qualified ? explode('.', $text) : [$text];

        return implode('.', array_map($database->quoteIdentifier(...), $parts));
    }

    /**
     * Escapes the LIKE wildcards % and _ (and the escape character itself), so the value matches literally.
     * The result still needs normal string escaping or a bound parameter, and an `ESCAPE` clause when the
     * database's default escape character differs.
     *
     * @throws CastException when the value cannot be converted to a string
     * @throws EscapeException when $escape is not exactly one character
     */
    public function like(string $escape = '\\'): string
    {
        if (\strlen($escape) !== 1) {
            throw new EscapeException('The LIKE escape character must be exactly one byte.');
        }

        return strtr($this->castString(), [$escape => $escape . $escape, '%' => $escape . '%', '_' => $escape . '_']);
    }

    /**
     * Escapes a single shell argument (escapeshellarg).
     *
     * Arguments longer than 131071 bytes are rejected on every platform: Linux cannot pass a longer
     * single argument to a program (MAX_ARG_STRLEN), and PHP's own limit differs between builds.
     *
     * @throws CastException when the value cannot be converted to a string
     * @throws EscapeException when the value contains a NUL byte or is too long
     */
    public function shellArg(): string
    {
        $text = $this->castString();

        if (str_contains($text, "\0")) {
            throw new EscapeException('Shell arguments cannot contain NUL bytes.');
        }

        if (\strlen($text) > self::MAX_SHELL_ARG_BYTES) {
            throw new EscapeException(\sprintf('Shell arguments cannot be longer than %d bytes.', self::MAX_SHELL_ARG_BYTES));
        }

        try {
            return escapeshellarg($text);
        } catch (\ValueError $e) {
            throw new EscapeException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Escapes regular-expression metacharacters (preg_quote), including the given delimiter.
     *
     * @throws CastException when the value cannot be converted to a string
     */
    public function regex(?string $delimiter = '/'): string
    {
        return preg_quote($this->castString(), $delimiter);
    }

    // ------------------------------------------------------------------
    // Sanitizing
    // ------------------------------------------------------------------

    /**
     * Trims Unicode whitespace, or the given characters, from both ends.
     *
     * @throws CastException when the value cannot be converted to a string
     */
    public function trim(?string $characters = null): string
    {
        return mb_trim($this->utf8(), $characters, 'UTF-8');
    }

    /**
     * Trims the value and collapses every run of whitespace to a single space.
     *
     * @throws CastException when the value cannot be converted to a string
     */
    public function squish(): string
    {
        return self::replace('/\s+/u', mb_trim($this->utf8(), null, 'UTF-8'), static fn(): string => ' ');
    }

    /**
     * Strips HTML and PHP tags. The output is not safe for HTML on its own; escape it with {@see html()}.
     *
     * @param list<string>|string|null $allowedTags
     *
     * @throws CastException when the value cannot be converted to a string
     */
    public function stripTags(array|string|null $allowedTags = null): string
    {
        return strip_tags($this->castString(), $allowedTags);
    }

    public function lower(): string
    {
        return mb_strtolower($this->utf8(), 'UTF-8');
    }

    public function upper(): string
    {
        return mb_strtoupper($this->utf8(), 'UTF-8');
    }

    /**
     * Keeps only the ASCII digits 0-9.
     *
     * @throws CastException when the value cannot be converted to a string
     */
    public function digits(): string
    {
        return self::replace('/[^0-9]+/', $this->castString(), static fn(): string => '');
    }

    /**
     * Keeps only letters (any script).
     *
     * @throws CastException when the value cannot be converted to a string
     */
    public function alpha(): string
    {
        return self::replace('/[^\p{L}\p{M}]+/u', $this->utf8(), static fn(): string => '');
    }

    /**
     * Keeps only letters (any script) and digits.
     *
     * @throws CastException when the value cannot be converted to a string
     */
    public function alnum(): string
    {
        return self::replace('/[^\p{L}\p{M}\p{N}]+/u', $this->utf8(), static fn(): string => '');
    }

    /**
     * Removes every character that is not allowed in an e-mail address (FILTER_SANITIZE_EMAIL).
     * This does not validate the address; use {@see isEmail()} for that.
     *
     * @throws CastException when the value cannot be converted to a string
     */
    public function email(): string
    {
        return (string) filter_var($this->castString(), \FILTER_SANITIZE_EMAIL);
    }

    /**
     * Removes every character that is not allowed in a URL (FILTER_SANITIZE_URL).
     * This does not validate the URL; use {@see isUrl()} for that.
     *
     * @throws CastException when the value cannot be converted to a string
     */
    public function url(): string
    {
        return (string) filter_var($this->castString(), \FILTER_SANITIZE_URL);
    }

    /**
     * Builds a lowercase ASCII slug: "Blåbær Syltetøy!" → "blabaer-syltetoy".
     * Uses ext-intl, when installed, to transliterate other scripts.
     *
     * @throws CastException when the value cannot be converted to a string
     */
    public function slug(string $separator = '-'): string
    {
        $text = strtr(mb_strtolower($this->utf8(), 'UTF-8'), self::TRANSLITERATION);

        if (class_exists(\Transliterator::class)) {
            $transliterated = \Transliterator::create('Any-Latin; Latin-ASCII; Lower()')?->transliterate($text);
            $text = \is_string($transliterated) ? $transliterated : $text;
        }

        $text = self::replace('/[^a-z0-9]+/', $text, static fn(): string => $separator);

        return $separator === '' ? $text : self::replace('/^(?:' . preg_quote($separator, '/') . ')+|(?:' . preg_quote($separator, '/') . ')+$/', $text, static fn(): string => '');
    }

    /**
     * Makes a single, safe file name: no path separators, control or reserved characters, no leading
     * dots, no Windows device names, and at most 255 bytes (the extension is kept when possible).
     * Returns an empty string when nothing usable remains.
     *
     * @throws CastException when the value cannot be converted to a string
     */
    public function filename(string $replacement = '_'): string
    {
        $replacement = self::replace('/[\x00-\x1F\x7F<>:"\/\\\\|?*]/', $replacement, static fn(): string => '');
        $name = self::replace('/[\x00-\x1F\x7F<>:"\/\\\\|?*]+/u', $this->utf8(), static fn(): string => $replacement);
        $name = trim($name, " .\t\n\r\v");

        if (preg_match('/^(?:CON|PRN|AUX|NUL|COM[0-9¹²³]|LPT[0-9¹²³])(?:\..*)?$/iu', $name) === 1) {
            $name = '_' . $name;
        }

        if (\strlen($name) <= 255) {
            return $name;
        }

        $dot = strrpos($name, '.');
        $extension = $dot !== false && \strlen($name) - $dot <= 16 ? substr($name, $dot) : '';

        return mb_strcut($name, 0, 255 - \strlen($extension), 'UTF-8') . $extension;
    }

    // ------------------------------------------------------------------
    // Validation (never throws)
    // ------------------------------------------------------------------

    public function isNull(): bool
    {
        return $this->value === null;
    }

    public function isBool(): bool
    {
        return \is_bool($this->value);
    }

    public function isInt(): bool
    {
        return \is_int($this->value);
    }

    public function isFloat(): bool
    {
        return \is_float($this->value);
    }

    public function isString(): bool
    {
        return \is_string($this->value);
    }

    public function isArray(): bool
    {
        return \is_array($this->value);
    }

    public function isList(): bool
    {
        return \is_array($this->value) && array_is_list($this->value);
    }

    public function isObject(): bool
    {
        return \is_object($this->value);
    }

    public function isResource(): bool
    {
        return $this->type() === Type::Resource;
    }

    public function isScalar(): bool
    {
        return \is_scalar($this->value);
    }

    public function isCallable(): bool
    {
        return \is_callable($this->value);
    }

    public function isIterable(): bool
    {
        return is_iterable($this->value);
    }

    public function isCountable(): bool
    {
        return is_countable($this->value);
    }

    /**
     * True when {@see string()} would succeed.
     */
    public function isStringable(): bool
    {
        return $this->tryString() !== null;
    }

    /**
     * True for int, float and numeric strings ("12", "1.5", "1e3", " 42 ").
     */
    public function isNumeric(): bool
    {
        return is_numeric($this->value);
    }

    /**
     * True for ints and for strings holding a whole number in the int range ("42", "-7", "+3").
     */
    public function isInteger(): bool
    {
        if (\is_int($this->value)) {
            return true;
        }

        $text = $this->text();

        return $text !== null
            && preg_match('/^[+-]?[0-9]+$/D', $text) === 1
            && \is_int(self::parseNumber($text));
    }

    /**
     * True for null, "", [] and empty Countable objects. Unlike PHP's empty(), 0, "0" and false are not empty.
     */
    public function isEmpty(): bool
    {
        $value = $this->value;

        return $value === null || $value === '' || $value === [] || ($value instanceof \Countable && \count($value) === 0);
    }

    /**
     * Like {@see isEmpty()}, but whitespace-only strings also count as blank.
     */
    public function isBlank(): bool
    {
        return $this->isEmpty() || (\is_string($this->value) && mb_trim($this->utf8(), null, 'UTF-8') === '');
    }

    public function isEmail(): bool
    {
        $text = $this->text();

        return $text !== null && filter_var($text, \FILTER_VALIDATE_EMAIL, \FILTER_FLAG_EMAIL_UNICODE) !== false;
    }

    /**
     * Validates an absolute URL with the WHATWG URL parser (so internationalized domains are accepted)
     * and rejects any input that needs error correction. Only http and https are allowed by default;
     * pass other schemes, or [] for any scheme.
     *
     * @param list<string> $schemes
     */
    public function isUrl(array $schemes = ['http', 'https']): bool
    {
        $text = $this->text();

        if ($text === null || $text === '') {
            return false;
        }

        $errors = [];
        $url = \Uri\WhatWg\Url::parse($text, null, $errors);

        if ($url === null || $errors !== []) {
            return false;
        }

        return $schemes === [] || \in_array($url->getScheme(), array_map(strtolower(...), $schemes), true);
    }

    public function isIp(): bool
    {
        return $this->filterText(\FILTER_VALIDATE_IP);
    }

    public function isIpv4(): bool
    {
        return $this->filterText(\FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4);
    }

    public function isIpv6(): bool
    {
        return $this->filterText(\FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6);
    }

    /**
     * True for public IP addresses (not private or reserved ranges).
     */
    public function isPublicIp(): bool
    {
        return $this->filterText(\FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE);
    }

    public function isMac(): bool
    {
        return $this->filterText(\FILTER_VALIDATE_MAC);
    }

    /**
     * Validates a host name such as "example.com" or "localhost" (ASCII, no scheme or path).
     */
    public function isDomain(): bool
    {
        return $this->filterText(\FILTER_VALIDATE_DOMAIN, \FILTER_FLAG_HOSTNAME);
    }

    /**
     * Validates an RFC 9562 UUID (any version, including the nil and max UUIDs), case-insensitive.
     */
    public function isUuid(): bool
    {
        return $this->matches('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD');
    }

    public function isJson(): bool
    {
        $text = $this->text();

        return $text !== null && json_validate($text);
    }

    /**
     * True for strict, correctly padded standard Base64.
     */
    public function isBase64(): bool
    {
        $text = $this->text();

        return $text !== null && self::isBase64String($text);
    }

    /**
     * True for non-empty strings of letters only (any script).
     */
    public function isAlpha(): bool
    {
        return $this->matches('/^[\p{L}\p{M}]+$/uD');
    }

    /**
     * True for non-empty strings of letters (any script) and digits.
     */
    public function isAlnum(): bool
    {
        return $this->matches('/^[\p{L}\p{M}\p{N}]+$/uD');
    }

    /**
     * True for non-empty strings of ASCII digits only (non-negative ints count).
     */
    public function isDigits(): bool
    {
        return $this->matches('/^[0-9]+$/D');
    }

    public function isHex(): bool
    {
        return $this->matches('/^[0-9a-f]+$/iD');
    }

    public function isUtf8(): bool
    {
        $text = $this->text();

        return $text !== null && mb_check_encoding($text, 'UTF-8');
    }

    /**
     * True when the value is a date string in exactly the given format (see DateTimeInterface::format()),
     * or any DateTimeInterface instance.
     */
    public function isDate(string $format = 'Y-m-d'): bool
    {
        if ($this->value instanceof \DateTimeInterface) {
            return true;
        }

        $text = $this->text();

        if ($text === null || $text === '' || str_contains($text, "\0") || str_contains($format, "\0")) {
            return false;
        }

        $date = \DateTimeImmutable::createFromFormat('!' . $format, $text);

        return $date !== false && $date->format($format) === $text;
    }

    /**
     * True when the value is a string matching the regular expression. An invalid pattern returns false.
     */
    public function matches(string $pattern): bool
    {
        $text = $this->text();

        if ($text === null) {
            return false;
        }

        try {
            return @preg_match($pattern, $text) === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function castString(): string
    {
        $value = $this->value;

        return match (true) {
            \is_string($value) => $value,
            $value === null, $value === false => '',
            \is_bool($value) => '1',
            \is_float($value) && is_nan($value) => 'NAN',
            \is_int($value), \is_float($value) => (string) $value,
            $value instanceof \BackedEnum => (string) $value->value,
            $value instanceof \UnitEnum => $value->name,
            $value instanceof \DateTimeInterface => $value->format(\DateTimeInterface::ATOM),
            $value instanceof \Stringable => self::stringify($value, 'string'),
            default => throw CastException::create($value, 'string'),
        };
    }

    private function castInt(): int
    {
        $number = $this->castNumber('int');

        if (\is_int($number)) {
            return $number;
        }

        return match (true) {
            is_nan($number) => throw CastException::create($this->value, 'int', 'NaN has no integer value'),
            $number >= self::INT_BOUND => \PHP_INT_MAX,
            $number <= -self::INT_BOUND => \PHP_INT_MIN,
            default => (int) $number,
        };
    }

    private function castFloat(): float
    {
        return (float) $this->castNumber('float');
    }

    private function castNumber(string $target): int|float
    {
        $value = $this->value;

        return match (true) {
            \is_int($value), \is_float($value) => $value,
            $value === null => 0,
            \is_bool($value) => (int) $value,
            \is_string($value) => self::parseNumber($value),
            \is_array($value) => $value === [] ? 0 : 1,
            $value instanceof \BackedEnum => \is_int($value->value) ? $value->value : self::parseNumber($value->value),
            $value instanceof \DateTimeInterface => $target === 'float' ? (float) $value->format('U.u') : $value->getTimestamp(),
            $value instanceof \Stringable => self::parseNumber(self::stringify($value, $target)),
            default => throw CastException::create($value, $target),
        };
    }

    /**
     * @return array<mixed>
     */
    private function castArray(): array
    {
        $value = $this->value;

        if (\is_array($value)) {
            return $value;
        }

        if ($value instanceof \Traversable) {
            try {
                return iterator_to_array($value, true);
            } catch (\Throwable $e) {
                throw CastException::create($value, 'array', $e->getMessage());
            }
        }

        return match (true) {
            $value === null => [],
            $value instanceof \UnitEnum, $value instanceof \DateTimeInterface, $value instanceof \Closure => [$value],
            \is_object($value) => get_object_vars($value),
            default => [$value],
        };
    }

    /**
     * Converts to a string with any invalid UTF-8 sequence replaced by U+FFFD.
     */
    private function utf8(): string
    {
        $text = $this->castString();

        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        $previous = mb_substitute_character();
        mb_substitute_character(0xFFFD);

        try {
            return mb_scrub($text, 'UTF-8');
        } finally {
            mb_substitute_character($previous);
        }
    }

    /**
     * The value as text for validators: strings, ints and Stringable objects only.
     */
    private function text(): ?string
    {
        $value = $this->value;

        return match (true) {
            \is_string($value) => $value,
            \is_int($value) => (string) $value,
            $value instanceof \Stringable => $this->tryString(),
            default => null,
        };
    }

    private function filterText(int $filter, int $flags = 0): bool
    {
        $text = $this->text();

        return $text !== null && filter_var($text, $filter, $flags) !== false;
    }

    /**
     * @template T
     *
     * @param \Closure(): T $cast
     * @param T|null $default
     *
     * @return T
     */
    private function orDefault(\Closure $cast, mixed $default): mixed
    {
        try {
            return $cast();
        } catch (CastException $e) {
            return $default ?? throw $e;
        }
    }

    /**
     * @template T
     *
     * @param \Closure(): T $cast
     *
     * @return T|null
     */
    private function orNull(\Closure $cast): mixed
    {
        try {
            return $cast();
        } catch (CastException) {
            return null;
        }
    }

    private static function stringify(\Stringable $value, string $target, bool $truthyOnFailure = false): string
    {
        try {
            return $value->__toString();
        } catch (\Throwable $e) {
            return $truthyOnFailure ? '1' : throw CastException::create($value, $target, $e->getMessage());
        }
    }

    private static function parseNumber(string $text): int|float
    {
        if (is_numeric($text)) {
            return $text + 0;
        }

        if (preg_match(self::NUMBER_PREFIX, $text, $match) === 1) {
            $prefix = $match[0];

            return is_numeric($prefix) ? $prefix + 0 : 0;
        }

        return 0;
    }

    private static function parseBool(string $text): bool
    {
        return filter_var($text, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? $text !== '';
    }

    private static function isBase64String(string $text): bool
    {
        return preg_match('/^(?:[A-Za-z0-9+\/]{4})*(?:[A-Za-z0-9+\/]{2}==|[A-Za-z0-9+\/]{3}=)?$/D', $text) === 1;
    }

    /**
     * preg_replace_callback() that never returns null.
     *
     * @param callable(string): string $replace
     */
    private static function replace(string $pattern, string $subject, callable $replace): string
    {
        return preg_replace_callback($pattern, static fn(array $match): string => $replace($match[0]), $subject)
            ?? throw new CastException(\sprintf('Text processing failed: %s.', preg_last_error_msg()));
    }

    /**
     * Runs a native function and converts any error, ValueError or PHP warning into a CastException.
     *
     * @param \Closure(): string $operation
     */
    private static function guard(\Closure $operation): string
    {
        set_error_handler(static function (int $severity, string $message): never {
            throw new CastException($message);
        });

        try {
            return $operation();
        } catch (CastException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new CastException($e->getMessage(), 0, $e);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Runs a database escaping operation and converts any failure into an EscapeException.
     *
     * @param \Closure(): string $operation
     */
    private static function escaping(\Closure $operation): string
    {
        try {
            return $operation();
        } catch (EscapeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new EscapeException('Escaping failed: ' . $e->getMessage(), 0, $e);
        }
    }
}
