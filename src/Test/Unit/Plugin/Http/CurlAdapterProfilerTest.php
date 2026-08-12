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

namespace MagePsycho\Profiler\Test\Unit\Plugin\Http;

use Magento\Framework\HTTP\Adapter\Curl;
use Magento\Framework\Profiler;
use Magento\Framework\Profiler\DriverInterface;
use MagePsycho\Profiler\Model\Instrumentation\Guard;
use MagePsycho\Profiler\Model\Instrumentation\Settings;
use MagePsycho\Profiler\Model\Instrumentation\Timer;
use MagePsycho\Profiler\Model\Instrumentation\TimerId;
use MagePsycho\Profiler\Plugin\Http\CurlAdapterProfiler;
use PHPUnit\Framework\TestCase;

class CurlAdapterProfilerTest extends TestCase
{
    private const URL = 'https://gateway.example.com/v1/charge?api_key=SECRET';

    /**
     * @var string[]
     */
    private $startedIds = [];

    protected function setUp(): void
    {
        $this->startedIds = [];
        Profiler::reset();
        putenv('MAGE_PROFILER_HTTP');
    }

    protected function tearDown(): void
    {
        Profiler::reset();
        putenv('MAGE_PROFILER_HTTP');
    }

    /**
     * The wait is in read(), and the url is only known from write() - so the two calls together make
     * one timer, named for the host alone.
     *
     * @return void
     */
    public function testTheRequestIsTimedOnReadAndNamedByHost(): void
    {
        $this->registerDriver();

        $plugin  = $this->createPlugin();
        $subject = $this->createMock(Curl::class);

        $plugin->beforeWrite($subject, 'POST', self::URL);
        $plugin->aroundRead($subject, static function () {
            return 'HTTP/1.1 200 OK';
        });

        $this->assertSame(['HTTP:POST (gateway.example.com)'], $this->startedIds);
    }

    /**
     * A read with no write behind it belongs to no known host, so it is left untimed rather than
     * attributed to the previous one.
     *
     * @return void
     */
    public function testReadWithoutWriteIsNotTimed(): void
    {
        $this->registerDriver();

        $this->createPlugin()->aroundRead($this->createMock(Curl::class), static function () {
            return '';
        });

        $this->assertSame([], $this->startedIds);
    }

    /**
     * The pending request is consumed, so a second read cannot borrow the first one's name.
     *
     * @return void
     */
    public function testPendingRequestIsNotReused(): void
    {
        $this->registerDriver();

        $plugin  = $this->createPlugin();
        $subject = $this->createMock(Curl::class);
        $call    = static function () {
            return '';
        };

        $plugin->beforeWrite($subject, 'GET', self::URL);
        $plugin->aroundRead($subject, $call);
        $plugin->aroundRead($subject, $call);

        $this->assertSame(['HTTP:GET (gateway.example.com)'], $this->startedIds);
    }

    /**
     * @return void
     */
    public function testOffModeRecordsNothing(): void
    {
        putenv('MAGE_PROFILER_HTTP=0');
        $this->registerDriver();

        $plugin  = $this->createPlugin();
        $subject = $this->createMock(Curl::class);

        $plugin->beforeWrite($subject, 'GET', self::URL);
        $plugin->aroundRead($subject, static function () {
            return '';
        });

        $this->assertSame([], $this->startedIds);
    }

    /**
     * @return CurlAdapterProfiler
     */
    private function createPlugin(): CurlAdapterProfiler
    {
        $settings = new Settings();

        return new CurlAdapterProfiler(new Guard($settings), new Timer(), new TimerId($settings));
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
