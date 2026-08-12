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

use Laminas\Http\Request;
use Magento\Framework\HTTP\LaminasClient;
use Magento\Framework\Profiler;
use Magento\Framework\Profiler\DriverInterface;
use MagePsycho\Profiler\Model\Instrumentation\Guard;
use MagePsycho\Profiler\Model\Instrumentation\Settings;
use MagePsycho\Profiler\Model\Instrumentation\Timer;
use MagePsycho\Profiler\Model\Instrumentation\TimerId;
use MagePsycho\Profiler\Plugin\Http\LaminasClientProfiler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LaminasClientProfilerTest extends TestCase
{
    private const URL = 'https://payflowpro.paypal.com/transaction?PARTNER=SECRET';

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
     * The host only - a Payflow URL carries the partner credentials in its query string.
     *
     * @return void
     */
    public function testTimerIdCarriesTheMethodAndHostOnly(): void
    {
        $this->registerDriver();

        $this->send($this->request('POST', self::URL));

        $this->assertSame(['HTTP:POST (payflowpro.paypal.com)'], $this->startedIds);
    }

    /**
     * send() takes an optional request; without one the client's own state is what gets sent, so that
     * is what the timer id has to describe.
     *
     * @return void
     */
    public function testClientStateIsUsedWhenSendIsCalledWithoutARequest(): void
    {
        $this->registerDriver();

        $subject = $this->createMock(LaminasClient::class);
        $subject->method('getMethod')->willReturn('GET');
        $subject->method('getUri')->willReturn('https://api.dhl.com/rates?key=SECRET');

        $this->createPlugin()->aroundSend($subject, static function () {
        });

        $this->assertSame(['HTTP:GET (api.dhl.com)'], $this->startedIds);
    }

    /**
     * A gateway that throws must still close its timer and release the area guard, or every later call
     * nests underneath the failed one - or stops being recorded at all.
     *
     * @return void
     */
    public function testTimerIsClosedAndGuardReleasedWhenTheCallThrows(): void
    {
        $this->registerDriver();

        $plugin = $this->createPlugin();

        try {
            $plugin->aroundSend($this->subject(), [$this, 'throwBoom'], $this->request('POST', self::URL));
            $this->fail('Expected the exception to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $plugin->aroundSend($this->subject(), static function () {
        }, $this->request('GET', 'https://other.example.com/ping'));

        $this->assertSame(
            ['HTTP:POST (payflowpro.paypal.com)', 'HTTP:GET (other.example.com)'],
            $this->startedIds
        );
    }

    /**
     * MAGE_PROFILER_HTTP=0 covers this client as well - one switch for all outbound traffic.
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

        $this->send($this->request('POST', self::URL));

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

        $this->send($this->request('POST', self::URL));

        $this->assertSame([], $this->startedIds);
    }

    /**
     * The response has to reach the caller untouched - this plugin only wraps a timer around it.
     *
     * @return void
     */
    public function testTheResponseIsReturnedUnchanged(): void
    {
        $this->registerDriver();

        $response = new \stdClass();
        $actual   = $this->createPlugin()->aroundSend(
            $this->subject(),
            static function () use ($response) {
                return $response;
            },
            $this->request('POST', self::URL)
        );

        $this->assertSame($response, $actual);
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
     * @param Request $request
     * @return void
     */
    private function send(Request $request): void
    {
        $this->createPlugin()->aroundSend($this->subject(), static function () {
        }, $request);
    }

    /**
     * @param string $method
     * @param string $uri
     * @return Request
     */
    private function request(string $method, string $uri): Request
    {
        $request = new Request();
        $request->setMethod($method);
        $request->setUri($uri);

        return $request;
    }

    /**
     * @return LaminasClient
     */
    private function subject(): LaminasClient
    {
        return $this->createMock(LaminasClient::class);
    }

    /**
     * A plugin with freshly built collaborators, so cached env values never leak between tests.
     *
     * @return LaminasClientProfiler
     */
    private function createPlugin(): LaminasClientProfiler
    {
        $settings = new Settings();

        return new LaminasClientProfiler(new Guard($settings), new Timer(), new TimerId($settings));
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
