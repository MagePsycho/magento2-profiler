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

namespace MagePsycho\Profiler\Plugin\Checkout;

use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\Total;
use Magento\Quote\Model\Quote\Address\Total\CollectorInterface;
use MagePsycho\Profiler\Model\Instrumentation\Guard;
use MagePsycho\Profiler\Model\Instrumentation\Settings;
use MagePsycho\Profiler\Model\Instrumentation\Timer;
use MagePsycho\Profiler\Model\Instrumentation\TimerId;

/**
 * Times one quote totals collector: "TOTALS:Total\Shipping".
 *
 * Totals collection is the single most expensive thing a checkout does, and it runs again on every
 * quote change - every qty edit, every address change, every coupon attempt. It is also the classic
 * place a third-party extension quietly costs a second: collectors are configured in sales.xml and
 * run in sequence, so one slow one is charged to a page that looks like it is doing nothing.
 *
 * The companion to TotalsProfiler, which times the whole pass. Without this one a slow checkout is a
 * single large number with nothing to attribute it to, and a wall of SQL rows underneath that never
 * names the collector that issued them.
 *
 * Wired on CollectorInterface, not on AbstractTotal: a collector is only required to implement the
 * interface, and hooking the abstract class would silently miss any that do not extend it - which,
 * being third-party, are exactly the ones worth timing.
 *
 * A class of its own rather than a second method on TotalsProfiler. Magento matches plugin methods by
 * name against whatever type the plugin is declared on, and TotalsCollector has a collect() of its own
 * with an entirely different signature - so one class serving both types has aroundCollect() applied
 * to both, and the wrong one fatals on the type hint.
 */
class TotalCollectorProfiler
{
    private const PREFIX = 'TOTALS';

    /**
     * @var Guard
     */
    private $guard;

    /**
     * @var Timer
     */
    private $timer;

    /**
     * @var TimerId
     */
    private $timerId;

    /**
     * @param Guard $guard
     * @param Timer $timer
     * @param TimerId $timerId
     */
    public function __construct(Guard $guard, Timer $timer, TimerId $timerId)
    {
        $this->guard   = $guard;
        $this->timer   = $timer;
        $this->timerId = $timerId;
    }

    /**
     * One collector, named after the class that implements it.
     *
     * @param CollectorInterface $subject
     * @param callable $proceed
     * @param Quote $quote
     * @param ShippingAssignmentInterface $shippingAssignment
     * @param Total $total
     * @return mixed
     */
    public function aroundCollect(
        CollectorInterface $subject,
        callable $proceed,
        Quote $quote,
        ShippingAssignmentInterface $shippingAssignment,
        Total $total
    ) {
        if (!$this->guard->isActive(Settings::AREA_CHECKOUT)) {
            return $proceed($quote, $shippingAssignment, $total);
        }

        return $this->timer->measure(
            $this->timerId->build(self::PREFIX, $this->timerId->shortClass(get_class($subject), 2)),
            static function () use ($proceed, $quote, $shippingAssignment, $total) {
                return $proceed($quote, $shippingAssignment, $total);
            }
        );
    }
}
