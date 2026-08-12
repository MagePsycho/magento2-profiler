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

namespace MagePsycho\Profiler\Test\Unit\Model\Cache;

use Magento\Framework\Cache\FrontendInterface;
use Magento\Framework\Profiler;
use Magento\Framework\Profiler\DriverInterface;
use MagePsycho\Profiler\Model\Cache\ProfilerDecorator;
use MagePsycho\Profiler\Model\Instrumentation\Settings;
use PHPUnit\Framework\TestCase;

class ProfilerDecoratorTest extends TestCase
{
    /**
     * @var string[]
     */
    private $startedIds = [];

    protected function setUp(): void
    {
        $this->startedIds = [];
        Profiler::reset();
        putenv('MAGE_PROFILER_CACHE');
        putenv('MAGE_PROFILER_REDIS');
    }

    protected function tearDown(): void
    {
        Profiler::reset();
        putenv('MAGE_PROFILER_CACHE');
        putenv('MAGE_PROFILER_REDIS');
    }

    /**
     * The id names the backend and the key family, not the raw cache id.
     *
     * @return void
     */
    public function testTimerIdCarriesTheBackendAndTheKeyFamily(): void
    {
        $this->registerDriver();

        $this->createDecorator($this->createMock(FrontendInterface::class))->load('CAT_P_828');

        $this->assertSame(['CACHE:load (CAT_P)'], $this->startedIds);
    }

    /**
     * Regression: Magento 2.4.9's Cache\Frontend\Factory applies its decorator list twice - once in
     * createSymfonyCache() and again in create() - so this decorator gets built wrapping itself and
     * every cache call would otherwise be reported twice, nested inside itself.
     *
     * @return void
     */
    public function testAnOuterInstanceDoesNotTimeTheSameCallTwice(): void
    {
        $this->registerDriver();

        $inner = $this->createDecorator($this->createMock(FrontendInterface::class));
        $outer = $this->createDecorator($inner);

        $outer->load('CAT_P_828');

        $this->assertSame(
            ['CACHE:load (CAT_P)'],
            $this->startedIds,
            'Only the innermost decorator may record the call'
        );
    }

    /**
     * @return void
     */
    public function testOffModeRecordsNothing(): void
    {
        putenv('MAGE_PROFILER_CACHE=0');
        $this->registerDriver();

        $this->createDecorator($this->createMock(FrontendInterface::class))->load('CAT_P_828');

        $this->assertSame([], $this->startedIds);
    }

    /**
     * The client swap must never run for a frontend that has no Redis behind it.
     *
     * @return void
     */
    public function testANonRedisFrontendIsTimedWithTheFallbackPrefix(): void
    {
        $this->registerDriver();

        $this->createDecorator($this->createMock(FrontendInterface::class))->save('v', 'CONFIG_SCOPES');

        $this->assertSame(['CACHE:save (CONFIG_SCOPES)'], $this->startedIds);
    }

    /**
     * @param FrontendInterface $frontend
     * @return ProfilerDecorator
     */
    private function createDecorator(FrontendInterface $frontend): ProfilerDecorator
    {
        return new ProfilerDecorator($frontend, new Settings());
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
