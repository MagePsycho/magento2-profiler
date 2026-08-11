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

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Profiler;
use Magento\Framework\Profiler\DriverInterface;
use MagePsycho\Profiler\Model\Instrumentation\Guard;
use MagePsycho\Profiler\Model\Instrumentation\Settings;
use MagePsycho\Profiler\Model\Instrumentation\Timer;
use MagePsycho\Profiler\Model\Instrumentation\TimerId;
use MagePsycho\Profiler\Plugin\Http\CurlProfiler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CurlProfilerTest extends TestCase
{
    private const URL = 'https://api.example.com/v1/orders?api_key=SECRET';

    /**
     * @var string[]
     */
    private $startedIds = [];

    protected function setUp(): void
    {
        $this->startedIds = [];
        Profiler::reset();
        putenv('MAGE_PROFILER_HTTP');
        putenv('MAGE_PROFILER_MAX_DETAIL');
    }

    protected function tearDown(): void
    {
        Profiler::reset();
        putenv('MAGE_PROFILER_HTTP');
        putenv('MAGE_PROFILER_MAX_DETAIL');
    }

    /**
     * The host only - the query string carries an API key and the report outlives the request.
     *
     * @return void
     */
    public function testTimerIdCarriesTheHostOnly(): void
    {
        $this->registerDriver();

        $this->runGet();

        $this->assertSame(['HTTP:GET (api.example.com)'], $this->startedIds);
    }

    /**
     * @return void
     */
    public function testPostIsTimedSeparately(): void
    {
        $this->registerDriver();

        $this->createPlugin()->aroundPost($this->subject(), static function () {
        }, self::URL, ['a' => 1]);

        $this->assertSame(['HTTP:POST (api.example.com)'], $this->startedIds);
    }

    /**
     * A client that throws must still close its timer and release the area guard, or every later
     * call nests underneath the failed one - or stops being recorded at all.
     *
     * @return void
     */
    public function testTimerIsClosedAndGuardReleasedWhenTheCallThrows(): void
    {
        $this->registerDriver();

        $plugin = $this->createPlugin();

        try {
            $plugin->aroundGet($this->subject(), [$this, 'throwBoom'], self::URL);
            $this->fail('Expected the exception to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $plugin->aroundGet($this->subject(), static function () {
        }, 'https://other.example.com/ping');

        $this->assertSame(
            ['HTTP:GET (api.example.com)', 'HTTP:GET (other.example.com)'],
            $this->startedIds
        );
    }

    /**
     * MAGE_PROFILER_HTTP=0 switches outbound timing off without touching the rest of the profiler.
     *
     * @param string $value
     * @return void
     * @dataProvider offValueDataProvider
     */
    #[DataProvider('offValueDataProvider')]
    public function testOffModeRecordsNothing(string $value): void
    {
        putenv('MAGE_PROFILER_HTTP=' . $value);
        $this->registerDriver();

        $this->runGet();

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
     * @return void
     */
    public function testNothingIsRecordedWhileProfilerDisabled(): void
    {
        $this->registerDriver();
        Profiler::disable();

        $this->runGet();

        $this->assertSame([], $this->startedIds);
    }

    /**
     * Callback used by testTimerIsClosedAndGuardReleasedWhenTheCallThrows().
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
     * @return void
     */
    private function runGet(): void
    {
        $this->createPlugin()->aroundGet($this->subject(), static function () {
        }, self::URL);
    }

    /**
     * @return Curl
     */
    private function subject(): Curl
    {
        return $this->createMock(Curl::class);
    }

    /**
     * A plugin with freshly built collaborators, so cached env values never leak between tests.
     *
     * @return CurlProfiler
     */
    private function createPlugin(): CurlProfiler
    {
        $settings = new Settings();

        return new CurlProfiler(new Guard($settings), new Timer(), new TimerId($settings));
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
