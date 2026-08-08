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

namespace MagePsycho\Profiler\Test\Unit\Util;

use Magento\Framework\Profiler;
use Magento\Framework\Profiler\DriverInterface;
use MagePsycho\Profiler\Util\Benchmark;
use PHPUnit\Framework\TestCase;

class BenchmarkTest extends TestCase
{
    /**
     * Timer ids handed to the driver, in call order.
     *
     * @var string[]
     */
    private $startedIds = [];

    protected function setUp(): void
    {
        $this->startedIds = [];
        Profiler::reset();
        $this->resetBenchmarkStack();
    }

    protected function tearDown(): void
    {
        Profiler::reset();
        $this->resetBenchmarkStack();
    }

    /**
     * Nothing must reach the profiler while it is disabled.
     *
     * @return void
     */
    public function testStartIsNoOpWhenProfilerDisabled(): void
    {
        $this->registerDriver();
        Profiler::disable();

        Benchmark::start('ignored');
        Benchmark::stop('ignored');

        $this->assertSame([], $this->startedIds);
    }

    /**
     * The nesting separator is rejected by Profiler::start(), so it must be normalised away.
     *
     * @return void
     */
    public function testStartNormalisesTheNestingSeparator(): void
    {
        $this->registerDriver();

        Benchmark::start('Vendor\Module\Class->method');
        Benchmark::stop('Vendor\Module\Class->method');

        $this->assertSame(['Vendor\Module\Class_method'], $this->startedIds);
    }

    /**
     * Whitespace is collapsed so the same block always reports under one id.
     *
     * @return void
     */
    public function testStartCollapsesWhitespace(): void
    {
        $this->registerDriver();

        Benchmark::start("  my   hook \n ");
        Benchmark::stop('my hook');

        $this->assertSame(['my hook'], $this->startedIds);
    }

    /**
     * Nested calls build the profiler's "parent->child" timer path.
     *
     * @return void
     */
    public function testNestedTimersBuildAPath(): void
    {
        $this->registerDriver();

        Benchmark::start('parent');
        Benchmark::start('child');
        Benchmark::stop('child');
        Benchmark::stop('parent');

        $this->assertSame(['parent', 'parent->child'], $this->startedIds);
    }

    /**
     * stop() without an identifier closes the most recently opened timer.
     *
     * @return void
     */
    public function testStopWithoutIdentifierClosesTheLastTimer(): void
    {
        $this->registerDriver();

        Benchmark::start('outer');
        Benchmark::start('inner');
        Benchmark::stop();
        Benchmark::start('sibling');
        Benchmark::stop();
        Benchmark::stop();

        $this->assertSame(['outer', 'outer->inner', 'outer->sibling'], $this->startedIds);
        $this->assertSame([], $this->getBenchmarkStack());
    }

    /**
     * stop() for a timer that was never started must be ignored, not throw.
     *
     * @return void
     */
    public function testStopWithUnknownIdentifierIsIgnored(): void
    {
        $this->registerDriver();

        Benchmark::start('known');
        Benchmark::stop('never-started');

        $this->assertSame(['known'], $this->getBenchmarkStack());
    }

    /**
     * Closing an outer timer also drops any of its still-open children from the stack.
     *
     * @return void
     */
    public function testEndingAnOuterTimerUnwindsOrphanedChildren(): void
    {
        $this->registerDriver();

        Benchmark::start('outer');
        Benchmark::start('forgotten');
        Benchmark::stop('outer');

        $this->assertSame([], $this->getBenchmarkStack());
    }

    /**
     * measure() returns the callback result and records the timer.
     *
     * @return void
     */
    public function testMeasureReturnsTheCallbackResult(): void
    {
        $this->registerDriver();

        $result = Benchmark::measure('work', static function () {
            return 42;
        });

        $this->assertSame(42, $result);
        $this->assertSame(['work'], $this->startedIds);
        $this->assertSame([], $this->getBenchmarkStack());
    }

    /**
     * measure() must close its timer even when the callback throws.
     *
     * @return void
     */
    public function testMeasureClosesTheTimerOnException(): void
    {
        $this->registerDriver();

        try {
            Benchmark::measure('failing', [$this, 'throwBoom']);
            $this->fail('Expected the exception to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(['failing'], $this->startedIds);
        $this->assertSame([], $this->getBenchmarkStack());
    }

    /**
     * An identifier that normalises to an empty string is skipped entirely.
     *
     * @return void
     */
    public function testBlankIdentifierIsSkipped(): void
    {
        $this->registerDriver();

        Benchmark::start('   ');

        $this->assertSame([], $this->startedIds);
        $this->assertSame([], $this->getBenchmarkStack());
    }

    /**
     * Callback used by testMeasureClosesTheTimerOnException().
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
     * @return string[]
     */
    private function getBenchmarkStack(): array
    {
        $property = new \ReflectionProperty(Benchmark::class, 'stack');
        $property->setAccessible(true);

        return $property->getValue();
    }

    /**
     * @return void
     */
    private function resetBenchmarkStack(): void
    {
        $property = new \ReflectionProperty(Benchmark::class, 'stack');
        $property->setAccessible(true);
        $property->setValue(null, []);
    }
}
