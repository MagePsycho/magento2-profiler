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

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\TotalsCollector;
use MagePsycho\Profiler\Model\Instrumentation\Guard;
use MagePsycho\Profiler\Model\Instrumentation\Settings;
use MagePsycho\Profiler\Model\Instrumentation\Timer;
use MagePsycho\Profiler\Model\Instrumentation\TimerId;

/**
 * Times a whole quote totals pass: "TOTALS:collectAddressTotals".
 *
 * Totals collection is the single most expensive thing a checkout does, and it runs again on every
 * quote change - every qty edit, every address change, every coupon attempt. It is also the classic
 * place a third-party extension quietly costs a second: collectors are configured in sales.xml and
 * run in sequence, so one slow one is charged to a page that looks like it is doing nothing.
 *
 * This is the parent row: what the whole pass cost. TotalCollectorProfiler supplies the per-collector
 * rows underneath it, and is a separate class because Magento matches plugin methods by name and this
 * subject has a collect() of its own with an unrelated signature.
 */
class TotalsProfiler
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
     * The whole pass over one address.
     *
     * @param TotalsCollector $subject
     * @param callable $proceed
     * @param Quote $quote
     * @param Address $address
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundCollectAddressTotals(
        TotalsCollector $subject,
        callable $proceed,
        Quote $quote,
        Address $address
    ) {
        if (!$this->guard->isActive(Settings::AREA_CHECKOUT)) {
            return $proceed($quote, $address);
        }

        return $this->timer->measure(
            $this->timerId->build(self::PREFIX, 'collectAddressTotals'),
            static function () use ($proceed, $quote, $address) {
                return $proceed($quote, $address);
            }
        );
    }
}
