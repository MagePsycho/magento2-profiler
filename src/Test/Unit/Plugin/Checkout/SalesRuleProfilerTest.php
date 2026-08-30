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

use Magento\Framework\Profiler;
use Magento\Framework\Profiler\DriverInterface;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\Validator;
use MagePsycho\Profiler\Model\Instrumentation\Guard;
use MagePsycho\Profiler\Model\Instrumentation\Settings;
use MagePsycho\Profiler\Model\Instrumentation\Timer;
use MagePsycho\Profiler\Model\Instrumentation\TimerId;
use MagePsycho\Profiler\Plugin\Checkout\SalesRuleProfiler;
use PHPUnit\Framework\TestCase;

class SalesRuleProfilerTest extends TestCase
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
     * The rule id is what lets one row be traced back to one row in the admin grid and switched off.
     *
     * @return void
     */
    public function testTheRuleIdIsTheDetail(): void
    {
        $this->registerDriver();

        $this->process($this->rule(12));

        $this->assertSame(['RULE:process (12)'], $this->startedIds);
    }

    /**
     * A validator should never be handed an unsaved rule, but third-party code can, and an id-less
     * row beats a fatal inside a profiler.
     *
     * @return void
     */
    public function testAnUnsavedRuleStillReports(): void
    {
        $this->registerDriver();

        $this->process($this->rule(null));

        $this->assertSame(['RULE:process (unsaved)'], $this->startedIds);
    }

    /**
     * Shipping discounts are a second pass over the same rules, against the address.
     *
     * @return void
     */
    public function testShippingAmountIsTimedSeparately(): void
    {
        $this->registerDriver();

        $this->createPlugin()->aroundProcessShippingAmount(
            $this->createMock(Validator::class),
            static function () {
                return null;
            },
            $this->createMock(Address::class)
        );

        $this->assertSame(['RULE:processShippingAmount'], $this->startedIds);
    }

    /**
     * @return void
     */
    public function testOffModeRecordsNothing(): void
    {
        putenv('MAGE_PROFILER_CHECKOUT=0');
        $this->registerDriver();

        $this->process($this->rule(12));

        $this->assertSame([], $this->startedIds);
    }

    /**
     * @param Rule $rule
     * @return void
     */
    private function process(Rule $rule): void
    {
        $this->createPlugin()->aroundProcess(
            $this->createMock(Validator::class),
            static function () {
                return null;
            },
            $this->createMock(AbstractItem::class),
            $rule
        );
    }

    /**
     * @param int|null $id
     * @return Rule
     */
    private function rule(?int $id): Rule
    {
        $rule = $this->createMock(Rule::class);
        $rule->method('getId')->willReturn($id);

        return $rule;
    }

    /**
     * @return SalesRuleProfiler
     */
    private function createPlugin(): SalesRuleProfiler
    {
        $settings = new Settings();

        return new SalesRuleProfiler(new Guard($settings), new Timer(), new TimerId($settings));
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
