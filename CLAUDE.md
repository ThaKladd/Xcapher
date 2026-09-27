# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Xcapher (`xcapher/xcapher`) is a Composer library for PHP ≥ 8.5 with no runtime dependencies. It escapes, casts, sanitizes and validates any value through a fluent API: `x($value)->int()`, `x($value)->html()`, `x($value)->isEmail()`. The README is the user-facing reference and lists every public method; update it whenever the public API changes, together with `CHANGELOG.md`.

## Commands

```bash
composer check                          # lint + analyse + test (what CI runs)
composer test                           # PHPUnit 13
composer analyse                        # PHPStan level max + strict rules + phpunit extension
composer lint                           # PHP-CS-Fixer dry run (PER-CS 3.0, PHP 8.5 migration)
composer fix                            # apply coding standard
vendor/bin/phpunit --filter testSlug    # single test (or a class name, e.g. --filter SqlTest)
php examples/index.php                  # runnable examples
```

PHPUnit is configured strictly (`phpunit.xml.dist`):
- Tests fail on any warning, notice or deprecation.
- Every test class must declare `#[CoversClass]` or `#[CoversNothing]`.
- Tests run in random order.

PHPStan guidance: fix the underlying types rather than adding ignores or `@var` overrides.

## Architecture

- `src/Xcapher.php` holds nearly all the logic. It is a `final readonly` class wrapping one `mixed` value, and its public methods are grouped by section comments: casting, HTML/JS/CSS, URL/JSON/Base64, SQL/shell/regex, sanitizing and validation.
- `src/Type.php` and `src/Database.php` are enums. `Database::detect()` maps a mysqli, PgSql or PDO connection to a SQL dialect, and `Database::quoteIdentifier()` quotes identifiers for that dialect.
- `src/Exception/` contains the `XcapherException` marker interface, implemented by `CastException` (conversion failures) and `EscapeException` (escaping failures).
- `src/functions.php` defines `Xcapher\x()`. `src/helpers.php` defines the global `x()`, guarded by `function_exists`. Both are loaded through `autoload.files`.

### The robustness contract

The central design rule: **every public method returns its declared type or throws an `XcapherException`**. It must never emit a PHP warning, notice or deprecation, and never leak a `TypeError` or `ValueError`. `tests/RobustnessTest.php` enforces this. It uses reflection to call every public method, including new ones, against more than 40 edge-case values, with an error handler that turns warnings into failures. When adding a method:
- If it takes required parameters, add representative arguments to `$withArguments` in `RobustnessTest::calls()`, or the sweep only calls its zero-argument form.
- Wrap native functions that can warn (for example `htmlentities` with a bad charset) in `self::guard()`, which turns warnings and errors into `CastException`. Database calls go through `self::escaping()`, which throws `EscapeException`.
- Watch for PHP 8.5 behavior: coercing `NAN` to string, bool or object emits a warning, and so does casting an out-of-range float to int. That is why `castString()`, `bool()`, `object()` and `castInt()` handle these cases explicitly.

### Internal conventions in `Xcapher`

- String-producing methods get their input from `castString()`, so they accept anything with a string form. Methods that work on characters use `utf8()`, which is `castString()` plus replacing invalid UTF-8 with U+FFFD.
- Casts follow the pattern `public function int(?int $default = null)` / `tryInt()`, built from a private `castX()` through `orDefault()` and `orNull()`.
- Validators (`is*`) never throw. Text validators read the value through `text()`, which accepts strings, ints and `Stringable` objects.
- Use `self::replace()` instead of calling `preg_replace_callback` directly; it never returns null.

## Environment

The host has no PHP installed. Run everything through DDEV (project `xcapher`, PHP 8.5, which includes `intl`, `mysqli` and `pgsql`), for example `ddev composer check` or `ddev exec vendor/bin/phpunit --filter SqlTest`.

Keep tests independent of the PHP build and the OS. PHP's own limits differ between builds; `escapeshellarg()` is one example, which is why `shellArg()` enforces a fixed maximum length itself.

The SQL tests use in-memory SQLite through PDO. The mysqli and PgSql code paths in `escape()`/`quote()` have no tests against a live database.
