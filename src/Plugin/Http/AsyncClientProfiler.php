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

use Magento\Framework\HTTP\AsyncClientInterface;
use MagePsycho\Profiler\Model\Instrumentation\Guard;
use MagePsycho\Profiler\Model\Instrumentation\Settings;
use MagePsycho\Profiler\Model\Instrumentation\Timer;
use MagePsycho\Profiler\Model\Instrumentation\TimerId;

/**
 * Times dispatch of async HTTP requests as "HTTP:async (api.example.com)".
 *
 * Note what this measures: the time to *hand off* the request, not the round trip. AsyncClientInterface
 * returns a deferred, and the wait happens wherever the caller resolves it. Treat a small number here as
 * "the call was queued", not "the call was fast".
 *
 * Host only - query strings carry credentials.
 */
class AsyncClientProfiler
{
    private const PREFIX = 'HTTP';

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
     * @param AsyncClientInterface $subject
     * @param callable $proceed
     * @param object $request
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundRequest(AsyncClientInterface $subject, callable $proceed, $request)
    {
        $call = static function () use ($proceed, $request) {
            return $proceed($request);
        };

        if (!$this->guard->isActive(Settings::AREA_HTTP)) {
            return $call();
        }

        return $this->timer->measure(
            $this->timerId->build(self::PREFIX, 'async', $this->resolveHost($request)),
            $call
        );
    }

    /**
     * @param object $request
     * @return string
     */
    private function resolveHost($request): string
    {
        if (is_object($request) && method_exists($request, 'getUrl')) {
            return $this->timerId->host((string)$request->getUrl());
        }

        return 'unknown';
    }
}
