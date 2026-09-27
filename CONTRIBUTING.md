# Contributing

Thanks for helping improve Xcapher! Bug reports, fixes, new escaping contexts and better tests are all welcome.
For security problems, follow [SECURITY.md](SECURITY.md) instead of opening an issue.

## Setup

With [DDEV](https://ddev.com) (PHP 8.5, MariaDB, pcov and the database credentials are configured in `.ddev/`):

```bash
ddev start
ddev composer install
```

Without DDEV, you need PHP 8.5 with `mbstring`, `pdo_sqlite` and, for coverage and mutation testing, `pcov` or
Xdebug. Then run `composer install`. Drop the `ddev` prefix from the commands below.

## Before opening a pull request

```bash
ddev composer check       # coding standard, PHPStan (level max) and PHPUnit — must pass
ddev composer mutation    # Infection mutation testing — must stay at or above 90% MSI
ddev composer fix         # fixes most coding standard violations automatically
```

- **Tests:** add tests for every change. Use a data provider when you are checking many inputs.
  Run a single test with `ddev exec vendor/bin/phpunit --filter testSlug`.
- **Robustness:** every public method must return its declared type or throw an `XcapherException`. It must
  never emit a warning or leak a `TypeError` or `ValueError`. `tests/RobustnessTest.php` calls every public
  method with more than 40 awkward values. If your new method takes required arguments, add representative
  ones to `RobustnessTest::calls()`.
- **Static analysis:** fix the types instead of adding `@phpstan-ignore` or baseline entries.
- **Docs:** update the README for every public API change and add an entry under `[Unreleased]` in
  `CHANGELOG.md`.

## Database tests

`tests/Live/` runs the SQL helpers against real servers. The tests skip themselves unless these environment
variables are set:

| Database | Variables |
| --- | --- |
| MySQL / MariaDB | `XCAPHER_MYSQL_HOST`, `_PORT`, `_USER`, `_PASSWORD`, `_DATABASE` |
| PostgreSQL | `XCAPHER_PGSQL_HOST`, `_PORT`, `_USER`, `_PASSWORD`, `_DATABASE` |

DDEV sets the MySQL variables for its MariaDB automatically. To run the PostgreSQL tests locally, start a
temporary server on DDEV's network:

```bash
docker run -d --rm --name xcapher-pg --network ddev_default -e POSTGRES_PASSWORD=pg postgres:17
ddev exec 'XCAPHER_PGSQL_HOST=xcapher-pg XCAPHER_PGSQL_PASSWORD=pg vendor/bin/phpunit tests/Live'
docker stop xcapher-pg
```

CI runs all tests against MariaDB and PostgreSQL with `--fail-on-skipped`.

## Commit messages and pull requests

Keep pull requests focused on one change and explain why it is needed. Link the issue it fixes, if there is one.
