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

use Magento\Framework\HTTP\Adapter\Curl;
use MagePsycho\Profiler\Model\Instrumentation\Guard;
use MagePsycho\Profiler\Model\Instrumentation\Settings;
use MagePsycho\Profiler\Model\Instrumentation\Timer;
use MagePsycho\Profiler\Model\Instrumentation\TimerId;

/**
 * Times the other curl client: "HTTP:POST (gateway.example.com)".
 *
 * CurlProfiler covers Magento\Framework\HTTP\Client\Curl, which is what modern code uses. This covers
 * Magento\Framework\HTTP\Adapter\Curl, the Zend_Http_Client transport - and that is where payment and
 * shipping gateways live. Without it the single slowest call in a checkout is routinely invisible.
 *
 * The adapter splits one request across two calls: write() hands curl the request, read() waits for the
 * answer. Timing write() alone would report nothing useful, so the url is remembered there and the
 * timer is opened around read(), which is where the network wait actually happens. A read() with no
 * preceding write() - the caller reusing the adapter oddly, or a failed connect - is left untimed
 * rather than attributed to the wrong host.
 */
class CurlAdapterProfiler
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
     * Method and host of the request written but not yet read.
     *
     * @var array{method: string, host: string}|null
     */
    private $pending;

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
     * Remember what is being sent. The wait is in read(), so nothing is timed here.
     *
     * @param Curl $subject
     * @param string $method
     * @param string $url
     * @param string $httpVer
     * @param array<int, string> $headers
     * @param string $body
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeWrite(
        Curl $subject,
        $method,
        $url,
        $httpVer = '1.1',
        $headers = [],
        $body = ''
    ): void {
        $this->pending = [
            'method' => strtoupper((string)$method) ?: 'REQUEST',
            /* Host only - query strings carry API keys and the report outlives the request. */
            'host'   => $this->timerId->host((string)$url),
        ];
    }

    /**
     * @param Curl $subject
     * @param callable $proceed
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundRead(Curl $subject, callable $proceed)
    {
        $pending       = $this->pending;
        $this->pending = null;

        if ($pending === null
            || !$this->guard->isActive(Settings::AREA_HTTP)
            || !$this->guard->enter(Settings::AREA_HTTP)
        ) {
            return $proceed();
        }

        try {
            return $this->timer->measure(
                $this->timerId->build(self::PREFIX, $pending['method'], $pending['host']),
                $proceed
            );
        } finally {
            $this->guard->leave(Settings::AREA_HTTP);
        }
    }
}
