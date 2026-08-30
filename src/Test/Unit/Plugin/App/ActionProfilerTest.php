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

namespace MagePsycho\Profiler\Test\Unit\Plugin\App;

use Magento\Framework\App\Action\Action as LegacyAction;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Profiler;
use Magento\Framework\Profiler\DriverInterface;
use MagePsycho\Profiler\Model\Instrumentation\Guard;
use MagePsycho\Profiler\Model\Instrumentation\Settings;
use MagePsycho\Profiler\Model\Instrumentation\Timer;
use MagePsycho\Profiler\Model\Instrumentation\TimerId;
use MagePsycho\Profiler\Plugin\App\ActionProfiler;
use PHPUnit\Framework\TestCase;

class ActionProfilerTest extends TestCase
{
    /**
     * @var string[]
     */
    private $startedIds = [];

    protected function setUp(): void
    {
        $this->startedIds = [];
        Profiler::reset();
    }

    protected function tearDown(): void
    {
        Profiler::reset();
    }

    /**
     * The route as configured is the useful label - "checkout_index_index" says where you are.
     *
     * @return void
     */
    public function testFullActionNameBecomesTheTimerId(): void
    {
        $this->registerDriver();

        $plugin = $this->createPlugin($this->createHttpRequest('checkout_index_index'));

        $result = $plugin->aroundExecute($this->createMock(ActionInterface::class), static function () {
            return 'page';
        });

        $this->assertSame('page', $result);
        $this->assertSame(['CONTROLLER:checkout_index_index'], $this->startedIds);
    }

    /**
     * getFullActionName() is empty until the router has matched, and absent entirely on a non-HTTP
     * request. Reporting an empty id would be worse than reporting the class.
     *
     * @return void
     */
    public function testFallsBackToTheClassWhenNoActionNameIsAvailable(): void
    {
        $this->registerDriver();

        $plugin  = $this->createPlugin($this->createMock(RequestInterface::class));
        $subject = $this->createMock(ActionInterface::class);

        $plugin->aroundExecute($subject, static function () {
            return null;
        });

        $this->assertCount(1, $this->startedIds);
        $this->assertStringStartsWith('CONTROLLER:', $this->startedIds[0]);
        $this->assertStringNotContainsString('CONTROLLER:unknown', $this->startedIds[0]);
    }

    /**
     * @return void
     */
    public function testEmptyActionNameFallsBackToTheClass(): void
    {
        $this->registerDriver();

        $plugin = $this->createPlugin($this->createHttpRequest(''));

        $plugin->aroundExecute($this->createMock(ActionInterface::class), static function () {
            return null;
        });

        $this->assertCount(1, $this->startedIds);
        $this->assertStringStartsWith('CONTROLLER:', $this->startedIds[0]);
    }

    /**
     * Regression: core's Action::dispatch() already opens CONTROLLER_ACTION:<name> and calls execute()
     * inside it. Timing execute() as well would report the same work twice under two names.
     *
     * @return void
     */
    public function testLegacyActionsAreLeftToCore(): void
    {
        $this->registerDriver();

        $plugin  = $this->createPlugin($this->createHttpRequest('catalog_product_view'));
        $subject = $this->createMock(LegacyAction::class);

        $result = $plugin->aroundExecute($subject, static function () {
            return 'legacy';
        });

        $this->assertSame('legacy', $result);
        $this->assertSame([], $this->startedIds);
    }

    /**
     * @return void
     */
    public function testNothingIsRecordedWhenTheProfilerIsOff(): void
    {
        /* No driver registered, so Profiler::isEnabled() is false. */
        $plugin = $this->createPlugin($this->createHttpRequest('cms_index_index'));

        $result = $plugin->aroundExecute($this->createMock(ActionInterface::class), static function () {
            return 'home';
        });

        $this->assertSame('home', $result);
        $this->assertSame([], $this->startedIds);
    }

    /**
     * An action that throws must still close its timer.
     *
     * @return void
     */
    public function testTimerIsClosedWhenTheActionThrows(): void
    {
        $this->registerDriver();

        $plugin = $this->createPlugin($this->createHttpRequest('checkout_cart_index'));

        try {
            $plugin->aroundExecute($this->createMock(ActionInterface::class), [$this, 'throwBoom']);
            $this->fail('Expected the exception to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        /* A leaked timer would nest the next id underneath this one. */
        $plugin->aroundExecute($this->createMock(ActionInterface::class), static function () {
            return null;
        });

        $this->assertSame(
            ['CONTROLLER:checkout_cart_index', 'CONTROLLER:checkout_cart_index'],
            $this->startedIds
        );
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
     * @param string $fullActionName
     * @return HttpRequest
     */
    private function createHttpRequest(string $fullActionName): HttpRequest
    {
        $request = $this->createMock(HttpRequest::class);
        $request->method('getFullActionName')->willReturn($fullActionName);

        return $request;
    }

    /**
     * @param RequestInterface $request
     * @return ActionProfiler
     */
    private function createPlugin(RequestInterface $request): ActionProfiler
    {
        $settings = new Settings();

        return new ActionProfiler(new Guard($settings), new Timer(), new TimerId($settings), $request);
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
