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
use MagePsycho\Profiler\Model\Instrumentation\QueryCapture;
use MagePsycho\Profiler\Model\Instrumentation\Settings;
use MagePsycho\Profiler\Model\Instrumentation\Timer;
use MagePsycho\Profiler\Model\Instrumentation\TimerId;
use MagePsycho\Profiler\Model\Profiler\Driver\Timeline;
use MagePsycho\Profiler\Plugin\Db\QueryProfiler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class QueryProfilerTest extends TestCase
{
    /**
     * @var string[]
     */
    private $startedIds = [];

    /**
     * Tags handed to the driver alongside each timer id.
     *
     * @var array<int, array<string, mixed>|null>
     */
    private $startedTags = [];

    protected function setUp(): void
    {
        $this->startedIds  = [];
        $this->startedTags = [];
        Profiler::reset();
        putenv('MAGE_PROFILER_SQL');
        putenv('MAGE_PROFILER_MAX_DETAIL');
        putenv('MAGE_PROFILER_SQL_MAXLEN');
        putenv('MAGE_PROFILER_SQL_BUDGET');
        $this->setTimelineRecording(true);
    }

    protected function tearDown(): void
    {
        Profiler::reset();
        putenv('MAGE_PROFILER_SQL');
        putenv('MAGE_PROFILER_MAX_DETAIL');
        putenv('MAGE_PROFILER_SQL_MAXLEN');
        putenv('MAGE_PROFILER_SQL_BUDGET');
        $this->setTimelineRecording(false);
    }

    /**
     * The timer id carries the operation and the primary table.
     *
     * @param string $sql
     * @param string $expected
     * @return void
     * @dataProvider statementDataProvider
     */
    #[DataProvider('statementDataProvider')]
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
     * A derived table carries a nested Select in tableName. Casting that to a string yields the whole
     * subquery - bound values included - which used to produce ids like
     * "SQL:SELECT (..., 141)) AND (`store_id` IN (1, 0)))": one row per parameter set, and query text
     * in the log. The alias is what repeats across calls, so the alias is what gets reported.
     *
     * @return void
     */
    public function testDerivedTableIsReportedByItsAlias(): void
    {
        $this->registerDriver();

        $subselect = $this->createMock(Select::class);
        $subselect->method('__toString')->willReturn('SELECT ... WHERE (entity_id IN (139, 141))');

        $select = $this->createMock(Select::class);
        $select->method('getPart')->willReturn([
            'main_table' => ['joinType' => Select::FROM, 'tableName' => $subselect],
        ]);

        $this->runPlugin($select);

        $this->assertSame(['SQL:SELECT (main_table)'], $this->startedIds);
    }

    /**
     * Zend numbers correlations when the query gives no alias, and a number names nothing.
     *
     * @return void
     */
    public function testDerivedTableWithoutAnAliasIsMarkedAsASubquery(): void
    {
        $this->registerDriver();

        $select = $this->createMock(Select::class);
        $select->method('getPart')->willReturn([
            0 => ['joinType' => Select::FROM, 'tableName' => $this->createMock(Select::class)],
        ]);

        $this->runPlugin($select);

        $this->assertSame(['SQL:SELECT (<subquery>)'], $this->startedIds);
    }

    /**
     * Two calls of the same query with different bound ids must stay one row.
     *
     * @return void
     */
    public function testDerivedTablesDoNotMultiplyRows(): void
    {
        $this->registerDriver();

        foreach ([141, 143, 156] as $entityId) {
            $subselect = $this->createMock(Select::class);
            $subselect->method('__toString')->willReturn('SELECT ... (entity_id IN (' . $entityId . '))');

            $select = $this->createMock(Select::class);
            $select->method('getPart')->willReturn([
                'main_table' => ['joinType' => Select::FROM, 'tableName' => $subselect],
            ]);

            $this->runPlugin($select);
        }

        $this->assertSame(
            ['SQL:SELECT (main_table)', 'SQL:SELECT (main_table)', 'SQL:SELECT (main_table)'],
            $this->startedIds
        );
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
    #[DataProvider('offValueDataProvider')]
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
     * Capture is opt-in: the default run must carry no payload at all.
     *
     * @return void
     */
    public function testNothingIsCapturedByDefault(): void
    {
        $this->registerDriver();

        $this->runPlugin('SELECT 1 FROM `dual`');

        $this->assertSame([null], $this->startedTags);
    }

    /**
     * operation sheds detail, query gathers it - the two modes are mutually exclusive.
     *
     * @return void
     */
    public function testOperationModeCapturesNothing(): void
    {
        putenv('MAGE_PROFILER_SQL=operation');
        $this->registerDriver();

        $this->runPlugin('SELECT 1 FROM `dual`');

        $this->assertSame(['SQL:SELECT'], $this->startedIds);
        $this->assertNull($this->lastTags());
    }

    /**
     * @return void
     */
    public function testUnknownModeValueLeavesCaptureOff(): void
    {
        putenv('MAGE_PROFILER_SQL=1');
        $this->registerDriver();

        $this->runPlugin('SELECT 1 FROM `dual`');

        $this->assertSame(['SQL:SELECT (dual)'], $this->startedIds);
        $this->assertNull($this->lastTags());
    }

    /**
     * @return void
     */
    public function testQueryModeCapturesStatementAndBinds(): void
    {
        putenv('MAGE_PROFILER_SQL=query');
        $this->registerDriver();

        $this->runPlugin(
            'SELECT * FROM `eav_attribute` WHERE entity_type_id = :entity_type_id AND code = :code',
            ['entity_type_id' => 4, ':code' => 'name']
        );

        $tags = $this->captured();
        $this->assertStringStartsWith('SELECT * FROM `eav_attribute`', $tags['sql']);
        /* The colon is stripped so :code and code read alike - _prepareQuery() normalises later. */
        $this->assertSame(['entity_type_id=4', 'code=name'], $tags['binds']);
    }

    /**
     * Nothing to consume the payload means nothing should be built.
     *
     * @return void
     */
    public function testCaptureIsSkippedWhenNoTimelineIsRecording(): void
    {
        putenv('MAGE_PROFILER_SQL=query');
        $this->setTimelineRecording(false);
        $this->registerDriver();

        $this->runPlugin('SELECT 1 FROM `dual`');

        $this->assertNull($this->lastTags());
    }

    /**
     * @return void
     */
    public function testCaptureIsSkippedWhileTheProfilerIsDisabled(): void
    {
        putenv('MAGE_PROFILER_SQL=query');
        $this->registerDriver();
        Profiler::disable();

        $select = $this->createMock(Select::class);
        $select->expects($this->never())->method('assemble');

        $this->assertSame('result', $this->runPlugin($select));
    }

    /**
     * assemble(), never __toString() - see QueryCapture for why that distinction matters.
     *
     * @return void
     */
    public function testSelectObjectIsAssembledOnlyWhenCapturing(): void
    {
        putenv('MAGE_PROFILER_SQL=query');
        $this->registerDriver();

        $select = $this->createMock(Select::class);
        $select->method('getPart')->willReturn([
            'e' => ['joinType' => Select::FROM, 'tableName' => 'catalog_product_entity'],
        ]);
        $select->expects($this->once())->method('assemble')->willReturn('SELECT * FROM `catalog_product_entity`');
        $select->expects($this->never())->method('__toString');

        $this->runPlugin($select);

        $this->assertSame('SELECT * FROM `catalog_product_entity`', $this->captured()['sql']);
    }

    /**
     * A broken renderer must cost the query nothing.
     *
     * @return void
     */
    public function testCaptureFailureDoesNotBreakTheQuery(): void
    {
        putenv('MAGE_PROFILER_SQL=query');
        $this->registerDriver();

        $select = $this->createMock(Select::class);
        $select->method('getPart')->willReturn([
            'e' => ['joinType' => Select::FROM, 'tableName' => 'catalog_product_entity'],
        ]);
        $select->method('assemble')->willThrowException(new \RuntimeException('renderer exploded'));

        $this->assertSame('result', $this->runPlugin($select));
        $this->assertSame(['SQL:SELECT (catalog_product_entity)'], $this->startedIds);
        $this->assertNull($this->lastTags());
    }

    /**
     * @return void
     */
    public function testCapturedSqlIsWhitespaceNormalised(): void
    {
        putenv('MAGE_PROFILER_SQL=query');
        $this->registerDriver();

        $this->runPlugin("SELECT\n    *\n  FROM\t`dual`\n");

        $this->assertSame('SELECT * FROM `dual`', $this->captured()['sql']);
    }

    /**
     * The nesting separator is illegal in a timer id and perfectly legal in a JSON path.
     *
     * Guards against someone later "tidying" QueryCapture to reuse TimerId::sanitize().
     *
     * @return void
     */
    public function testTheNestingSeparatorSurvivesInCapturedSql(): void
    {
        putenv('MAGE_PROFILER_SQL=query');
        $this->registerDriver();

        $this->runPlugin("SELECT json_col->'\$.sku' FROM `weird->table`");

        $this->assertStringContainsString("json_col->'\$.sku'", $this->captured()['sql']);
        /* Whatever the id ends up being, it must not carry the separator - Profiler::start() throws. */
        $this->assertStringNotContainsString('->', $this->startedIds[0]);
    }

    /**
     * @return void
     */
    public function testCapturedSqlHonoursMaxLen(): void
    {
        putenv('MAGE_PROFILER_SQL=query');
        putenv('MAGE_PROFILER_SQL_MAXLEN=32');
        $this->registerDriver();

        $this->runPlugin('SELECT * FROM `dual` WHERE id IN (' . implode(',', range(1, 200)) . ')');

        $sql = $this->captured()['sql'];
        $this->assertSame(32, strlen($sql));
        $this->assertStringStartsWith('SELECT * FROM `dual`', $sql);
        $this->assertStringEndsWith('...', $sql);
    }

    /**
     * A byte cut through a multi-byte literal would make the whole report field encode as null.
     *
     * @return void
     */
    public function testMultibyteSqlIsNotSplitMidCharacter(): void
    {
        putenv('MAGE_PROFILER_SQL=query');
        putenv('MAGE_PROFILER_SQL_MAXLEN=30');
        $this->registerDriver();

        $this->runPlugin("SELECT * FROM `t` WHERE n = 'ünïcödé wörth cüttïng'");

        $sql = $this->captured()['sql'];
        $this->assertTrue(mb_check_encoding($sql, 'UTF-8'));
        $this->assertIsString(json_encode(['sql' => $sql]));
    }

    /**
     * @return void
     */
    public function testBindCountIsCapped(): void
    {
        putenv('MAGE_PROFILER_SQL=query');
        $this->registerDriver();

        $this->runPlugin('SELECT 1', range(1, 50));

        $binds = $this->captured()['binds'];
        $this->assertCount(21, $binds);
        $this->assertSame('+30 more', end($binds));
    }

    /**
     * @param mixed $value
     * @param string $expected
     * @return void
     * @dataProvider bindValueDataProvider
     */
    #[DataProvider('bindValueDataProvider')]
    public function testBindValueShapes($value, string $expected): void
    {
        putenv('MAGE_PROFILER_SQL=query');
        $this->registerDriver();

        $this->runPlugin('SELECT 1', ['v' => $value]);

        $this->assertSame(['v=' . $expected], $this->captured()['binds']);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function bindValueDataProvider(): array
    {
        return [
            'null'    => [null, 'NULL'],
            'true'    => [true, 'true'],
            'false'   => [false, 'false'],
            'int'     => [42, '42'],
            'float'   => [1.5, '1.5'],
            'string'  => ['name', 'name'],
            'long'    => [str_repeat('a', 200), str_repeat('a', 61) . '...'],
            'array'   => [[1, 2, 3], '<array:3>'],
            'object'  => [new \stdClass(), '<stdClass>'],
        ];
    }

    /**
     * Mysql::_prepareQuery() is what wraps a scalar bind into an array, and it runs after us.
     *
     * @return void
     */
    public function testNonArrayBindIsHandled(): void
    {
        putenv('MAGE_PROFILER_SQL=query');
        $this->registerDriver();

        $this->runPlugin('SELECT * FROM `dual` WHERE id = ?', 5);

        $this->assertSame(['5'], $this->captured()['binds']);
    }

    /**
     * Past the budget the queries still run and are still timed - they just stop carrying text.
     *
     * @return void
     */
    public function testBudgetStopsCapture(): void
    {
        putenv('MAGE_PROFILER_SQL=query');
        putenv('MAGE_PROFILER_SQL_BUDGET=30');
        $this->registerDriver();

        $plugin = $this->createPlugin();
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame('result', $this->runPlugin('SELECT * FROM `catalog_product_entity`', [], $plugin));
        }

        $this->assertIsArray($this->startedTags[0]);
        $this->assertNull($this->startedTags[1]);
        $this->assertNull($this->startedTags[2]);
        $this->assertSame(
            array_fill(0, 3, 'SQL:SELECT (catalog_product_entity)'),
            $this->startedIds
        );
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
     * @param mixed $bind
     * @param QueryProfiler|null $plugin Reuse one instance when the test spans several queries.
     * @return mixed
     */
    private function runPlugin($sql, $bind = [], ?QueryProfiler $plugin = null)
    {
        $plugin  = $plugin ?: $this->createPlugin();
        $subject = $this->createMock(Mysql::class);

        return $plugin->aroundQuery($subject, static function () {
            return 'result';
        }, $sql, $bind);
    }

    /**
     * A plugin with freshly built collaborators, so cached env values never leak between tests.
     *
     * @return QueryProfiler
     */
    private function createPlugin(): QueryProfiler
    {
        $settings = new Settings();

        return new QueryProfiler(
            new Guard($settings),
            new Timer(),
            new TimerId($settings),
            $settings,
            new QueryCapture($settings)
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
            ->willReturnCallback(function ($timerId, $tags = null): void {
                $this->startedIds[]  = $timerId;
                $this->startedTags[] = $tags;
            });

        Profiler::add($driver);
    }

    /**
     * Capture is gated on a timeline driver existing, which a unit test has no business constructing -
     * its constructor registers a shutdown hook that writes a real report.
     *
     * @param bool $on
     * @return void
     */
    private function setTimelineRecording(bool $on): void
    {
        /* No setAccessible(): it has been a no-op since PHP 8.1 and is deprecated in 8.5. */
        (new \ReflectionProperty(Timeline::class, 'recording'))->setValue(null, $on);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lastTags(): ?array
    {
        return $this->startedTags ? end($this->startedTags) : null;
    }

    /**
     * The captured payload, asserted to exist so the reads below stay unambiguous.
     *
     * @return array<string, mixed>
     */
    private function captured(): array
    {
        $tags = $this->lastTags();
        $this->assertIsArray($tags, 'expected a captured payload');

        return $tags;
    }
}
