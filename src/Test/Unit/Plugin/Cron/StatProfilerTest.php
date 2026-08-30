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

namespace MagePsycho\Profiler\Test\Unit\Plugin\Cron;

use Magento\Framework\Profiler;
use Magento\Framework\Profiler\Driver\Standard\Stat;
use Magento\Framework\Profiler\DriverInterface;
use MagePsycho\Profiler\Model\Instrumentation\Guard;
use MagePsycho\Profiler\Model\Instrumentation\Settings;
use MagePsycho\Profiler\Model\Instrumentation\TimerId;
use MagePsycho\Profiler\Plugin\Cron\StatProfiler;
use PHPUnit\Framework\TestCase;

class StatProfilerTest extends TestCase
{
    /**
     * @var string[]
     */
    private $startedIds = [];

    /**
     * @var string[]
     */
    private $stoppedIds = [];

    protected function setUp(): void
    {
        $this->startedIds = [];
        $this->stoppedIds = [];
        Profiler::reset();
        putenv('MAGE_PROFILER_CLI');
    }

    protected function tearDown(): void
    {
        Profiler::reset();
        putenv('MAGE_PROFILER_CLI');
    }

    /**
     * @return void
     */
    public function testCronTimersAreMirroredIntoTheProfiler(): void
    {
        $this->registerDriver();

        $plugin  = $this->createPlugin();
        $subject = $this->createMock(Stat::class);

        $plugin->beforeStart($subject, 'job catalog_product_outdated_price_values_cleanup', 1.0, 0, 0);
        $plugin->beforeStop($subject, 'job catalog_product_outdated_price_values_cleanup', 2.0, 0, 0);

        $this->assertSame(['CRON:catalog_product_outdated_price_values_cleanup'], $this->startedIds);
        $this->assertSame(['CRON:catalog_product_outdated_price_values_cleanup'], $this->stoppedIds);
    }

    /**
     * The plugin returns null so the original arguments pass through untouched - it observes Stat,
     * it does not rewrite what cron asked for.
     *
     * @return void
     */
    public function testArgumentsAreNeverRewritten(): void
    {
        $this->registerDriver();

        $plugin  = $this->createPlugin();
        $subject = $this->createMock(Stat::class);

        $this->assertNull($plugin->beforeStart($subject, 'job indexer_reindex_all_invalid', 1.0, 0, 0));
        $this->assertNull($plugin->beforeStop($subject, 'job indexer_reindex_all_invalid', 2.0, 0, 0));
        $this->assertNull($plugin->beforeStart($subject, 'SQL:SELECT (cron_schedule)', 1.0, 0, 0));
    }

    /**
     * This is the property that stops the mirroring recursing.
     *
     * The module's own Standard driver keeps a Stat as well, so the plugin fires for every timer the
     * profiler records - the CRON: rows it emits here included. Those do not carry cron's "job "
     * prefix, and that is the whole of what breaks the loop.
     *
     * @return void
     */
    public function testTheProfilersOwnTimersAreIgnored(): void
    {
        $this->registerDriver();

        $plugin  = $this->createPlugin();
        $subject = $this->createMock(Stat::class);

        /* What re-entry looks like: the id this plugin just emitted, coming back through Stat. */
        $plugin->beforeStart($subject, 'CRON:sitemap_generate', 1.0, 0, 0);
        $plugin->beforeStart($subject, 'CLI:cron:run', 1.0, 0, 0);
        $plugin->beforeStart($subject, 'SQL:SELECT (cron_schedule)', 1.0, 0, 0);
        $plugin->beforeStart($subject, 'magento', 1.0, 0, 0);

        $this->assertSame([], $this->startedIds);
    }

    /**
     * "job" without a trailing space is somebody else's timer, not cron's.
     *
     * @return void
     */
    public function testPrefixMustBeFollowedBySeparator(): void
    {
        $this->registerDriver();

        $plugin = $this->createPlugin();

        $plugin->beforeStart($this->createMock(Stat::class), 'jobqueue_consumer', 1.0, 0, 0);

        $this->assertSame([], $this->startedIds);
    }

    /**
     * Gated on MAGE_PROFILER_CLI: cron is a console command, and its parent row is CLI:cron:run.
     *
     * @return void
     */
    public function testOffModeRecordsNothing(): void
    {
        putenv('MAGE_PROFILER_CLI=0');
        $this->registerDriver();

        $this->createPlugin()->beforeStart($this->createMock(Stat::class), 'job sitemap_generate', 1.0, 0, 0);

        $this->assertSame([], $this->startedIds);
    }

    /**
     * @return void
     */
    public function testNonStringTimerIdIsIgnored(): void
    {
        $this->registerDriver();

        $this->createPlugin()->beforeStart($this->createMock(Stat::class), null, 1.0, 0, 0);

        $this->assertSame([], $this->startedIds);
    }

    /**
     * @return StatProfiler
     */
    private function createPlugin(): StatProfiler
    {
        $settings = new Settings();

        return new StatProfiler(new Guard($settings), new TimerId($settings));
    }

    /**
     * @return void
     */
    private function registerDriver(): void
    {
        $driver = $this->createMock(DriverInterface::class);
        $driver->method('start')
            ->willReturnCallback(function ($timerId): void {
                $this->startedIds[] = $timerId;
            });
        $driver->method('stop')
            ->willReturnCallback(function ($timerId): void {
                $this->stoppedIds[] = $timerId;
            });

        Profiler::add($driver);
    }
}
