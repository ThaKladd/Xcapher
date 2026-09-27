<?php

declare(strict_types=1);

namespace Xcapher\Tests;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Xcapher\Database;
use Xcapher\Exception\XcapherException;
use Xcapher\Tests\Fixtures\BrokenText;
use Xcapher\Tests\Fixtures\EmptyBag;
use Xcapher\Tests\Fixtures\Level;
use Xcapher\Tests\Fixtures\Point;
use Xcapher\Tests\Fixtures\Pure;
use Xcapher\Tests\Fixtures\Suit;
use Xcapher\Tests\Fixtures\Text;
use Xcapher\Type;
use Xcapher\Xcapher;

/**
 * Calls every public method with every awkward value and asserts that the only possible outcomes are
 * a return value or an XcapherException: never a PHP warning, notice, deprecation, TypeError or ValueError.
 */
#[CoversNothing]
final class RobustnessTest extends TestCase
{
    /**
     * @return iterable<string, array{\Closure(): mixed}>
     */
    public static function valueProvider(): iterable
    {
        yield 'null' => [static fn(): null => null];
        yield 'true' => [static fn(): bool => true];
        yield 'false' => [static fn(): bool => false];
        yield 'zero' => [static fn(): int => 0];
        yield 'int max' => [static fn(): int => \PHP_INT_MAX];
        yield 'int min' => [static fn(): int => \PHP_INT_MIN];
        yield 'negative zero' => [static fn(): float => -0.0];
        yield 'NaN' => [static fn(): float => \NAN];
        yield 'infinity' => [static fn(): float => \INF];
        yield 'negative infinity' => [static fn(): float => -\INF];
        yield 'tiny float' => [static fn(): float => 5e-324];
        yield 'huge float' => [static fn(): float => 1.7976931348623157E+308];
        yield 'empty string' => [static fn(): string => ''];
        yield 'whitespace' => [static fn(): string => " \t\n\r\0\x0B"];
        yield 'zero string' => [static fn(): string => '0'];
        yield 'overflowing number' => [static fn(): string => '1e999'];
        yield 'negative overflowing number' => [static fn(): string => '-99999999999999999999999'];
        yield 'invalid UTF-8' => [static fn(): string => "\xFF\xFE\xC3\x28\xA0\xA1"];
        yield 'NUL bytes' => [static fn(): string => "a\0b\0"];
        yield 'emoji' => [static fn(): string => "\u{1F600}\u{1F468}\u{200D}\u{1F469}"];
        yield 'html' => [static fn(): string => '<script>alert("x")</script>'];
        yield 'long string' => [static fn(): string => str_repeat('ab<&>"\' ', 20_000)];
        yield 'empty array' => [static fn(): array => []];
        yield 'nested array' => [static fn(): array => ['a' => [1, [2, [3]]], 'b' => new \stdClass()]];
        yield 'array with resource' => [static fn(): array => [fopen('php://memory', 'r')]];
        yield 'recursive array' => [static function (): array {
            $array = [1];
            $array[] = &$array;

            return $array;
        }];
        yield 'stdClass' => [static fn(): object => (object) ['a' => 1]];
        yield 'recursive object' => [static function (): object {
            $object = new \stdClass();
            $object->self = $object;

            return $object;
        }];
        yield 'closure' => [static fn(): \Closure => static fn(): int => 1];
        yield 'generator' => [self::pairs(...)];
        yield 'failing generator' => [self::failing(...)];
        yield 'generator with array keys' => [self::arrayKeys(...)];
        yield 'backed enum' => [static fn(): Suit => Suit::Hearts];
        yield 'int enum' => [static fn(): Level => Level::High];
        yield 'pure enum' => [static fn(): Pure => Pure::Alpha];
        yield 'stringable' => [static fn(): Text => new Text("O'Reilly <b>")];
        yield 'broken stringable' => [static fn(): BrokenText => new BrokenText()];
        yield 'object with private state' => [static fn(): Point => new Point()];
        yield 'countable' => [static fn(): EmptyBag => new EmptyBag()];
        yield 'date' => [static fn(): \DateTimeImmutable => new \DateTimeImmutable('2024-01-01')];
        yield 'spl object' => [static fn(): \SplFixedArray => \SplFixedArray::fromArray([1, 2])];
        yield 'resource' => [static fn(): mixed => fopen('php://memory', 'r')];
        yield 'closed resource' => [static function (): mixed {
            $resource = fopen('php://memory', 'r');
            \assert(\is_resource($resource));
            fclose($resource);

            return $resource;
        }];
    }

    private static function pairs(): \Generator
    {
        yield 'a' => 1;
    }

    private static function failing(): \Generator
    {
        yield 1;

        throw new \RuntimeException('Iteration failed.');
    }

    private static function arrayKeys(): \Generator
    {
        yield [] => 1;
    }

    /**
     * @param \Closure(): mixed $factory
     */
    #[DataProvider('valueProvider')]
    public function testEveryMethodEitherReturnsOrThrowsAnXcapherException(\Closure $factory): void
    {
        $failures = [];

        foreach (self::calls() as $label => $call) {
            set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
                throw new \ErrorException($message, 0, $severity, $file, $line);
            });

            try {
                $call(new Xcapher($factory()));
            } catch (XcapherException) {
                // An expected, typed failure.
            } catch (\Throwable $e) {
                $failures[] = \sprintf('%s: %s: %s', $label, $e::class, $e->getMessage());
            } finally {
                restore_error_handler();
            }
        }

        self::assertSame([], $failures);
    }

    /**
     * Every public method, with representative arguments for the ones that take any.
     *
     * @return iterable<string, \Closure(Xcapher): mixed>
     */
    private static function calls(): iterable
    {
        $pdo = new \PDO('sqlite::memory:');
        $withArguments = [
            'to' => array_map(static fn(Type $type): \Closure => static fn(Xcapher $x): mixed => $x->to($type), Type::cases()),
            'map' => [static fn(Xcapher $x): array => $x->map(static fn(Xcapher $item): ?string => $item->tryString())],
            'escape' => [static fn(Xcapher $x): string => $x->escape($pdo), static fn(Xcapher $x): string => $x->escape(null)],
            'quote' => [static fn(Xcapher $x): string => $x->quote($pdo), static fn(Xcapher $x): string => $x->quote(Database::PostgreSql)],
            'identifier' => [static fn(Xcapher $x): string => $x->identifier(Database::MySql, true), static fn(Xcapher $x): string => $x->identifier(new \stdClass())],
            'matches' => [static fn(Xcapher $x): bool => $x->matches('/a/u'), static fn(Xcapher $x): bool => $x->matches('/(/')],
            'isDate' => [static fn(Xcapher $x): bool => $x->isDate('!!%%'), static fn(Xcapher $x): bool => $x->isDate()],
            'like' => [static fn(Xcapher $x): string => $x->like(), static fn(Xcapher $x): string => $x->like('')],
            'regex' => [static fn(Xcapher $x): string => $x->regex('##'), static fn(Xcapher $x): string => $x->regex(null)],
            'filename' => [static fn(Xcapher $x): string => $x->filename("\0/")],
            'slug' => [static fn(Xcapher $x): string => $x->slug('/')],
            'json' => [static fn(Xcapher $x): string => $x->json(\PHP_INT_MAX, true)],
            'jsonDecode' => [static fn(Xcapher $x): mixed => $x->jsonDecode(false, 1)],
            'htmlEntityEncode' => [static fn(Xcapher $x): string => $x->htmlEntityEncode(\PHP_INT_MAX, 'bogus')],
            'htmlEntityDecode' => [static fn(Xcapher $x): string => $x->htmlEntityDecode(\PHP_INT_MAX, 'bogus')],
            'trim' => [static fn(Xcapher $x): string => $x->trim("\xFF..")],
        ];

        foreach (new \ReflectionClass(Xcapher::class)->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();

            if ($method->isStatic() || $method->isConstructor()) {
                continue;
            }

            if ($method->getNumberOfRequiredParameters() === 0) {
                yield $name => $method->invoke(...);
            }

            foreach ($withArguments[$name] ?? [] as $index => $call) {
                yield $name . ' #' . $index => $call;
            }
        }
    }
}
