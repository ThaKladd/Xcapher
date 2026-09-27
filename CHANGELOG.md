# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.0.0] - 2026-09-27

The 1.0 rewrite. See "Upgrading from 0.x" in the README.

### Added

- `Xcapher` namespace with PSR-4 autoloading, the `Xcapher\x()` helper, and `Xcapher::from()`.
- `Type` and `Database` enums.
- Casting: `default` arguments, `try*()` variants, `list()`, `map()`, and a generic `to(Type)`.
  Enums, dates and `Stringable` objects are supported.
- Escaping: `html()`, `htmlAttr()`, `xml()`, `js()`, `jsValue()`, `css()`, `rawUrlEncode()`/`rawUrlDecode()`,
  `query()`, `json()`/`jsonDecode()`, Base64 and Base64URL, `quote()`, `identifier()`, `like()`,
  `shellArg()` and `regex()`.
- Enums, objects, dates and allowlists: `enum()`, `instanceOf()`, `date()`, `oneOf()` and `closure()`, each
  with a `try*()` variant, plus `isEnum()`, `isEnumValue()`, `isInstanceOf()`, `isOneOf()` and `isStream()`.
- Introspection: `debugType()` and `resourceType()`.
- Arrays: `get()`/`has()` for dot-path access, `only()`/`except()` key allowlists, typed lists (`ints()`,
  `floats()`, `strings()`, `bools()`, `enums()`), `flatten()`, `dot()`, `depth()`, `count()`, `array(deep: true)`,
  and the validators `isAssoc()`, `hasKeys()`, `every()` and `some()`.
- Escaping: `htmlAttributes()`, plus `csv()` and `csvField()` with CSV-injection protection.
- Sanitizing: `trim()`, `squish()`, `stripTags()`, `lower()`, `upper()`, `digits()`, `alpha()`, `alnum()`,
  `slug()` and `filename()`.
- More than 30 validators, including `isEmail()`, `isUrl()` (WHATWG parser), `isIp()`, `isUuid()`,
  `isJson()`, `isDate()` and `matches()`.
- The `XcapherException` interface, with `CastException` and `EscapeException`.
- PDO support for SQL escaping.
- A PHPUnit test suite, PHPStan (level max, strict rules), PHP-CS-Fixer (PER-CS 3.0) and GitHub Actions CI.
- Tests that run the SQL helpers against real MariaDB/MySQL and PostgreSQL servers (mysqli, ext-pgsql and PDO).
- Infection mutation testing (minimum 90% MSI), code coverage reports and Dependabot.
- `SECURITY.md`, `CONTRIBUTING.md` and a DDEV configuration.

### Changed

- PHP 8.5 is now required.
- `escape()`, `urlEncode()`, `urlDecode()` and the HTML entity methods accept any value that has a string
  form.
- `array()` on objects returns their public properties.
- `bool()` reads strings such as `"false"`, `"off"` and `"no"` as `false`.
- `shellArg()` rejects arguments longer than 131071 bytes (the Linux per-argument limit) on every PHP build.

### Removed

- `DataType`, `VariableType`, `DataBaseType`, `Error\XcapherError`, `setDb()` and `getDb()`.

### Fixed

- `escape()` threw the wrong message: "No database connection." when the value was not scalar, and the reverse.
- `urlEncode()` and `urlDecode()` rejected values that were not complete, valid URLs.
- Type detection could never report callables or iterables. Those checks are now `isCallable()` and
  `isIterable()`.
- Methods now throw a typed exception instead of emitting PHP warnings, `TypeError` or `ValueError`. This
  covers, for example, NaN in PHP 8.5, arrays converted to strings and floats outside the int range.

[Unreleased]: https://github.com/ThaKladd/Xcapher/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/ThaKladd/Xcapher/releases/tag/v1.0.0
