# Security policy

Xcapher is an escaping library, so a flaw in it can put applications that rely on it at risk. Security
reports are very welcome and are treated as the highest priority.

## Supported versions

| Version | Supported |
| --- | --- |
| 1.x | Yes |
| 0.x | No. Please upgrade; see "Upgrading from 0.x" in the README. |

## Reporting a vulnerability

**Please do not open a public issue, pull request or discussion for security problems.**

Report privately through GitHub instead:
[Report a vulnerability](https://github.com/ThaKladd/Xcapher/security/advisories/new).

Please include:

- the affected method(s) and Xcapher version
- the input that triggers the problem and the output you got
- the context the output is used in (HTML attribute, JavaScript string, SQL literal, shell argument, …)
- the PHP version, and the database or driver if it is SQL-related

## What counts as a vulnerability

For example:

- output that breaks out of the context it was escaped for, such as `htmlAttr()` output that ends an attribute,
  `js()` output that ends a string or script, or `quote()`/`escape()` output that ends an SQL literal
- `identifier()`, `like()`, `shellArg()` or `filename()` output that allows injection or path traversal
- validators (`isUrl()`, `isEmail()`, …) accepting input that the documentation says they reject, in a way
  that can be exploited
- a method emitting PHP warnings or uncaught errors in a way that leaks information or can be triggered by
  user input

Unexpected casting results or documentation mistakes are ordinary bugs; please report those as normal issues.

## Process

1. You report the issue privately.
2. The report is confirmed and a fix is prepared in a private security advisory.
3. A patched release is published, and the advisory is made public with credit to you (unless you prefer
   otherwise).
