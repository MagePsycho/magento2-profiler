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

namespace MagePsycho\Profiler\Test\Unit\Plugin\Search;

use Magento\Framework\Profiler;
use Magento\Framework\Profiler\DriverInterface;
use Magento\OpenSearch\Model\SearchClient;
use MagePsycho\Profiler\Model\Instrumentation\Guard;
use MagePsycho\Profiler\Model\Instrumentation\Settings;
use MagePsycho\Profiler\Model\Instrumentation\Timer;
use MagePsycho\Profiler\Model\Instrumentation\TimerId;
use MagePsycho\Profiler\Plugin\Search\OpenSearchClientProfiler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OpenSearchClientProfilerTest extends TestCase
{
    /**
     * @var string[]
     */
    private $startedIds = [];

    protected function setUp(): void
    {
        if (!class_exists(SearchClient::class)) {
            $this->markTestSkipped('Magento_OpenSearch is not installed');
        }

        $this->startedIds = [];
        Profiler::reset();
        $this->clearEnv();
    }

    protected function tearDown(): void
    {
        Profiler::reset();
        $this->clearEnv();
    }

    /**
     * The read path passes the unversioned alias.
     *
     * @return void
     */
    public function testQueryTimerIdCarriesTheIndexAlias(): void
    {
        $this->registerDriver();

        $this->runQuery(['index' => 'magento2_product_1', 'body' => []]);

        $this->assertSame(['OPENSEARCH:query (magento2_product_1)'], $this->startedIds);
    }

    /**
     * The regression this plugin's detail handling exists for: a full reindex increments the _vN
     * suffix, and without folding it every run would add a permanent new row.
     *
     * @return void
     */
    public function testVersionedIndexNamesCollapseToASingleId(): void
    {
        $this->registerDriver();

        $plugin  = $this->createPlugin();
        $subject = $this->createMock(SearchClient::class);

        foreach (['magento2_product_1_v37', 'magento2_product_1_v38'] as $index) {
            $plugin->aroundBulkQuery($subject, static function () {
                return 'result';
            }, ['index' => $index, 'body' => []]);
        }

        $this->assertSame(
            ['OPENSEARCH:bulkQuery (magento2_product_1_v*)', 'OPENSEARCH:bulkQuery (magento2_product_1_v*)'],
            $this->startedIds
        );
    }

    /**
     * A multi-index search reports the first index and how many more it touched.
     *
     * @return void
     */
    public function testIndexListIsReducedToFirstPlusCount(): void
    {
        $this->registerDriver();

        $this->runQuery(['index' => ['magento2_product_1_v3', 'magento2_product_2_v3']]);

        $this->assertSame(['OPENSEARCH:query (magento2_product_1_v* +1)'], $this->startedIds);
    }

    /**
     * Deep pagination unsets the index and pages through a point in time instead.
     *
     * @return void
     */
    public function testQueryWithoutIndexFallsBackToPit(): void
    {
        $this->registerDriver();

        $this->runQuery(['body' => ['pit' => ['id' => 'abc']]]);

        $this->assertSame(['OPENSEARCH:query (pit)'], $this->startedIds);
    }

    /**
     * @return void
     */
    public function testQueryWithoutIndexOrPitHasNoDetail(): void
    {
        $this->registerDriver();

        $this->runQuery(['body' => []]);

        $this->assertSame(['OPENSEARCH:query'], $this->startedIds);
    }

    /**
     * The index-administration methods take the index as their first argument.
     *
     * @param string $method
     * @param array<int, mixed> $extraArgs
     * @return void
     * @dataProvider indexArgumentMethodDataProvider
     */
    #[DataProvider('indexArgumentMethodDataProvider')]
    public function testIndexAdminMethodsUseTheirIndexArgument(string $method, array $extraArgs = []): void
    {
        $this->registerDriver();

        $plugin  = $this->createPlugin();
        $subject = $this->createMock(SearchClient::class);
        $call    = static function () {
            return 'result';
        };

        $args = array_merge([$subject, $call, 'magento2_product_1_v9'], $extraArgs);
        $plugin->{'around' . ucfirst($method)}(...$args);

        $this->assertSame(['OPENSEARCH:' . $method . ' (magento2_product_1_v*)'], $this->startedIds);
    }

    /**
     * @return array<string, array{0: string, 1?: array<int, mixed>}>
     */
    public static function indexArgumentMethodDataProvider(): array
    {
        return [
            'createIndex' => ['createIndex', [['settings' => []]]],
            'putIndexSettings' => ['putIndexSettings', [['settings' => []]]],
            'deleteIndex' => ['deleteIndex'],
            'isEmptyIndex' => ['isEmptyIndex'],
            'indexExists' => ['indexExists'],
        ];
    }

    /**
     * deleteMapping takes an entity type as its second argument, not an index.
     *
     * @return void
     */
    public function testDeleteMappingUsesItsIndexArgument(): void
    {
        $this->registerDriver();

        $this->createPlugin()->aroundDeleteMapping(
            $this->createMock(SearchClient::class),
            static function () {
                return 'result';
            },
            'magento2_product_1_v9',
            'document'
        );

        $this->assertSame(['OPENSEARCH:deleteMapping (magento2_product_1_v*)'], $this->startedIds);
    }

    /**
     * The odd one out - the index is the second argument here.
     *
     * @return void
     */
    public function testAddFieldsMappingReadsTheSecondArgument(): void
    {
        $this->registerDriver();

        $this->createPlugin()->aroundAddFieldsMapping(
            $this->createMock(SearchClient::class),
            static function () {
                return 'result';
            },
            ['sku' => ['type' => 'text']],
            'magento2_product_1_v9',
            'document'
        );

        $this->assertSame(['OPENSEARCH:addFieldsMapping (magento2_product_1_v*)'], $this->startedIds);
    }

    /**
     * Alias methods report the alias, which is stable across reindexes.
     *
     * @return void
     */
    public function testAliasMethodsUseTheAliasArgument(): void
    {
        $this->registerDriver();

        $plugin  = $this->createPlugin();
        $subject = $this->createMock(SearchClient::class);
        $call    = static function () {
            return 'result';
        };

        $plugin->aroundUpdateAlias($subject, $call, 'magento2_product_1', 'magento2_product_1_v9');
        $plugin->aroundExistsAlias($subject, $call, 'magento2_product_1');
        $plugin->aroundGetAlias($subject, $call, 'magento2_product_1');

        $this->assertSame(
            [
                'OPENSEARCH:updateAlias (magento2_product_1)',
                'OPENSEARCH:existsAlias (magento2_product_1)',
                'OPENSEARCH:getAlias (magento2_product_1)',
            ],
            $this->startedIds
        );
    }

    /**
     * Batch size is bucketed, so a reindex produces a handful of ids rather than one per batch.
     *
     * @param int $documents
     * @param string $action
     * @param string $expected
     * @return void
     * @dataProvider bulkBucketDataProvider
     */
    #[DataProvider('bulkBucketDataProvider')]
    public function testBulkBatchSizeIsBucketed(int $documents, string $action, string $expected): void
    {
        $this->registerDriver();

        $this->createPlugin()->aroundBulkQuery(
            $this->createMock(SearchClient::class),
            static function () {
                return 'result';
            },
            ['index' => 'magento2_product_1_v9', 'body' => $this->bulkBody($documents, $action)]
        );

        $this->assertSame(['OPENSEARCH:bulkQuery (magento2_product_1_v* ' . $expected . ')'], $this->startedIds);
    }

    /**
     * @return array<string, array{0: int, 1: string, 2: string}>
     */
    public static function bulkBucketDataProvider(): array
    {
        return [
            'single document' => [1, 'index', 'x1'],
            'dozens' => [47, 'index', 'x10'],
            'hundreds' => [640, 'index', 'x100'],
            'a full batch' => [1000, 'index', 'x1k'],
            'oversized batch' => [5000, 'index', 'x1k'],
            'deletes carry no document line' => [30, 'delete', 'x10'],
        ];
    }

    /**
     * A timed-out or shard-failed response opens a nested marker whose Cnt is the failure count.
     *
     * @param array<string, mixed> $response
     * @return void
     * @dataProvider degradedResponseDataProvider
     */
    #[DataProvider('degradedResponseDataProvider')]
    public function testDegradedResponsesOpenAMarkerTimer(array $response): void
    {
        $this->registerDriver();

        $this->createPlugin()->aroundQuery(
            $this->createMock(SearchClient::class),
            static function () use ($response) {
                return $response;
            },
            ['index' => 'magento2_product_1']
        );

        /* The marker is opened inside the query timer, so it arrives as a nested id. */
        $this->assertSame(
            [
                'OPENSEARCH:query (magento2_product_1)',
                'OPENSEARCH:query (magento2_product_1)->OPENSEARCH:query:degraded',
            ],
            $this->startedIds
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function degradedResponseDataProvider(): array
    {
        return [
            'timed out' => [['timed_out' => true]],
            'failed shard' => [['_shards' => ['total' => 5, 'failed' => 1]]],
        ];
    }

    /**
     * @return void
     */
    public function testHealthyResponseOpensNoMarker(): void
    {
        $this->registerDriver();

        $this->runQuery(['index' => 'magento2_product_1'], ['timed_out' => false, '_shards' => ['failed' => 0]]);

        $this->assertSame(['OPENSEARCH:query (magento2_product_1)'], $this->startedIds);
    }

    /**
     * @return void
     */
    public function testBulkErrorsOpenAnErrorMarker(): void
    {
        $this->registerDriver();

        $this->createPlugin()->aroundBulkQuery(
            $this->createMock(SearchClient::class),
            static function () {
                return ['errors' => true, 'items' => []];
            },
            ['index' => 'magento2_product_1_v9', 'body' => []]
        );

        $this->assertSame(
            [
                'OPENSEARCH:bulkQuery (magento2_product_1_v*)',
                'OPENSEARCH:bulkQuery (magento2_product_1_v*)->OPENSEARCH:bulkQuery:errors',
            ],
            $this->startedIds
        );
    }

    /**
     * MAGE_PROFILER_SEARCH=operation keeps the rows but drops the index.
     *
     * @return void
     */
    public function testOperationModeOmitsTheIndex(): void
    {
        putenv('MAGE_PROFILER_SEARCH=operation');
        $this->registerDriver();

        $this->runQuery(['index' => 'magento2_product_1']);

        $this->assertSame(['OPENSEARCH:query'], $this->startedIds);
    }

    /**
     * MAGE_PROFILER_SEARCH=0 switches both search layers off.
     *
     * @param string $value
     * @return void
     * @dataProvider offValueDataProvider
     */
    #[DataProvider('offValueDataProvider')]
    public function testOffModeRecordsNothing(string $value): void
    {
        putenv('MAGE_PROFILER_SEARCH=' . $value);
        $this->registerDriver();

        $this->assertSame('result', $this->runQuery(['index' => 'magento2_product_1']));
        $this->assertSame([], $this->startedIds);
    }

    /**
     * @return array<string, string[]>
     */
    public static function offValueDataProvider(): array
    {
        return [
            'zero' => ['0'],
            'false' => ['false'],
            'off' => ['off'],
            'no' => ['no'],
        ];
    }

    /**
     * @return void
     */
    public function testNothingIsRecordedWhileProfilerDisabled(): void
    {
        $this->registerDriver();
        Profiler::disable();

        $this->assertSame('result', $this->runQuery(['index' => 'magento2_product_1']));
        $this->assertSame([], $this->startedIds);
    }

    /**
     * @return void
     */
    public function testReturnValueIsPreserved(): void
    {
        $this->registerDriver();

        $this->assertSame('result', $this->runQuery(['index' => 'magento2_product_1']));
    }

    /**
     * A failing call must still close its timer, or the whole timer tree unbalances.
     *
     * @return void
     */
    public function testTimerIsClosedWhenTheCallThrows(): void
    {
        $this->registerDriver();

        $plugin  = $this->createPlugin();
        $subject = $this->createMock(SearchClient::class);

        try {
            $plugin->aroundQuery($subject, [$this, 'throwBoom'], ['index' => 'magento2_product_1']);
            $this->fail('Expected the exception to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        /* A leaked timer would make the next id nest underneath this one. */
        $this->runQuery(['index' => 'magento2_category_1']);

        $this->assertSame(
            ['OPENSEARCH:query (magento2_product_1)', 'OPENSEARCH:query (magento2_category_1)'],
            $this->startedIds
        );
    }

    /**
     * The nesting separator would make Profiler::start() throw.
     *
     * @return void
     */
    public function testNestingSeparatorIsStrippedFromIndexNames(): void
    {
        $this->registerDriver();

        $this->runQuery(['index' => 'weird->index']);

        $this->assertSame(['OPENSEARCH:query (weird_index)'], $this->startedIds);
    }

    /**
     * Long names are cut from the front so the distinguishing tail survives.
     *
     * @return void
     */
    public function testLongIndexNamesAreTruncated(): void
    {
        putenv('MAGE_PROFILER_MAX_DETAIL=20');
        $this->registerDriver();

        $this->runQuery(['index' => 'magento2_product_extra_long_store_code_1']);

        $this->assertSame(['OPENSEARCH:query (...long_store_code_1)'], $this->startedIds);
    }

    /**
     * Callback used by testTimerIsClosedWhenTheCallThrows().
     *
     * Kept out of the test method so the throw and the catch do not share a scope.
     *
     * @return void
     * @throws \RuntimeException
     */
    public function throwBoom(): void
    {
        throw new \RuntimeException('boom');
    }

    /**
     * A bulk body as the indexer builds it: an action line per document, plus a document line for
     * every action except delete.
     *
     * @param int $documents
     * @param string $action
     * @return array<int, array<string, mixed>>
     */
    private function bulkBody(int $documents, string $action): array
    {
        $body = [];
        for ($i = 0; $i < $documents; $i++) {
            $body[] = [$action => ['_id' => $i, '_index' => 'magento2_product_1_v9']];
            if ($action !== 'delete') {
                $body[] = ['sku' => 'sku-' . $i];
            }
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $query
     * @param mixed $result
     * @return mixed
     */
    private function runQuery(array $query, $result = 'result')
    {
        return $this->createPlugin()->aroundQuery(
            $this->createMock(SearchClient::class),
            static function () use ($result) {
                return $result;
            },
            $query
        );
    }

    /**
     * A plugin with freshly built collaborators, so cached env values never leak between tests.
     *
     * @return OpenSearchClientProfiler
     */
    private function createPlugin(): OpenSearchClientProfiler
    {
        $settings = new Settings();

        return new OpenSearchClientProfiler(
            new Guard($settings),
            new Timer(),
            new TimerId($settings),
            $settings
        );
    }

    /**
     * Register a spying driver. Profiler::add() enables the profiler as a side effect.
     *
     * @return void
     */
    private function registerDriver(): void
    {
        $driver = $this->createMock(DriverInterface::class);
        $driver->method('start')
            ->willReturnCallback(function ($timerId): void {
                $this->startedIds[] = $timerId;
            });

        Profiler::add($driver);
    }

    /**
     * @return void
     */
    private function clearEnv(): void
    {
        putenv('MAGE_PROFILER_SEARCH');
        putenv('MAGE_PROFILER_MAX_DETAIL');
        putenv('MAGE_PROFILER_MAX_IDS');
    }
}
