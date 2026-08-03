<?php

declare(strict_types=1);

namespace KloudStack\Observability\Tests\Unit;

use KloudStack\Observability\Collector\RequestCollector;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The logic behind the "Excluded paths" setting. Plain entries are substring matches;
 * `#...#`-delimited entries are regular expressions; a malformed regex must fail safe
 * (no match, and never a per-request PHP warning).
 *
 * @covers \KloudStack\Observability\Collector\RequestCollector
 */
final class ExcludedPathMatchingTest extends TestCase
{
    private static function match(string $pattern, string $target): bool
    {
        $method = new ReflectionMethod(RequestCollector::class, 'matchesUserPattern');
        $method->setAccessible(true); // needed on PHP 7.4; a harmless no-op from 8.1

        return (bool) $method->invoke(null, $pattern, $target);
    }

    public function testEmptyPatternNeverMatches(): void
    {
        self::assertFalse(self::match('', '/anything'));
    }

    public function testPlainEntryIsASubstringMatch(): void
    {
        self::assertTrue(self::match('/health', '/health?probe=1'));
        self::assertTrue(self::match('wp-json', '/wp-json/wp/v2/posts'));
        self::assertFalse(self::match('/health', '/about'));
    }

    public function testDelimitedEntryIsARegex(): void
    {
        self::assertTrue(self::match('#^/wp-json/#', '/wp-json/wp/v2/users'));
        // Anchored at ^, so the same segment further along must NOT match.
        self::assertFalse(self::match('#^/wp-json/#', '/blog/wp-json/x'));
    }

    public function testMalformedRegexFailsSafe(): void
    {
        // '#(#' is an unclosed group — invalid. The match must be false, never a warning or throw.
        self::assertFalse(self::match('#(#', '/anything'));
    }

    public function testTooShortToBeARegexIsTreatedAsSubstring(): void
    {
        // A lone '#' is length 1, so it is a literal substring, not a regex delimiter.
        self::assertTrue(self::match('#', '/path#fragment'));
        self::assertFalse(self::match('#', '/plain'));
    }
}
