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

use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Shipping\Model\Shipping;
use MagePsycho\Profiler\Model\Instrumentation\Guard;
use MagePsycho\Profiler\Model\Instrumentation\Settings;
use MagePsycho\Profiler\Model\Instrumentation\Timer;
use MagePsycho\Profiler\Model\Instrumentation\TimerId;

/**
 * Times shipping rate collection: "SHIPPING:collectRates" and "SHIPPING:collectCarrierRates (ups)".
 *
 * Rate collection runs every carrier in sequence, and a live carrier means an HTTP call to UPS, DHL,
 * FedEx or a rate aggregator - inside the request, while the customer waits. One unreachable carrier
 * timing out is the usual explanation for a checkout step that takes ten seconds for no visible
 * reason, and nothing else in a profile attributes that time to a carrier.
 *
 * The per-carrier row is the point. `HTTP:` already reports the outbound call, but by host, and a
 * rate aggregator answers for several carriers on one host; the carrier code is what tells you which
 * one to switch off. The parent row also captures the carriers that are slow *without* a network
 * call - a table-rate lookup over a large table, or a carrier doing its own quote arithmetic.
 */
class ShippingProfiler
{
    private const PREFIX = 'SHIPPING';

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
     * @param Shipping $subject
     * @param callable $proceed
     * @param RateRequest $request
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundCollectRates(Shipping $subject, callable $proceed, RateRequest $request)
    {
        if (!$this->guard->isActive(Settings::AREA_CHECKOUT)) {
            return $proceed($request);
        }

        return $this->timer->measure(
            $this->timerId->build(self::PREFIX, 'collectRates'),
            static function () use ($proceed, $request) {
                return $proceed($request);
            }
        );
    }

    /**
     * @param Shipping $subject
     * @param callable $proceed
     * @param string $carrierCode
     * @param mixed $request
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundCollectCarrierRates(Shipping $subject, callable $proceed, $carrierCode, $request)
    {
        if (!$this->guard->isActive(Settings::AREA_CHECKOUT)) {
            return $proceed($carrierCode, $request);
        }

        return $this->timer->measure(
            $this->timerId->build(self::PREFIX, 'collectCarrierRates', (string)$carrierCode),
            static function () use ($proceed, $carrierCode, $request) {
                return $proceed($carrierCode, $request);
            }
        );
    }
}
