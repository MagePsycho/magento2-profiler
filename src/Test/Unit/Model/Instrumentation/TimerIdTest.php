<?php
/**
 * This file is part of the MagePsycho_Profiler package.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this package
 * to newer versions in the future.
 *
 * @author   Raj KB <rajkb@magepsycho.com>
 * @license  Open Software License (OSL 3.0)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
declare(strict_types=1);

namespace MagePsycho\Profiler\Test\Unit\Model\Instrumentation;

use MagePsycho\Profiler\Model\Instrumentation\Settings;
use MagePsycho\Profiler\Model\Instrumentation\TimerId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TimerIdTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('MAGE_PROFILER_MAX_IDS');
        putenv('MAGE_PROFILER_MAX_DETAIL');
    }

    protected function tearDown(): void
    {
        putenv('MAGE_PROFILER_MAX_IDS');
        putenv('MAGE_PROFILER_MAX_DETAIL');
    }

    /**
     * @return void
     */
    public function testBuildsPrefixedId(): void
    {
        $this->assertSame('SQL:SELECT', $this->create()->build('SQL', 'SELECT'));
    }

    /**
     * @return void
     */
    public function testBuildsIdWithDetail(): void
    {
        $this->assertSame(
            'SQL:SELECT (catalog_product_entity)',
            $this->create()->build('SQL', 'SELECT', 'catalog_product_entity')
        );
    }

    /**
     * An empty detail must not leave dangling parentheses.
     *
     * @return void
     */
    public function testEmptyDetailIsOmitted(): void
    {
        $this->assertSame('SQL:SELECT', $this->create()->build('SQL', 'SELECT', ''));
    }

    /**
     * Profiler::start() throws on the nesting separator, so it can never reach a timer id.
     *
     * @return void
     */
    public function testNestingSeparatorIsStripped(): void
    {
        $this->assertSame(
            'GRAPHQL:Resolver_Products (weird_field)',
            $this->create()->build('GRAPHQL', 'Resolver->Products', 'weird->field')
        );
    }

    /**
     * @return void
     */
    public function testWhitespaceIsCollapsed(): void
    {
        $this->assertSame('CLI:indexer reindex', $this->create()->build('CLI', "  indexer \n  reindex  "));
    }

    /**
     * @return void
     */
    public function testMissingNameFallsBackToUnknown(): void
    {
        $this->assertSame('HTTP:unknown', $this->create()->build('HTTP', '   '));
    }

    /**
     * Details are cut from the front: prefixes collide, suffixes distinguish.
     *
     * @return void
     */
    public function testLongDetailIsTruncatedKeepingTheTail(): void
    {
        putenv('MAGE_PROFILER_MAX_DETAIL=20');

        $id = $this->create()->build('SQL', 'SELECT', 'catalog_product_entity_datetime_value_index');

        $this->assertSame('SQL:SELECT (...etime_value_index)', $id);
    }

    /**
     * The cap is the backstop against ids we cannot fully predict, such as client-supplied
     * GraphQL operation names.
     *
     * @return void
     */
    public function testCardinalityCapCollapsesOverflow(): void
    {
        putenv('MAGE_PROFILER_MAX_IDS=3');
        $timerId = $this->create();

        $ids = [];
        for ($i = 1; $i <= 5; $i++) {
            $ids[] = $timerId->build('GRAPHQL', 'op' . $i);
        }

        $this->assertSame(
            ['GRAPHQL:op1', 'GRAPHQL:op2', 'GRAPHQL:op3', 'GRAPHQL:<overflow>', 'GRAPHQL:<overflow>'],
            $ids
        );
    }

    /**
     * Repeating an id already seen must not consume budget - otherwise a hot query would
     * exhaust the cap on its own.
     *
     * @return void
     */
    public function testRepeatedIdsDoNotConsumeTheCap(): void
    {
        putenv('MAGE_PROFILER_MAX_IDS=2');
        $timerId = $this->create();

        $timerId->build('SQL', 'SELECT', 'a');
        for ($i = 0; $i < 50; $i++) {
            $timerId->build('SQL', 'SELECT', 'a');
        }

        $this->assertSame('SQL:SELECT (b)', $timerId->build('SQL', 'SELECT', 'b'));
        $this->assertSame('SQL:<overflow>', $timerId->build('SQL', 'SELECT', 'c'));
    }

    /**
     * The cap is per prefix, so a noisy area cannot starve a quiet one.
     *
     * @return void
     */
    public function testCapIsPerPrefix(): void
    {
        putenv('MAGE_PROFILER_MAX_IDS=1');
        $timerId = $this->create();

        $timerId->build('SQL', 'SELECT', 'a');

        $this->assertSame('SQL:<overflow>', $timerId->build('SQL', 'SELECT', 'b'));
        $this->assertSame('HTTP:GET (example.com)', $timerId->build('HTTP', 'GET', 'example.com'));
    }

    /**
     * @param string $class
     * @param int $segments
     * @param string $expected
     * @return void
     * @dataProvider shortClassDataProvider
     */
    #[DataProvider('shortClassDataProvider')]
    public function testShortClass(string $class, int $segments, string $expected): void
    {
        $this->assertSame($expected, $this->create()->shortClass($class, $segments));
    }

    /**
     * @return array<string, array{string, int, string}>
     */
    public static function shortClassDataProvider(): array
    {
        return [
            'trims to the last segments' => [
                'Magento\CatalogGraphQl\Model\Resolver\Products',
                2,
                'Resolver\Products',
            ],
            'single segment' => [
                'Magento\CatalogGraphQl\Model\Resolver\Products',
                1,
                'Products',
            ],
            'short class is untouched' => ['Products', 2, 'Products'],
            'interceptor suffix is dropped' => [
                'Magento\Catalog\Model\Product\Interceptor',
                2,
                'Model\Product',
            ],
        ];
    }

    /**
     * Query strings routinely carry API keys, and the report is appended to a log file.
     *
     * @param string $url
     * @param string $expected
     * @return void
     * @dataProvider hostDataProvider
     */
    #[DataProvider('hostDataProvider')]
    public function testHostNeverLeaksTheQueryString(string $url, string $expected): void
    {
        $this->assertSame($expected, $this->create()->host($url));
    }

    /**
     * @return array<string, string[]>
     */
    public static function hostDataProvider(): array
    {
        return [
            'plain' => ['https://api.example.com/v1/orders', 'api.example.com'],
            'with credentials in query' => [
                'https://api.example.com/v1/orders?api_key=SECRET&token=abc',
                'api.example.com',
            ],
            'with port' => ['http://localhost:8080/ping', 'localhost'],
            'relative path keeps no query' => ['/local/endpoint?token=SECRET', '/local/endpoint'],
            'empty' => ['', 'unknown'],
        ];
    }

    /**
     * The versioned suffix changes on every reindex, so it must not reach the id.
     *
     * @param string|string[]|null $index
     * @param string|null $expected
     * @return void
     * @dataProvider indexNameDataProvider
     */
    #[DataProvider('indexNameDataProvider')]
    public function testIndexNameFoldsTheReindexVersion($index, ?string $expected): void
    {
        $this->assertSame($expected, $this->create()->indexName($index));
    }

    /**
     * @return array<string, array{0: string|string[]|null, 1: string|null}>
     */
    public static function indexNameDataProvider(): array
    {
        return [
            'alias passes through' => ['magento2_product_1', 'magento2_product_1'],
            'versioned index is folded' => ['magento2_product_1_v37', 'magento2_product_1_v*'],
            'high version is folded the same' => ['magento2_product_1_v1204', 'magento2_product_1_v*'],
            'a version mid-name is left alone' => ['magento2_v2_product_1', 'magento2_v2_product_1'],
            'list reports the first and a count' => [
                ['magento2_product_1_v3', 'magento2_product_2_v3'],
                'magento2_product_1_v* +1',
            ],
            'comma joined list' => ['magento2_product_1,magento2_product_2', 'magento2_product_1 +1'],
            'blank entries are ignored' => [['', 'magento2_product_1'], 'magento2_product_1'],
            'nesting separator is stripped' => ['weird->index', 'weird_index'],
            'empty string' => ['', null],
            'empty list' => [[], null],
            'null' => [null, null],
        ];
    }

    /**
     * @return TimerId
     */
    private function create(): TimerId
    {
        return new TimerId(new Settings());
    }
}
