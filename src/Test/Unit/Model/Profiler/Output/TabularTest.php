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

namespace MagePsycho\Profiler\Test\Unit\Model\Profiler\Output;

use Magento\Framework\Profiler\Driver\Standard\Stat;
use MagePsycho\Profiler\Model\Profiler\Output\Tabular;
use PHPUnit\Framework\TestCase;

class TabularTest extends TestCase
{
    /**
     * @var string
     */
    private $baseDir;

    /**
     * @var string
     */
    private $logFile = 'profiler_test.log';

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/magepsycho-profiler-' . getmypid();
        if (!is_dir($this->baseDir)) {
            mkdir($this->baseDir, 0777, true);
        }

        /* Keep the test output clean - the run itself is CLI. */
        putenv('MAGE_PROFILER_CLI_STDERR=0');
    }

    protected function tearDown(): void
    {
        putenv('MAGE_PROFILER_CLI_STDERR');
        putenv('MAGE_PROFILER_MIN_MS');
        putenv('MAGE_PROFILER_FILTER');
        putenv('MAGE_PROFILER_LOG');

        $this->removeRecursively($this->baseDir);
    }

    /**
     * Absolute path the report is expected at: always below baseDir/var/log.
     *
     * @param string|null $relative
     * @return string
     */
    private function logPath(?string $relative = null): string
    {
        return $this->baseDir . '/var/log/' . ($relative ?? $this->logFile);
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

    /**
     * The report must land in the configured file, resolved relative to baseDir.
     *
     * @return void
     */
    public function testDisplayWritesReportToConfiguredFile(): void
    {
        $this->createOutput()->display($this->createStat());

        $file = $this->logPath();
        $this->assertFileExists($file);

        $contents = (string)file_get_contents($file);
        $this->assertStringContainsString('Timer Id', $contents);
        $this->assertStringContainsString('magento', $contents);
    }

    /**
     * Timers are rendered with their measured values, converted to ms and KB.
     *
     * @return void
     */
    public function testDisplayRendersMeasuredValues(): void
    {
        $contents = $this->displayAndRead($this->createStat());

        /* magento: 0.5s -> 500.000 ms, 1MB emalloc -> 1024.00 KB, 2MB real -> 2048.00 KB */
        $pattern = '/\|\s+magento\s+\|\s+1\s+\|\s+500\.000\s+\|\s+500\.000\s+'
            . '\|\s+1024\.00\s+\|\s+2048\.00\s+\|\s+100\.0\s+\|/';

        $this->assertMatchesRegularExpression($pattern, $contents);
    }

    /**
     * Nested timer ids keep only their last segment and are indented by depth.
     *
     * @return void
     */
    public function testDisplayIndentsNestedTimers(): void
    {
        $contents = $this->displayAndRead($this->createStat());

        $this->assertStringContainsString('| |- child', $contents);
        $this->assertStringContainsString('| |  |- grandchild', $contents);

        /* Only the last path segment is printed, never the full "a->b->c" id. */
        $this->assertStringNotContainsString('magento->child', $contents);
    }

    /**
     * Averages come from the call count, not from the number of rows.
     *
     * @return void
     */
    public function testDisplayAveragesRepeatedCalls(): void
    {
        $stat = new Stat();
        $stat->start('repeated', 0.0, 0, 0);
        $stat->stop('repeated', 0.2, 0, 0);
        $stat->start('repeated', 1.0, 0, 0);
        $stat->stop('repeated', 1.4, 0, 0);

        $contents = $this->displayAndRead($stat);

        /* 200ms + 400ms over 2 calls -> 600.000 total, 300.000 average */
        $this->assertMatchesRegularExpression(
            '/\|\s+repeated\s+\|\s+2\s+\|\s+600\.000\s+\|\s+300\.000\s+\|/',
            $contents
        );
    }

    /**
     * Every timer is shown by default - the core thresholds (1ms / 10 calls / 10KB) are dropped.
     *
     * @return void
     */
    public function testDisplayShowsSubMillisecondTimersByDefault(): void
    {
        $stat = new Stat();
        $stat->start('tiny', 0.0, 0, 0);
        $stat->stop('tiny', 0.00002, 0, 0);

        $this->assertStringContainsString('tiny', $this->displayAndRead($stat));
    }

    /**
     * MAGE_PROFILER_MIN_MS hides anything faster than the given duration.
     *
     * @return void
     */
    public function testMinTimeEnvVarFiltersFastTimers(): void
    {
        putenv('MAGE_PROFILER_MIN_MS=100');

        $stat = new Stat();
        $stat->start('slow', 0.0, 0, 0);
        $stat->stop('slow', 0.5, 0, 0);
        $stat->start('fast', 1.0, 0, 0);
        $stat->stop('fast', 1.001, 0, 0);

        $contents = $this->displayAndRead($stat);

        $this->assertStringContainsString('slow', $contents);
        $this->assertStringNotContainsString('fast', $contents);
    }

    /**
     * MAGE_PROFILER_FILTER restricts the report to matching timer ids.
     *
     * @return void
     */
    public function testFilterPatternEnvVarRestrictsTimers(): void
    {
        putenv('MAGE_PROFILER_FILTER=/^keep/');

        $stat = new Stat();
        $stat->start('keep_me', 0.0, 0, 0);
        $stat->stop('keep_me', 0.1, 0, 0);
        $stat->start('drop_me', 1.0, 0, 0);
        $stat->stop('drop_me', 1.1, 0, 0);

        $contents = $this->displayAndRead($stat);

        $this->assertStringContainsString('keep_me', $contents);
        $this->assertStringNotContainsString('drop_me', $contents);
    }

    /**
     * Reports accumulate - unlike the core csvfile output, the log is never truncated.
     *
     * @return void
     */
    public function testDisplayAppendsInsteadOfTruncating(): void
    {
        $this->createOutput()->display($this->createStat());
        $this->createOutput()->display($this->createStat());

        $contents = (string)file_get_contents($this->logPath());

        $this->assertSame(2, substr_count($contents, 'Timer Id'));
    }

    /**
     * Table borders must line up, including the header row.
     *
     * @return void
     */
    public function testTableRowsAreAligned(): void
    {
        $lines = array_values(array_filter(
            explode("\n", $this->displayAndRead($this->createStat())),
            static function (string $line): bool {
                return $line !== '' && ($line[0] === '|' || $line[0] === '+');
            }
        ));

        $this->assertNotEmpty($lines);

        $expected = strlen($lines[0]);
        foreach ($lines as $line) {
            $this->assertSame($expected, strlen($line), 'Misaligned table row: ' . $line);
        }
    }

    /**
     * An empty statistics object must not produce a report at all.
     *
     * @return void
     */
    public function testDisplayWritesNothingWithoutTimers(): void
    {
        $this->createOutput()->display(new Stat());

        $this->assertFileDoesNotExist($this->logPath());
    }

    /**
     * A failure inside the output must never bubble up into the request.
     *
     * @return void
     */
    public function testDisplayNeverThrows(): void
    {
        $output = new Tabular(['baseDir' => '/proc/nonexistent-magepsycho', 'filePath' => 'nope.log']);

        $output->display($this->createStat());

        $this->assertTrue(true, 'display() swallowed the write failure');
    }

    /**
     * A traversal attempt must degrade into a nested path under var/log, never escape it.
     *
     * @return void
     */
    public function testTraversalPathStaysUnderVarLog(): void
    {
        $output = new Tabular([
            'baseDir'  => $this->baseDir,
            'filePath' => '../../../../etc/escaped.log',
        ]);

        $output->display($this->createStat());

        $this->assertFileExists($this->logPath('etc/escaped.log'));
        $this->assertFileDoesNotExist($this->baseDir . '/etc/escaped.log');
    }

    /**
     * An absolute, web-served target is rewritten below var/log instead of being honoured.
     *
     * @return void
     */
    public function testAbsolutePathStaysUnderVarLog(): void
    {
        $output = new Tabular([
            'baseDir'  => $this->baseDir,
            'filePath' => '/pub/media/report.log',
        ]);

        $output->display($this->createStat());

        $this->assertFileExists($this->logPath('pub/media/report.log'));
        $this->assertFileDoesNotExist($this->baseDir . '/pub/media/report.log');
    }

    /**
     * The report is never written to an executable extension.
     *
     * @return void
     */
    public function testNonLogExtensionIsForcedToLog(): void
    {
        $output = new Tabular([
            'baseDir'  => $this->baseDir,
            'filePath' => 'shell.php',
        ]);

        $output->display($this->createStat());

        $this->assertFileExists($this->logPath('shell.php.log'));
        $this->assertFileDoesNotExist($this->logPath('shell.php'));
    }

    /**
     * A configured "var/log/..." prefix is the normal way to write the path and must not nest twice.
     *
     * @return void
     */
    public function testVarLogPrefixIsNotDuplicated(): void
    {
        $output = new Tabular([
            'baseDir'  => $this->baseDir,
            'filePath' => 'var/log/prefixed.log',
        ]);

        $output->display($this->createStat());

        $this->assertFileExists($this->logPath('prefixed.log'));
        $this->assertFileDoesNotExist($this->logPath('var/log/prefixed.log'));
    }

    /**
     * MAGE_PROFILER_LOG overrides the path but is confined the same way.
     *
     * @return void
     */
    public function testEnvLogPathStaysUnderVarLog(): void
    {
        putenv('MAGE_PROFILER_LOG=/tmp/from-env.log');

        $this->createOutput()->display($this->createStat());

        $this->assertFileExists($this->logPath('tmp/from-env.log'));
    }

    /**
     * An empty path falls back to the default report file.
     *
     * @return void
     */
    public function testEmptyPathFallsBackToDefault(): void
    {
        $output = new Tabular(['baseDir' => $this->baseDir, 'filePath' => '   ']);

        $output->display($this->createStat());

        $this->assertFileExists($this->logPath('profiler_tabular.log'));
    }

    /**
     * Statistics with a root timer, a child and a grandchild.
     *
     * @return Stat
     */
    private function createStat(): Stat
    {
        $mb = 1024 * 1024;

        $stat = new Stat();
        $stat->start('magento', 0.0, 0, 0);
        $stat->start('magento->child', 0.1, 0, 0);
        $stat->start('magento->child->grandchild', 0.2, 0, 0);
        $stat->stop('magento->child->grandchild', 0.25, 0, 0);
        $stat->stop('magento->child', 0.3, 0, 0);
        $stat->stop('magento', 0.5, 2 * $mb, 1 * $mb);

        return $stat;
    }

    /**
     * @return Tabular
     */
    private function createOutput(): Tabular
    {
        return new Tabular(['baseDir' => $this->baseDir, 'filePath' => $this->logFile]);
    }

    /**
     * @param Stat $stat
     * @return string
     */
    private function displayAndRead(Stat $stat): string
    {
        $this->createOutput()->display($stat);

        return (string)file_get_contents($this->logPath());
    }
}
