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

namespace MagePsycho\Profiler\Test\Unit\Plugin\Db;

use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Select;
use Magento\Framework\Profiler;
use Magento\Framework\Profiler\DriverInterface;
use MagePsycho\Profiler\Model\Instrumentation\Guard;
use MagePsycho\Profiler\Model\Instrumentation\Settings;
use MagePsycho\Profiler\Model\Instrumentation\Timer;
use MagePsycho\Profiler\Model\Instrumentation\TimerId;
use MagePsycho\Profiler\Plugin\Db\QueryProfiler;
use PHPUnit\Framework\TestCase;

class QueryProfilerTest extends TestCase
{
    /**
     * @var string[]
     */
    private $startedIds = [];

    protected function setUp(): void
    {
        $this->startedIds = [];
        Profiler::reset();
        putenv('MAGE_PROFILER_SQL');
        putenv('MAGE_PROFILER_MAX_DETAIL');
    }

    protected function tearDown(): void
    {
        Profiler::reset();
        putenv('MAGE_PROFILER_SQL');
        putenv('MAGE_PROFILER_MAX_DETAIL');
    }

    /**
     * The timer id carries the operation and the primary table.
     *
     * @param string $sql
     * @param string $expected
     * @return void
     * @dataProvider statementDataProvider
     */
    public function testTimerIdForRawStatements(string $sql, string $expected): void
    {
        $this->registerDriver();

        $this->runPlugin($sql);

        $this->assertSame([$expected], $this->startedIds);
    }

    /**
     * @return array<string, string[]>
     */
    public static function statementDataProvider(): array
    {
        return [
            'select' => [
                'SELECT * FROM `catalog_product_entity` WHERE entity_id = 1',
                'SQL:SELECT (catalog_product_entity)',
            ],
            'select without backticks' => [
                'SELECT e.sku FROM catalog_product_entity AS e',
                'SQL:SELECT (catalog_product_entity)',
            ],
            'select with joins' => [
                'SELECT * FROM `catalog_product_entity` AS e'
                    . ' INNER JOIN `catalog_product_entity_int` AS i ON i.entity_id = e.entity_id'
                    . ' LEFT JOIN `catalog_product_website` AS w ON w.product_id = e.entity_id',
                'SQL:SELECT (catalog_product_entity +2)',
            ],
            'insert' => [
                'INSERT INTO `sales_order_grid` (entity_id) VALUES (1)',
                'SQL:INSERT (sales_order_grid)',
            ],
            'replace' => [
                'REPLACE INTO `catalog_url_rewrite_product_category` (url_rewrite_id) VALUES (1)',
                'SQL:REPLACE (catalog_url_rewrite_product_category)',
            ],
            'update' => [
                'UPDATE `cataloginventory_stock_item` SET qty = 0 WHERE item_id = 5',
                'SQL:UPDATE (cataloginventory_stock_item)',
            ],
            'update ignore' => [
                'UPDATE IGNORE `cataloginventory_stock_item` SET qty = 0',
                'SQL:UPDATE (cataloginventory_stock_item)',
            ],
            'delete' => [
                'DELETE FROM `quote` WHERE is_active = 0',
                'SQL:DELETE (quote)',
            ],
            'truncate' => [
                'TRUNCATE TABLE `search_query`',
                'SQL:TRUNCATE (search_query)',
            ],
            'alter' => [
                'ALTER TABLE `catalog_category_entity` ADD COLUMN foo INT',
                'SQL:ALTER (catalog_category_entity)',
            ],
            'create if not exists' => [
                'CREATE TABLE IF NOT EXISTS `tmp_index` (id INT)',
                'SQL:CREATE (tmp_index)',
            ],
            'drop temporary' => [
                'DROP TEMPORARY TABLE IF EXISTS `tmp_index`',
                'SQL:DROP (tmp_index)',
            ],
            'describe' => [
                'DESCRIBE `eav_attribute`',
                'SQL:DESCRIBE (eav_attribute)',
            ],
            'schema qualified' => [
                'SELECT * FROM INFORMATION_SCHEMA.TABLES',
                'SQL:SELECT (INFORMATION_SCHEMA.TABLES)',
            ],
            'leading whitespace and newlines' => [
                "\n   SELECT 1 FROM `dual`",
                'SQL:SELECT (dual)',
            ],
            'operation without a table' => [
                'SHOW TABLE STATUS',
                'SQL:SHOW',
            ],
            'transaction control' => [
                'COMMIT',
                'SQL:COMMIT',
            ],
            'unrecognised statement' => [
                'OPTIMIZE TABLE `catalog_product_entity`',
                'SQL:UNKNOWN',
            ],
        ];
    }

    /**
     * A Select object is read through getPart() - never stringified, which would be expensive.
     *
     * @return void
     */
    public function testTimerIdForSelectObject(): void
    {
        $this->registerDriver();

        $select = $this->createMock(Select::class);
        $select->expects($this->once())
            ->method('getPart')
            ->with(Select::FROM)
            ->willReturn([
                'e' => ['joinType' => Select::FROM, 'tableName' => 'catalog_product_entity'],
                'i' => ['joinType' => 'inner join', 'tableName' => 'catalog_product_entity_int'],
            ]);
        $select->expects($this->never())->method('__toString');

        $this->runPlugin($select);

        $this->assertSame(['SQL:SELECT (catalog_product_entity +1)'], $this->startedIds);
    }

    /**
     * The primary table is picked by join type, not by array order.
     *
     * @return void
     */
    public function testSelectPrefersTheFromTableOverJoins(): void
    {
        $this->registerDriver();

        $select = $this->createMock(Select::class);
        $select->method('getPart')->willReturn([
            'i' => ['joinType' => 'inner join', 'tableName' => 'catalog_product_entity_int'],
            'e' => ['joinType' => Select::FROM, 'tableName' => 'catalog_product_entity'],
        ]);

        $this->runPlugin($select);

        $this->assertSame(['SQL:SELECT (catalog_product_entity +1)'], $this->startedIds);
    }

    /**
     * Long names are cut from the front so the distinguishing tail survives.
     *
     * @return void
     */
    public function testLongTableNamesAreTruncated(): void
    {
        putenv('MAGE_PROFILER_MAX_DETAIL=20');
        $this->registerDriver();

        $this->runPlugin('SELECT * FROM `catalog_product_entity_datetime_value_index`');

        $this->assertSame(['SQL:SELECT (...etime_value_index)'], $this->startedIds);
        $this->assertSame(20, strlen('...etime_value_index'), 'The rendered name must honour MAXLEN exactly');
    }

    /**
     * MAGE_PROFILER_SQL=operation drops the table lookup entirely.
     *
     * @return void
     */
    public function testOperationModeOmitsTheTable(): void
    {
        putenv('MAGE_PROFILER_SQL=operation');
        $this->registerDriver();

        $this->runPlugin('SELECT * FROM `catalog_product_entity`');

        $this->assertSame(['SQL:SELECT'], $this->startedIds);
    }

    /**
     * MAGE_PROFILER_SQL=0 switches SQL profiling off without touching the rest of the profiler.
     *
     * @param string $value
     * @return void
     * @dataProvider offValueDataProvider
     */
    public function testOffModeRecordsNothing(string $value): void
    {
        putenv('MAGE_PROFILER_SQL=' . $value);
        $this->registerDriver();

        $this->assertSame('result', $this->runPlugin('SELECT * FROM `catalog_product_entity`'));
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
     * With the profiler off the plugin must be a pass-through.
     *
     * @return void
     */
    public function testNothingIsRecordedWhileProfilerDisabled(): void
    {
        $this->registerDriver();
        Profiler::disable();

        $this->assertSame('result', $this->runPlugin('SELECT * FROM `catalog_product_entity`'));
        $this->assertSame([], $this->startedIds);
    }

    /**
     * The wrapped call's return value is passed straight through.
     *
     * @return void
     */
    public function testReturnValueIsPreserved(): void
    {
        $this->registerDriver();

        $this->assertSame('result', $this->runPlugin('SELECT 1'));
    }

    /**
     * A failing query must still close its timer, or the whole timer tree unbalances.
     *
     * @return void
     */
    public function testTimerIsClosedWhenTheQueryThrows(): void
    {
        $this->registerDriver();

        $plugin  = $this->createPlugin();
        $subject = $this->createMock(Mysql::class);

        try {
            $plugin->aroundQuery($subject, [$this, 'throwBoom'], 'SELECT 1');
            $this->fail('Expected the exception to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        /* A leaked timer would make the next id nest underneath this one. */
        $this->runPlugin('SELECT * FROM `quote`');

        $this->assertSame(['SQL:SELECT', 'SQL:SELECT (quote)'], $this->startedIds);
    }

    /**
     * The nesting separator would make Profiler::start() throw.
     *
     * @return void
     */
    public function testNestingSeparatorIsStrippedFromTableNames(): void
    {
        $this->registerDriver();

        $select = $this->createMock(Select::class);
        $select->method('getPart')->willReturn([
            'e' => ['joinType' => Select::FROM, 'tableName' => 'weird->table'],
        ]);

        $this->runPlugin($select);

        $this->assertSame(['SQL:SELECT (weird_table)'], $this->startedIds);
    }

    /**
     * Callback used by testTimerIsClosedWhenTheQueryThrows().
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
     * @param string|Select $sql
     * @return mixed
     */
    private function runPlugin($sql)
    {
        $plugin  = $this->createPlugin();
        $subject = $this->createMock(Mysql::class);

        return $plugin->aroundQuery($subject, static function () {
            return 'result';
        }, $sql);
    }

    /**
     * A plugin with freshly built collaborators, so cached env values never leak between tests.
     *
     * @return QueryProfiler
     */
    private function createPlugin(): QueryProfiler
    {
        $settings = new Settings();

        return new QueryProfiler(new Guard($settings), new Timer(), new TimerId($settings), $settings);
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
}
