<?php

declare(strict_types=1);

/*
 * Run from the project root with `php examples/index.php`, or serve it with `php -S localhost:8000 -t examples`.
 */

use Xcapher\Database;
use Xcapher\Exception\XcapherException;

use function Xcapher\x;

require __DIR__ . '/../vendor/autoload.php';

$examples = [
    'String to int' => x('12test')->int(),
    'Array to int' => x([1, 2, 3, 4])->int(),
    'Array to object' => print_r(x([1, 2])->object(), true),
    'Fallback on failure' => x(new stdClass())->int(default: 0),
    'Semantic bool' => var_export(x('off')->bool(), true),
    'URL encode' => x('https://test.com/')->urlEncode(),
    'HTML' => x('<b>"Tom & Jerry"</b>')->html(),
    'HTML attribute' => x('" onmouseover="alert(1)')->htmlAttr(),
    'JavaScript string' => x('</script><script>alert(1)</script>')->js(),
    'JSON for <script>' => x(['name' => '</script>'])->jsValue(),
    'Slug' => x('Blåbær Syltetøy!')->slug(),
    'File name' => x('../../etc/passwd')->filename(),
    'Identifier' => x('order')->identifier(Database::MySql),
    'LIKE pattern' => x('50%_off')->like(),
    'Shell argument' => x("it's; rm -rf /")->shellArg(),
    'Valid e-mail' => var_export(x('bjørn@eksempel.no')->isEmail(), true),
    'Valid URL' => var_export(x('javascript:alert(1)')->isUrl(), true),
];

try {
    x([1, 2])->string();
} catch (XcapherException $e) {
    $examples['Typed exception'] = $e->getMessage();
}

$isCli = \PHP_SAPI === 'cli';

foreach ($examples as $label => $result) {
    $line = sprintf('%s: %s', $label, $result);
    echo $isCli ? $line . \PHP_EOL : x($line)->html() . '<br>' . \PHP_EOL;
}
