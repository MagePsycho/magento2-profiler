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

namespace MagePsycho\Profiler\Plugin\Http;

use Laminas\Http\Request;
use Magento\Framework\HTTP\LaminasClient;
use MagePsycho\Profiler\Model\Instrumentation\Guard;
use MagePsycho\Profiler\Model\Instrumentation\Settings;
use MagePsycho\Profiler\Model\Instrumentation\Timer;
use MagePsycho\Profiler\Model\Instrumentation\TimerId;

/**
 * Times the other outbound client: "HTTP:POST (payflowpro.paypal.com)".
 *
 * This is where the calls that matter most actually go. PayPal Payflow, USPS, DHL, the currency
 * imports and Payment\Gateway\Http\Client\Zend all build their client through LaminasClientFactory,
 * and none of it was recorded anywhere: LaminasClient extends Laminas\Http\Client and implements no
 * part of Framework\HTTP\ClientInterface, so CurlProfiler never sees it.
 *
 * Nor does CurlAdapterProfiler, despite LaminasClient::__construct configuring
 * Framework\HTTP\Adapter\Curl as its adapter. Laminas\Http\Client::setAdapter() instantiates the
 * adapter with a plain `new $adapter()`, so that object is never an ObjectManager interceptor and no
 * plugin on the adapter can fire for it. A DHL rate lookup therefore cost whatever it cost and
 * appeared in the profile as nothing at all.
 *
 * send() is the single hook point - every verb funnels through it - and it is public on the parent,
 * which the generated interceptor overrides like any other public method.
 *
 * Host only, for the reason CurlProfiler gives: query strings carry API keys and the report outlives
 * the request.
 */
class LaminasClientProfiler
{
    private const PREFIX = 'HTTP';

    /**
     * Laminas defaults an unset method to GET (Laminas\Http\Request::$method).
     */
    private const DEFAULT_METHOD = 'GET';

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
     * @param LaminasClient $subject
     * @param callable $proceed
     * @param Request|null $request
     * @return mixed
     */
    public function aroundSend(LaminasClient $subject, callable $proceed, ?Request $request = null)
    {
        $callback = static function () use ($proceed, $request) {
            return $proceed($request);
        };

        if (!$this->guard->isActive(Settings::AREA_HTTP) || !$this->guard->enter(Settings::AREA_HTTP)) {
            return $callback();
        }

        try {
            return $this->timer->measure(
                $this->timerId->build(
                    self::PREFIX,
                    $this->method($subject, $request),
                    $this->timerId->host($this->uri($subject, $request))
                ),
                $callback
            );
        } finally {
            $this->guard->leave(Settings::AREA_HTTP);
        }
    }

    /**
     * send() takes an optional request that overrides the one held on the client, so the argument
     * wins when it is there and the client's own state answers otherwise.
     *
     * @param LaminasClient $subject
     * @param Request|null $request
     * @return string
     */
    private function method(LaminasClient $subject, ?Request $request): string
    {
        $method = $request !== null ? $request->getMethod() : $subject->getMethod();

        return strtoupper((string)$method) ?: self::DEFAULT_METHOD;
    }

    /**
     * @param LaminasClient $subject
     * @param Request|null $request
     * @return string
     */
    private function uri(LaminasClient $subject, ?Request $request): string
    {
        /* Both sides are unset on a freshly constructed client; host() renders that as "unknown". */
        if ($request !== null) {
            return (string)$request->getUriString();
        }

        return (string)$subject->getUri();
    }
}
