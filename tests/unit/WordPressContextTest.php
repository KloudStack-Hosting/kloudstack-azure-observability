<?php

declare(strict_types=1);

namespace KloudStack\Observability\Tests\Unit;

use KloudStack\Observability\Context\WordPressContext;
use PHPUnit\Framework\TestCase;
use WPStubs;

/**
 * These dimensions are a published contract — Marketplace workbook queries bind to these exact
 * names, so a rename is a breaking change. The tests pin the names as much as the values.
 *
 * @covers \KloudStack\Observability\Context\WordPressContext
 */
final class WordPressContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WPStubs::reset();
        unset($_SERVER['SCRIPT_NAME'], $GLOBALS['wp_version'], $GLOBALS['wpdb'], $GLOBALS['wp_object_cache']);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['SCRIPT_NAME'], $GLOBALS['wp_version'], $GLOBALS['wpdb'], $GLOBALS['wp_object_cache']);
        parent::tearDown();
    }

    private function context(bool $managed = false): WordPressContext
    {
        return new WordPressContext(1, '2.0.0', 'standard', $managed);
    }

    public function testSchemaContractDimensionNames(): void
    {
        // If this test needs updating, the schema version must be incremented and the Marketplace
        // workbooks reviewed. That is the whole point of asserting the names.
        $GLOBALS['wp_version']    = '6.8.1';
        WPStubs::$stylesheet      = 'twentytwentyfive';
        WPStubs::$options['active_plugins'] = ['a/a.php', 'b/b.php'];

        $dimensions = $this->context()->staticDimensions();

        self::assertSame('1', $dimensions['schema_version']);
        self::assertSame('2.0.0', $dimensions['plugin_version']);
        self::assertSame('standard', $dimensions['load_mode']);
        self::assertSame(PHP_VERSION, $dimensions['php_version']);
        self::assertSame('6.8.1', $dimensions['wp_version']);
        self::assertSame('twentytwentyfive', $dimensions['wp_theme']);
        self::assertSame('2', $dimensions['wp_plugin_count']);
        self::assertSame('front', $dimensions['wp_context']);
    }

    public function testManagedDimensionOnlyAppearsOnManagedStacks(): void
    {
        self::assertArrayNotHasKey('managed_by', $this->context()->staticDimensions());
        self::assertSame('kloudstack', $this->context(true)->staticDimensions()['managed_by']);
    }

    public function testMultisiteDimensionsOmittedOnSingleSite(): void
    {
        $dimensions = $this->context()->staticDimensions();

        self::assertArrayNotHasKey('wp_is_multisite', $dimensions);
        self::assertArrayNotHasKey('wp_blog_id', $dimensions);
    }

    public function testMultisiteDimensionsPresentOnNetwork(): void
    {
        WPStubs::$isMultisite = true;
        WPStubs::$blogId      = 7;

        $dimensions = $this->context()->staticDimensions();

        self::assertSame('true', $dimensions['wp_is_multisite'], 'Booleans must be "true", not "1".');
        self::assertSame('7', $dimensions['wp_blog_id']);
    }

    public function testUnavailableValuesAreOmittedRatherThanGuessed(): void
    {
        // A false zero would read as "no plugins active", which is a meaningful and wrong claim.
        $dimensions = $this->context()->staticDimensions();

        self::assertArrayNotHasKey('wp_plugin_count', $dimensions);
        self::assertArrayNotHasKey('wp_version', $dimensions);
        self::assertArrayNotHasKey('wp_theme', $dimensions);
    }

    public function testRuntimeDimensions(): void
    {
        $wpdb              = new \stdClass();
        $wpdb->num_queries = 112;
        $GLOBALS['wpdb']   = $wpdb;

        $dimensions = $this->context()->runtimeDimensions();

        self::assertSame('112', $dimensions['wp_query_count']);
        self::assertSame('false', $dimensions['wp_user_logged_in']);
        self::assertArrayHasKey('wp_memory_peak_mb', $dimensions);
        self::assertMatchesRegularExpression('/^\d+(\.\d+)?$/', $dimensions['wp_memory_peak_mb']);
    }

    public function testUserIdentityIsNeverEmittedOnlyABoolean(): void
    {
        WPStubs::$userLoggedIn = true;

        $dimensions = $this->context()->dimensions();

        self::assertSame('true', $dimensions['wp_user_logged_in']);

        foreach (array_keys($dimensions) as $name) {
            self::assertStringNotContainsString('user_id', $name);
            self::assertStringNotContainsString('email', $name);
        }
    }

    /**
     * @dataProvider cacheStates
     */
    public function testObjectCacheState(bool $external, ?int $hits, ?int $misses, string $expected): void
    {
        WPStubs::$usingExtObjectCache = $external;

        if ($hits !== null) {
            $cache                       = new \stdClass();
            $cache->cache_hits           = $hits;
            $cache->cache_misses         = $misses;
            $GLOBALS['wp_object_cache']  = $cache;
        }

        self::assertSame($expected, $this->context()->runtimeDimensions()['wp_cache_hit']);
    }

    /**
     * "unknown" rather than "false" when there is no persistent cache: conflating "no cache
     * configured" with "cache missed" makes hit-rate charts meaningless.
     *
     * @return array<string, array{bool, int|null, int|null, string}>
     */
    public static function cacheStates(): array
    {
        return [
            'no persistent cache' => [false, null, null, 'unknown'],
            'cache with no activity' => [true, 0, 0, 'unknown'],
            'mostly hits'         => [true, 90, 10, 'true'],
            'mostly misses'       => [true, 10, 90, 'false'],
        ];
    }

    /**
     * @dataProvider requestContexts
     *
     * @param array<string, bool> $constants
     */
    public function testRequestContextClassification(array $constants, string $script, bool $admin, string $expected): void
    {
        foreach ($constants as $name => $value) {
            if (!defined($name)) {
                define($name, $value);
            }
        }

        if ($script !== '') {
            $_SERVER['SCRIPT_NAME'] = '/' . $script;
        }

        WPStubs::$isAdmin = $admin;

        self::assertSame($expected, WordPressContext::requestContext());
    }

    /**
     * Only cases that do not require conflicting constants can be table-driven, since constants
     * cannot be undefined once set. Precedence is covered separately below.
     *
     * @return array<string, array{array<string, bool>, string, bool, string}>
     */
    public static function requestContexts(): array
    {
        return [
            'front end' => [[], 'index.php', false, 'front'],
            'admin'     => [[], 'wp-admin/index.php', true, 'admin'],
            'login'     => [[], 'wp-login.php', false, 'login'],
            'signup'    => [[], 'wp-signup.php', false, 'login'],
        ];
    }

    public function testLoginIsDetectedEvenThoughWordPressSetsNoConstant(): void
    {
        // Login traffic has a different performance and security profile from the rest of the
        // front end, and WordPress provides no constant to identify it.
        $_SERVER['SCRIPT_NAME'] = '/wp-login.php';

        self::assertSame('login', WordPressContext::requestContext());
    }

    /**
     * Runs isolated because it defines DOING_AJAX, and a constant cannot be undefined once set.
     * Without isolation every later test in the process would classify as "ajax", which would
     * make this suite order-dependent — passing today and failing on an unrelated change.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testAdminAjaxClassifiesAsAjaxNotAdmin(): void
    {
        // admin-ajax satisfies both is_admin() and DOING_AJAX. Misclassifying background AJAX as
        // admin page traffic pollutes admin performance percentiles.
        if (!defined('DOING_AJAX')) {
            define('DOING_AJAX', true);
        }

        WPStubs::$isAdmin       = true;
        $_SERVER['SCRIPT_NAME'] = '/wp-admin/admin-ajax.php';

        self::assertSame('ajax', WordPressContext::requestContext());
    }
}
