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

namespace MagePsycho\Profiler\Test\Unit\Plugin\Checkout;

use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Address\Total;
use Magento\Quote\Model\Quote\Address\Total\CollectorInterface;
use Magento\Quote\Model\Quote\TotalsCollector;
use Magento\Framework\Profiler;
use Magento\Framework\Profiler\DriverInterface;
use MagePsycho\Profiler\Model\Instrumentation\Guard;
use MagePsycho\Profiler\Model\Instrumentation\Settings;
use MagePsycho\Profiler\Model\Instrumentation\Timer;
use MagePsycho\Profiler\Model\Instrumentation\TimerId;
use MagePsycho\Profiler\Plugin\Checkout\TotalCollectorProfiler;
use MagePsycho\Profiler\Plugin\Checkout\TotalsProfiler;
use PHPUnit\Framework\TestCase;

class TotalsProfilerTest extends TestCase
{
    /**
     * @var string[]
     */
    private $startedIds = [];

    protected function setUp(): void
    {
        $this->startedIds = [];
        Profiler::reset();
        putenv('MAGE_PROFILER_CHECKOUT');
    }

    protected function tearDown(): void
    {
        Profiler::reset();
        putenv('MAGE_PROFILER_CHECKOUT');
    }

    /**
     * @return void
     */
    public function testTheWholePassIsTimed(): void
    {
        $this->registerDriver();

        $result = $this->createPlugin()->aroundCollectAddressTotals(
            $this->createMock(TotalsCollector::class),
            static function () {
                return 'totals';
            },
            $this->createMock(Quote::class),
            $this->createMock(Address::class)
        );

        $this->assertSame('totals', $result);
        $this->assertSame(['TOTALS:collectAddressTotals'], $this->startedIds);
    }

    /**
     * The per-collector row is the point: without it a slow checkout is one large number with
     * nothing to attribute it to.
     *
     * @return void
     */
    public function testEachCollectorIsNamedAfterItsClass(): void
    {
        $this->registerDriver();

        $this->collect($this->createMock(CollectorInterface::class));

        $this->assertCount(1, $this->startedIds);
        $this->assertStringStartsWith('TOTALS:', $this->startedIds[0]);
        $this->assertStringNotContainsString('collectAddressTotals', $this->startedIds[0]);
    }

    /**
     * Interceptors are an implementation detail; the report names the class the developer wrote.
     *
     * @return void
     */
    public function testInterceptorSuffixIsNotReported(): void
    {
        $this->registerDriver();

        $this->collect($this->createMock(CollectorInterface::class));

        $this->assertStringNotContainsString('Interceptor', $this->startedIds[0]);
    }

    /**
     * @return void
     */
    public function testOffModeRecordsNothing(): void
    {
        putenv('MAGE_PROFILER_CHECKOUT=0');
        $this->registerDriver();

        $this->collect($this->createMock(CollectorInterface::class));

        $this->assertSame([], $this->startedIds);
    }

    /**
     * A collector that throws must still close its timer, or every later row nests under it.
     *
     * @return void
     */
    public function testTimerIsClosedWhenACollectorThrows(): void
    {
        $this->registerDriver();

        $collectorPlugin = $this->createCollectorPlugin();
        $subject         = $this->createMock(CollectorInterface::class);

        try {
            $collectorPlugin->aroundCollect(
                $subject,
                [$this, 'throwBoom'],
                $this->createMock(Quote::class),
                $this->createMock(ShippingAssignmentInterface::class),
                $this->createMock(Total::class)
            );
            $this->fail('Expected the exception to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->collect($subject);

        $this->assertCount(2, $this->startedIds);
        $this->assertSame($this->startedIds[0], $this->startedIds[1]);
    }

    /**
     * @return void
     * @throws \RuntimeException
     */
    public function throwBoom(): void
    {
        throw new \RuntimeException('boom');
    }

    /**
     * @param CollectorInterface $subject
     * @return void
     */
    private function collect(CollectorInterface $subject): void
    {
        $this->createCollectorPlugin()->aroundCollect(
            $subject,
            static function () {
                return null;
            },
            $this->createMock(Quote::class),
            $this->createMock(ShippingAssignmentInterface::class),
            $this->createMock(Total::class)
        );
    }

    /**
     * @return TotalCollectorProfiler
     */
    private function createCollectorPlugin(): TotalCollectorProfiler
    {
        $settings = new Settings();

        return new TotalCollectorProfiler(new Guard($settings), new Timer(), new TimerId($settings));
    }

    /**
     * @return TotalsProfiler
     */
    private function createPlugin(): TotalsProfiler
    {
        $settings = new Settings();

        return new TotalsProfiler(new Guard($settings), new Timer(), new TimerId($settings));
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

        Profiler::add($driver);
    }
}
