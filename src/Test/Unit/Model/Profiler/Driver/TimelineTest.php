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

namespace MagePsycho\Profiler\Test\Unit\Model\Profiler\Driver;

use MagePsycho\Profiler\Model\Profiler\Driver\Timeline;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TimelineTest extends TestCase
{
    /**
     * @var string
     */
    private $baseDir = '';

    /**
     * Drivers handed out by driver(), so tearDown() can flush them.
     *
     * @var Timeline[]
     */
    private $drivers = [];

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/magepsycho-timeline-' . getmypid();
        if (!is_dir($this->baseDir)) {
            mkdir($this->baseDir, 0777, true);
        }

        $this->drivers = [];
        putenv('MAGE_PROFILER_MAX_SPANS');
        putenv('MAGE_PROFILER_KEEP');
    }

    protected function tearDown(): void
    {
        /*
         * Every constructed driver must be flushed here. The constructor registers
         * register_shutdown_function([$this, 'flush']), so an unflushed instance writes a real report
         * at PHPUnit shutdown - into a directory this method has already removed and which
         * ReportIndex::write() would cheerfully recreate. flush() is idempotent, so a flushed
         * instance is inert by then.
         */
        foreach ($this->drivers as $driver) {
            $driver->flush();
        }
        $this->drivers = [];

        putenv('MAGE_PROFILER_MAX_SPANS');
        putenv('MAGE_PROFILER_KEEP');

        $this->removeRecursively($this->baseDir);
    }

    /**
     * @return void
     */
    public function testSpanCarriesNoSqlKeyWithoutTags(): void
    {
        $driver = $this->driver();
        $driver->start('magento');
        $driver->stop('magento');

        $span = $this->report($driver)['spans'][0];

        $this->assertArrayNotHasKey('sql', $span);
        $this->assertArrayNotHasKey('binds', $span);
    }

    /**
     * @return void
     */
    public function testSqlTagLandsOnTheMatchingSpanOnly(): void
    {
        $driver = $this->driver();
        $driver->start('magento');
        $driver->start('magento->SQL:SELECT (dual)', ['sql' => 'SELECT 1', 'binds' => ['1']]);
        $driver->stop('magento->SQL:SELECT (dual)');
        $driver->stop('magento');

        $spans = $this->spansById($this->report($driver));

        $this->assertSame('SELECT 1', $spans['magento->SQL:SELECT (dual)']['sql']);
        $this->assertSame(['1'], $spans['magento->SQL:SELECT (dual)']['binds']);
        /* Neither inherited up to the parent nor left behind on it. */
        $this->assertArrayNotHasKey('sql', $spans['magento']);
    }

    /**
     * The security-relevant one: aggregated rows are what a casual reader scans first.
     *
     * @return void
     */
    public function testAggregatedRowsNeverCarryQueryText(): void
    {
        $driver = $this->driver();
        $driver->start('SQL:SELECT (dual)', ['sql' => 'SELECT secret FROM `customer_entity`']);
        $driver->stop('SQL:SELECT (dual)');

        $row = $this->report($driver)['rows'][0];

        $this->assertSame(
            ['id', 'name', 'depth', 'cnt', 'time_ms', 'avg_ms', 'emalloc_kb', 'realmem_kb', 'pct'],
            array_keys($row)
        );
    }

    /**
     * A frame unwound by an exception holds the query you are usually hunting.
     *
     * @return void
     */
    public function testTagsOnATruncatedFrameAreStillRecorded(): void
    {
        $driver = $this->driver();
        $driver->start('magento');
        $driver->start('magento->SQL:SELECT (dual)', ['sql' => 'SELECT 1']);
        /* Closing the outer id unwinds the inner frame as truncated. */
        $driver->stop('magento');

        $spans = $this->spansById($this->report($driver));
        $span  = $spans['magento->SQL:SELECT (dual)'];

        $this->assertTrue($span['truncated']);
        $this->assertSame('SELECT 1', $span['sql']);
    }

    /**
     * start() is public driver API, so nothing about the tags may be assumed.
     *
     * @param array<string, mixed> $tags
     * @return void
     * @dataProvider malformedTagsDataProvider
     */
    #[DataProvider('malformedTagsDataProvider')]
    public function testMalformedTagsAreIgnored(array $tags): void
    {
        $driver = $this->driver();
        $driver->start('SQL:SELECT (dual)', $tags);
        $driver->stop('SQL:SELECT (dual)');

        $span = $this->report($driver)['spans'][0];

        $this->assertArrayNotHasKey('sql', $span);
        $this->assertArrayNotHasKey('binds', $span);
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function malformedTagsDataProvider(): array
    {
        return [
            'sql is an array'    => [['sql' => ['SELECT 1']]],
            'sql is empty'       => [['sql' => '']],
            'binds without sql'  => [['binds' => []]],
            'unknown key only'   => [['group' => 'db']],
        ];
    }

    /**
     * @return void
     */
    public function testMetaCountsCapturedSpans(): void
    {
        $driver = $this->driver();
        $driver->start('SQL:SELECT (a)', ['sql' => 'SELECT 1']);
        $driver->stop('SQL:SELECT (a)');
        $driver->start('SQL:SELECT (b)', ['sql' => 'SELECT 2']);
        $driver->stop('SQL:SELECT (b)');
        $driver->start('cache_load');
        $driver->stop('cache_load');

        $this->assertSame(2, $this->report($driver)['meta']['sql_captured']);
    }

    /**
     * @return void
     */
    public function testMetaOmitsTheCounterWhenNothingWasCaptured(): void
    {
        $driver = $this->driver();
        $driver->start('cache_load');
        $driver->stop('cache_load');

        $this->assertArrayNotHasKey('sql_captured', $this->report($driver)['meta']);
    }

    /**
     * Text captured for a span past the cap is thrown away with the span.
     *
     * @return void
     */
    public function testSpanCapDropsCapturedSpansToo(): void
    {
        putenv('MAGE_PROFILER_MAX_SPANS=1');

        $driver = $this->driver();
        $driver->start('SQL:SELECT (a)', ['sql' => 'SELECT 1']);
        $driver->stop('SQL:SELECT (a)');
        $driver->start('SQL:SELECT (b)', ['sql' => 'SELECT 2']);
        $driver->stop('SQL:SELECT (b)');

        $report = $this->report($driver);

        $this->assertCount(1, $report['spans']);
        $this->assertSame(1, $report['meta']['dropped']);
        /* Both calls still counted, and both rows still aggregated. */
        $this->assertSame(2, $report['meta']['calls']);
        $this->assertCount(2, $report['rows']);
    }

    /**
     * Baseline coverage this driver has never had.
     *
     * @return void
     */
    public function testNameAndDepthComeFromTheNestingSeparator(): void
    {
        $driver = $this->driver();
        $driver->start('magento');
        $driver->start('magento->LAYOUT');
        $driver->start('magento->LAYOUT->layout_render');
        $driver->stop('magento->LAYOUT->layout_render');
        $driver->stop('magento->LAYOUT');
        $driver->stop('magento');

        $spans = $this->spansById($this->report($driver));

        $this->assertSame('layout_render', $spans['magento->LAYOUT->layout_render']['name']);
        $this->assertSame(2, $spans['magento->LAYOUT->layout_render']['depth']);
        $this->assertSame(0, $spans['magento']['depth']);
    }

    /**
     * @return void
     */
    public function testSpansAreOrderedByStart(): void
    {
        $driver = $this->driver();
        foreach (['a', 'b', 'c'] as $id) {
            $driver->start($id);
            $driver->stop($id);
        }

        $starts = array_column($this->report($driver)['spans'], 'start_ms');
        $sorted = $starts;
        sort($sorted);

        $this->assertSame($sorted, $starts);
    }

    /**
     * @return void
     */
    public function testClearDropsEverything(): void
    {
        $driver = $this->driver();
        $driver->start('SQL:SELECT (a)', ['sql' => 'SELECT 1']);
        $driver->stop('SQL:SELECT (a)');
        $driver->clear();
        $driver->flush();

        /* Nothing aggregated is nothing to report - flush() writes no file at all. */
        $this->assertSame([], $this->reportFiles());
    }

    /**
     * @return void
     */
    public function testFlushIsIdempotent(): void
    {
        $driver = $this->driver();
        $driver->start('magento');
        $driver->stop('magento');

        $driver->flush();
        $driver->flush();

        $this->assertCount(1, $this->reportFiles());
    }

    /**
     * A driver whose reports land under the temp baseDir, tracked for flushing in tearDown().
     *
     * @return Timeline
     */
    private function driver(): Timeline
    {
        $driver = new Timeline(['baseDir' => $this->baseDir]);
        $this->drivers[] = $driver;

        return $driver;
    }

    /**
     * Flush and read the written report back, which exercises buildReport() and ReportIndex too.
     *
     * @param Timeline $driver
     * @return array<string, mixed>
     */
    private function report(Timeline $driver): array
    {
        $driver->flush();

        $files = $this->reportFiles();
        $this->assertNotEmpty($files, 'no report was written');

        $report = json_decode((string)file_get_contents((string)end($files)), true);
        $this->assertIsArray($report);

        return $report;
    }

    /**
     * @return string[]
     */
    private function reportFiles(): array
    {
        return glob($this->baseDir . '/var/log/profiler/*.json') ?: [];
    }

    /**
     * @param array<string, mixed> $report
     * @return array<string, array<string, mixed>>
     */
    private function spansById(array $report): array
    {
        $byId = [];
        foreach ((array)($report['spans'] ?? []) as $span) {
            $byId[$span['id']] = $span;
        }

        return $byId;
    }

    /**
     * @param string $path
     * @return void
     */
    private function removeRecursively(string $path): void
    {
        if (is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff((array)scandir($path), ['.', '..']) as $entry) {
            $this->removeRecursively($path . '/' . $entry);
        }
        rmdir($path);
    }
}
