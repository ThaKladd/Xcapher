<?php

declare(strict_types=1);

/*
 * Run from the project root with `php examples/index.php`, serve it with `php -S localhost:8000 -t examples`,
 * or open it with `ddev launch`.
 */

use Xcapher\Database;
use Xcapher\Xcapher;

use function Xcapher\x;

require __DIR__ . '/../vendor/autoload.php';

enum Status: string
{
    case Active = 'active';
    case Banned = 'banned';
}

/**
 * Each example is the code shown on the page and a closure that runs exactly that code.
 *
 * @var array<string, array{string, list<array{string, Closure(): mixed}>}> $sections
 */
$sections = [
    'casting' => ['Casting', [
        ["x('12test')->int()", static fn(): int => x('12test')->int()],
        ['x([1, 2, 3])->int()', static fn(): int => x([1, 2, 3])->int()],
        ['x(1e30)->int()', static fn(): int => x(1e30)->int()],
        ["x('  YES ')->bool()", static fn(): bool => x('  YES ')->bool()],
        ["x('off')->bool()", static fn(): bool => x('off')->bool()],
        ['x(new stdClass())->int(default: 0)', static fn(): int => x(new stdClass())->int(default: 0)],
        ['x(new stdClass())->tryInt()', static fn(): ?int => x(new stdClass())->tryInt()],
        ["x(['a' => '1', 'b' => 'x'])->map(fn (\$v) => \$v->int())", static fn(): array => x(['a' => '1', 'b' => 'x'])->map(static fn(Xcapher $v): int => $v->int())],
    ]],
    'escaping' => ['HTML, JavaScript & CSS', [
        ["x('<b>\"Tom & Jerry\"</b>')->html()", static fn(): string => x('<b>"Tom & Jerry"</b>')->html()],
        ["x('\" onmouseover=\"alert(1)')->htmlAttr()", static fn(): string => x('" onmouseover="alert(1)')->htmlAttr()],
        ["x('</script><script>alert(1)')->js()", static fn(): string => x('</script><script>alert(1)')->js()],
        ["x(['name' => '</script>'])->jsValue()", static fn(): string => x(['name' => '</script>'])->jsValue()],
        ["x('a b;}')->css()", static fn(): string => x('a b;}')->css()],
    ]],
    'encoding' => ['URLs, JSON & Base64', [
        ["x('https://test.com/')->urlEncode()", static fn(): string => x('https://test.com/')->urlEncode()],
        ["x(['q' => 'blå bær', 'page' => 2])->query()", static fn(): string => x(['q' => 'blå bær', 'page' => 2])->query()],
        ["x(['url' => 'https://x.no/', 'n' => 1.0])->json()", static fn(): string => x(['url' => 'https://x.no/', 'n' => 1.0])->json()],
        ["x('hi??>>')->base64UrlEncode()", static fn(): string => x('hi??>>')->base64UrlEncode()],
    ]],
    'objects' => ['Enums, objects & allowlists', [
        ["x('banned')->enum(Status::class)", static fn(): Status => x('banned')->enum(Status::class)],
        ["x('hacker')->enum(Status::class, Status::Active)", static fn(): Status => x('hacker')->enum(Status::class, Status::Active)],
        ["x('id; DROP TABLE users')->oneOf(['id', 'name'], 'id')", static fn(): string => x('id; DROP TABLE users')->oneOf(['id', 'name'], 'id')],
        ["x('5')->oneOf([10, 20, 5])", static fn(): int => x('5')->oneOf([10, 20, 5])],
        ["x('29.02.2024')->date('d.m.Y')", static fn(): DateTimeImmutable => x('29.02.2024')->date('d.m.Y')],
        ["x('2023-02-30')->tryDate()", static fn(): ?DateTimeImmutable => x('2023-02-30')->tryDate()],
        ['x(new ArrayObject())->isInstanceOf(Countable::class)', static fn(): bool => x(new ArrayObject())->isInstanceOf(Countable::class)],
        ["x(fopen('php://memory', 'r'))->debugType()", static fn(): string => x(fopen('php://memory', 'r'))->debugType()],
    ]],
    'arrays' => ['Arrays & CSV', [
        ["x(['user' => ['age' => '42']])->get('user.age')->int()", static fn(): int => x(['user' => ['age' => '42']])->get('user.age')->int()],
        ["x(['user' => []])->get('user.email', 'none')->string()", static fn(): string => x(['user' => []])->get('user.email', 'none')->string()],
        ["x(['name' => 'Eve', 'is_admin' => 1])->only('name', 'email')", static fn(): array => x(['name' => 'Eve', 'is_admin' => 1])->only('name', 'email')],
        ["x(['1', '2', '3x'])->ints()", static fn(): array => x(['1', '2', '3x'])->ints()],
        ["x(['a' => ['b' => [1, 2]]])->dot()", static fn(): array => x(['a' => ['b' => [1, 2]]])->dot()],
        ['x([1, [2, [3, [4]]]])->depth()', static fn(): int => x([1, [2, [3, [4]]]])->depth()],
        ["x(['class' => ['btn', 'active'], 'disabled' => true])->htmlAttributes()", static fn(): string => x(['class' => ['btn', 'active'], 'disabled' => true])->htmlAttributes()],
        ["x(['Eve', '=HYPERLINK(\"http://evil\")'])->csv()", static fn(): string => x(['Eve', '=HYPERLINK("http://evil")'])->csv()],
    ]],
    'sql' => ['SQL & Shell', [
        ["x('order')->identifier(Database::MySql)", static fn(): string => x('order')->identifier(Database::MySql)],
        ["x('public.users')->identifier(Database::PostgreSql, qualified: true)", static fn(): string => x('public.users')->identifier(Database::PostgreSql, qualified: true)],
        ['x(true)->quote(Database::PostgreSql)', static fn(): string => x(true)->quote(Database::PostgreSql)],
        ["x('50%_off')->like()", static fn(): string => x('50%_off')->like()],
        ["x(\"it's; rm -rf /\")->shellArg()", static fn(): string => x("it's; rm -rf /")->shellArg()],
    ]],
    'sanitizing' => ['Sanitizing', [
        ["x('Blåbær Syltetøy!')->slug()", static fn(): string => x('Blåbær Syltetøy!')->slug()],
        ["x('../../etc/passwd')->filename()", static fn(): string => x('../../etc/passwd')->filename()],
        ["x(\"  a \\n\\n  b \")->squish()", static fn(): string => x("  a \n\n  b ")->squish()],
        ["x('+47 123 45 678')->digits()", static fn(): string => x('+47 123 45 678')->digits()],
        ["x('<p>Hello <b>world</b></p>')->stripTags()", static fn(): string => x('<p>Hello <b>world</b></p>')->stripTags()],
    ]],
    'validation' => ['Validation', [
        ["x('bjørn@eksempel.no')->isEmail()", static fn(): bool => x('bjørn@eksempel.no')->isEmail()],
        ["x('https://bücher.de')->isUrl()", static fn(): bool => x('https://bücher.de')->isUrl()],
        ["x('javascript:alert(1)')->isUrl()", static fn(): bool => x('javascript:alert(1)')->isUrl()],
        ["x('2023-02-29')->isDate()", static fn(): bool => x('2023-02-29')->isDate()],
        ["x('0')->isEmpty()", static fn(): bool => x('0')->isEmpty()],
    ]],
    'errors' => ['Errors', [
        ['x([1, 2])->string()', static fn(): string => x([1, 2])->string()],
        ["x('{invalid')->jsonDecode()", static fn(): mixed => x('{invalid')->jsonDecode()],
        ['x(NAN)->int()', static fn(): int => x(\NAN)->int()],
    ]],
];

/**
 * Methods applied to the "Try it" input.
 *
 * @var array<string, Closure(Xcapher): mixed> $playground
 */
$playground = [
    'string()' => static fn(Xcapher $x): string => $x->string(),
    'int()' => static fn(Xcapher $x): int => $x->int(),
    'float()' => static fn(Xcapher $x): float => $x->float(),
    'bool()' => static fn(Xcapher $x): bool => $x->bool(),
    'html()' => static fn(Xcapher $x): string => $x->html(),
    'htmlAttr()' => static fn(Xcapher $x): string => $x->htmlAttr(),
    'js()' => static fn(Xcapher $x): string => $x->js(),
    'urlEncode()' => static fn(Xcapher $x): string => $x->urlEncode(),
    'slug()' => static fn(Xcapher $x): string => $x->slug(),
    'filename()' => static fn(Xcapher $x): string => $x->filename(),
    'squish()' => static fn(Xcapher $x): string => $x->squish(),
    'base64Encode()' => static fn(Xcapher $x): string => $x->base64Encode(),
    'isEmail()' => static fn(Xcapher $x): bool => $x->isEmail(),
    'isUrl()' => static fn(Xcapher $x): bool => $x->isUrl(),
    'isInteger()' => static fn(Xcapher $x): bool => $x->isInteger(),
    'isJson()' => static fn(Xcapher $x): bool => $x->isJson(),
];

/**
 * Runs an example and describes its outcome.
 *
 * @param Closure(): mixed $run
 *
 * @return array{kind: 'true'|'false'|'null'|'error'|'value', text: string}
 */
function outcome(Closure $run): array
{
    try {
        $value = $run();
    } catch (Throwable $e) {
        return ['kind' => 'error', 'text' => new ReflectionClass($e)->getShortName() . ': ' . $e->getMessage()];
    }

    return match (true) {
        $value === true => ['kind' => 'true', 'text' => 'true'],
        $value === false => ['kind' => 'false', 'text' => 'false'],
        $value === null => ['kind' => 'null', 'text' => 'null'],
        $value instanceof UnitEnum => ['kind' => 'value', 'text' => $value::class . '::' . $value->name],
        $value instanceof DateTimeInterface => ['kind' => 'value', 'text' => $value->format('Y-m-d H:i:s P')],
        is_string($value) => ['kind' => 'value', 'text' => "'" . $value . "'"],
        is_array($value) => ['kind' => 'value', 'text' => x($value)->json()],
        default => ['kind' => 'value', 'text' => var_export($value, true)],
    };
}

if (\PHP_SAPI === 'cli') {
    foreach ($sections as [$title, $examples]) {
        echo \PHP_EOL . $title . \PHP_EOL . str_repeat('-', mb_strlen($title)) . \PHP_EOL;

        foreach ($examples as [$code, $run]) {
            echo $code . \PHP_EOL . '    → ' . outcome($run)['text'] . \PHP_EOL;
        }
    }

    return;
}

$defaultInput = 'Blåbær <b>&</b> "syltetøy" 42';
$input = x($_GET['q'] ?? $defaultInput)->tryString() ?? $defaultInput;
$tried = new Xcapher($input);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Xcapher: examples</title>
    <style>
        :root {
            --bg: #f6f5f1;
            --surface: #ffffff;
            --text: #1d1d1f;
            --muted: #6b6b70;
            --line: #e6e4dd;
            --accent: #3d5afe;
            --accent-soft: #eef1ff;
            --code-bg: #f3f2ee;
            --true: #1b7f4b;
            --false: #b3541e;
            --error: #c62828;
            --error-soft: #fdecec;
            --radius: 14px;
            --mono: ui-monospace, "SF Mono", "JetBrains Mono", Menlo, Consolas, monospace;
            color-scheme: light;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #111113;
                --surface: #1a1a1d;
                --text: #ececef;
                --muted: #9a9aa3;
                --line: #2a2a2f;
                --accent: #8c9eff;
                --accent-soft: #1f2340;
                --code-bg: #222226;
                --true: #5fd39a;
                --false: #f0a36e;
                --error: #ff8a80;
                --error-soft: #3a1f1f;
                color-scheme: dark;
            }
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font: 16px/1.55 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        .wrap { max-width: 1040px; margin: 0 auto; padding: 0 20px; }

        header { padding: 72px 0 40px; }

        .brand {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-family: var(--mono);
            font-size: 14px;
            color: var(--accent);
            background: var(--accent-soft);
            padding: 4px 12px;
            border-radius: 999px;
        }

        h1 {
            font-size: clamp(2.4rem, 6vw, 3.6rem);
            line-height: 1.05;
            letter-spacing: -0.03em;
            margin: 18px 0 14px;
        }

        h1 span { color: var(--accent); }

        .lead { font-size: 1.15rem; color: var(--muted); max-width: 640px; margin: 0; }

        .install {
            margin-top: 28px;
            display: inline-block;
            font-family: var(--mono);
            font-size: 14px;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 10px 16px;
        }

        .install::before { content: "$ "; color: var(--muted); }

        nav {
            position: sticky;
            top: 0;
            z-index: 1;
            background: color-mix(in srgb, var(--bg) 85%, transparent);
            backdrop-filter: blur(8px);
            border-bottom: 1px solid var(--line);
        }

        nav .wrap { display: flex; gap: 6px; overflow-x: auto; padding-block: 10px; }

        nav a {
            flex: none;
            color: var(--muted);
            text-decoration: none;
            font-size: 14px;
            padding: 6px 12px;
            border-radius: 8px;
        }

        nav a:hover { color: var(--text); background: var(--surface); }

        section { padding-top: 48px; scroll-margin-top: 56px; }

        h2 { font-size: 1.35rem; letter-spacing: -0.01em; margin: 0 0 16px; }

        .card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            overflow: hidden;
        }

        .row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 20px;
            padding: 14px 20px;
            border-top: 1px solid var(--line);
            align-items: baseline;
        }

        .row:first-child { border-top: 0; }

        code, .result {
            font-family: var(--mono);
            font-size: 13.5px;
            overflow-wrap: anywhere;
            white-space: pre-wrap;
        }

        .row code { color: var(--text); }

        .result {
            justify-self: start;
            background: var(--code-bg);
            border-radius: 8px;
            padding: 3px 10px;
        }

        .result::before { content: "→ "; color: var(--muted); }
        .result.true { color: var(--true); }
        .result.false { color: var(--false); }
        .result.null { color: var(--muted); }
        .result.error { color: var(--error); background: var(--error-soft); }

        .try form { display: flex; gap: 10px; padding: 20px; border-bottom: 1px solid var(--line); }

        .try input {
            flex: 1;
            min-width: 0;
            font: 15px var(--mono);
            color: var(--text);
            background: var(--bg);
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 12px 14px;
            outline: none;
        }

        .try input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-soft); }

        .try button {
            font: 600 15px system-ui, sans-serif;
            color: #fff;
            background: var(--accent);
            border: 0;
            border-radius: 10px;
            padding: 0 22px;
            cursor: pointer;
        }

        .try button:hover { filter: brightness(1.08); }

        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); }

        .grid .row { grid-template-columns: 110px minmax(0, 1fr); border-top: 1px solid var(--line); }

        .grid .row code { color: var(--muted); }

        footer { padding: 64px 0 48px; color: var(--muted); font-size: 14px; }

        footer a { color: var(--accent); }

        @media (max-width: 640px) {
            header { padding-top: 48px; }
            .row { grid-template-columns: 1fr; gap: 8px; }
            .try form { flex-direction: column; }
            .try button { padding: 12px; }
        }
    </style>
</head>
<body>
<header class="wrap">
    <span class="brand">x($value)</span>
    <h1>Escape <span>anything</span>.<br>Break nothing.</h1>
    <p class="lead">
        Cast, escape, sanitize and validate any PHP value through one fluent call.
        Every method returns what it promises or throws a typed exception.
        It never emits a warning.
    </p>
    <div class="install">composer require xcapher/xcapher</div>
</header>

<nav>
    <div class="wrap">
        <a href="#try">Try it</a>
        <?php foreach ($sections as $id => [$title]) { ?>
            <a href="#<?= x($id)->htmlAttr() ?>"><?= x($title)->html() ?></a>
        <?php } ?>
    </div>
</nav>

<main class="wrap">
    <section id="try" class="try">
        <h2>Try it</h2>
        <div class="card">
            <form method="get" action="#try">
                <input name="q" value="<?= x($input)->html() ?>" aria-label="Value to test" autocomplete="off" spellcheck="false">
                <button type="submit">Run</button>
            </form>
            <div class="grid">
                <?php foreach ($playground as $method => $run) { ?>
                    <?php $result = outcome(static fn(): mixed => $run($tried)); ?>
                    <div class="row">
                        <code>-><?= x($method)->html() ?></code>
                        <span class="result <?= x($result['kind'])->htmlAttr() ?>"><?= x($result['text'])->html() ?></span>
                    </div>
                <?php } ?>
            </div>
        </div>
    </section>

    <?php foreach ($sections as $id => [$title, $examples]) { ?>
        <section id="<?= x($id)->htmlAttr() ?>">
            <h2><?= x($title)->html() ?></h2>
            <div class="card">
                <?php foreach ($examples as [$code, $run]) { ?>
                    <?php $result = outcome($run); ?>
                    <div class="row">
                        <code><?= x($code)->html() ?></code>
                        <span class="result <?= x($result['kind'])->htmlAttr() ?>"><?= x($result['text'])->html() ?></span>
                    </div>
                <?php } ?>
            </div>
        </section>
    <?php } ?>
</main>

<footer class="wrap">
    Every result on this page is computed live, and all output is escaped with Xcapher itself.
    · <a href="https://github.com/ThaKladd/Xcapher">GitHub</a>
</footer>
</body>
</html>
