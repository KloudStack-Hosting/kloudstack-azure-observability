<?php

declare(strict_types=1);

namespace KloudStack\Observability\Tests\Unit;

use KloudStack\Observability\Collector\RequestCollector;
use PHPUnit\Framework\TestCase;

/**
 * Operation names must never contain regex syntax.
 *
 * A live site produced the operation name "GET /{x}{x})?" from the rewrite rule
 * "(.?.+?)(?:/([0-9]+))?/?$" — a single-pass group collapse consumed the inner group and left the
 * outer ")?" stranded. Meaningless as a name, and it leaks regex into Azure Monitor where it
 * fragments the operation-name dimension.
 *
 * @covers \KloudStack\Observability\Collector\RequestCollector::readableRule
 */
final class RouteNormalisationTest extends TestCase
{
    /**
     * @dataProvider rewriteRules
     */
    public function testRewriteRulesProduceCleanNames(string $rule, string $expected): void
    {
        self::assertSame($expected, RequestCollector::readableRule($rule));
    }

    /**
     * Real WordPress rewrite rules.
     *
     * @return array<string, array{string, string}>
     */
    public static function rewriteRules(): array
    {
        return [
            'nested group (the live failure)' => ['(.?.+?)(?:/([0-9]+))?/?$', '/{x}'],
            // Three separated segments stay three. Collapsing them would destroy the route's
            // shape — /2026/07/some-post and /shop/cat/item are not the same route.
            'date archive'                    => ['([0-9]{4})/([0-9]{1,2})/([^/]+)/?$', '/{x}/{x}/{x}'],
            'category'                        => ['category/(.+?)/?$', '/category/{x}'],
            'woocommerce product'             => ['shop/(.+?)/?$', '/shop/{x}'],
            'no groups'                       => ['sitemap\.xml$', ''],
            'trailing slash only'             => ['/?$', '/'],
        ];
    }

    /**
     * @dataProvider rewriteRules
     */
    public function testNoOutputEverContainsRegexSyntax(string $rule): void
    {
        $name = RequestCollector::readableRule($rule);

        self::assertDoesNotMatchRegularExpression(
            '/[()\[\]|\\?$^]/',
            $name,
            'Operation name "' . $name . '" leaks regex syntax from rule "' . $rule . '".'
        );
    }

    public function testAlternationIsRejectedRatherThanMangled(): void
    {
        // "feed/(feed|rdf|rss|rss2|atom)/?$" — an alternation of literals. Rather than emit
        // something half-parsed, reject it so the caller falls back to path-based naming.
        $name = RequestCollector::readableRule('feed/(feed|rdf|rss|rss2|atom)/?$');

        self::assertDoesNotMatchRegularExpression('/[()|]/', $name);
    }

    public function testAdjacentPlaceholdersCollapse(): void
    {
        // "{x}{x}" reads as one unknown segment, not two.
        self::assertSame('/{x}', RequestCollector::readableRule('([0-9]+)([a-z]+)$'));
    }

    public function testMalformedRuleDoesNotHang(): void
    {
        // A rule that cannot be fully collapsed must terminate and be rejected, not spin.
        $start = microtime(true);
        $name  = RequestCollector::readableRule(str_repeat('((((', 50) . 'x');

        self::assertLessThan(1.0, microtime(true) - $start, 'Normalisation must be bounded.');
        self::assertSame('', $name);
    }
}
