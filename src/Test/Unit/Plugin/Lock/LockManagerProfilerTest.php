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

namespace MagePsycho\Profiler\Test\Unit\Plugin\Lock;

use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Profiler;
use Magento\Framework\Profiler\DriverInterface;
use MagePsycho\Profiler\Model\Instrumentation\Guard;
use MagePsycho\Profiler\Model\Instrumentation\Settings;
use MagePsycho\Profiler\Model\Instrumentation\Timer;
use MagePsycho\Profiler\Model\Instrumentation\TimerId;
use MagePsycho\Profiler\Plugin\Lock\LockManagerProfiler;
use PHPUnit\Framework\TestCase;

class LockManagerProfilerTest extends TestCase
{
    /**
     * @var string[]
     */
    private $startedIds = [];

    protected function setUp(): void
    {
        $this->startedIds = [];
        Profiler::reset();
        putenv('MAGE_PROFILER_LOCK');
    }

    protected function tearDown(): void
    {
        Profiler::reset();
        putenv('MAGE_PROFILER_LOCK');
    }

    /**
     * Lock names are per cache entry and per price context, so only the family reaches the id.
     *
     * @return void
     */
    public function testLockNamesAreReducedToTheirFamily(): void
    {
        $this->registerDriver();

        $plugin  = $this->createPlugin();
        $subject = $this->createMock(LockManagerInterface::class);
        $call    = static function () {
            return true;
        };

        $plugin->aroundLock($subject, $call, 'CUSTOM_BLOCK_5a41e1d6c8f391cd2a65f9e7d21524c5', 10);
        $plugin->aroundUnlock($subject, $call, 'CUSTOM_BLOCK_5a41e1d6c8f391cd2a65f9e7d21524c5');

        $this->assertSame(
            ['LOCK:lock (CUSTOM_BLOCK)', 'LOCK:unlock (CUSTOM_BLOCK)'],
            $this->startedIds
        );
    }

    /**
     * Regression: Lock\Proxy implements the interface and forwards to the configured provider, which
     * implements it too. Both are interceptors, so the guard is what stops every lock being timed
     * twice, nested inside itself.
     *
     * @return void
     */
    public function testNestedManagersAreNotTimedTwice(): void
    {
        $this->registerDriver();

        $plugin  = $this->createPlugin();
        $subject = $this->createMock(LockManagerInterface::class);

        /* The inner call is what a delegating implementation does. */
        $plugin->aroundLock($subject, function () use ($plugin, $subject) {
            return $plugin->aroundLock($subject, static function () {
                return true;
            }, 'CATALOG_RULE', 10);
        }, 'CATALOG_RULE', 10);

        $this->assertSame(['LOCK:lock (CATALOG_RULE)'], $this->startedIds);
    }

    /**
     * @return void
     */
    public function testOffModeRecordsNothing(): void
    {
        putenv('MAGE_PROFILER_LOCK=0');
        $this->registerDriver();

        $this->createPlugin()->aroundLock($this->createMock(LockManagerInterface::class), static function () {
            return true;
        }, 'CATALOG_RULE', 10);

        $this->assertSame([], $this->startedIds);
    }

    /**
     * A provider that throws must still close its timer and release the guard.
     *
     * @return void
     */
    public function testGuardIsReleasedWhenTheProviderThrows(): void
    {
        $this->registerDriver();

        $plugin  = $this->createPlugin();
        $subject = $this->createMock(LockManagerInterface::class);

        try {
            $plugin->aroundLock($subject, [$this, 'throwBoom'], 'CATALOG_RULE', 10);
            $this->fail('Expected the exception to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $plugin->aroundUnlock($subject, static function () {
            return true;
        }, 'CATALOG_RULE');

        $this->assertSame(['LOCK:lock (CATALOG_RULE)', 'LOCK:unlock (CATALOG_RULE)'], $this->startedIds);
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
     * @return LockManagerProfiler
     */
    private function createPlugin(): LockManagerProfiler
    {
        $settings = new Settings();

        return new LockManagerProfiler(new Guard($settings), new Timer(), new TimerId($settings));
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
