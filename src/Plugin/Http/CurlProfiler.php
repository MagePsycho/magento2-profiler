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

use Magento\Framework\HTTP\ClientInterface;
use MagePsycho\Profiler\Model\Instrumentation\Guard;
use MagePsycho\Profiler\Model\Instrumentation\Settings;
use MagePsycho\Profiler\Model\Instrumentation\Timer;
use MagePsycho\Profiler\Model\Instrumentation\TimerId;

/**
 * Times outbound calls made through Magento's HTTP clients as "HTTP:GET api.example.com".
 *
 * Network latency is invisible to every other timer in the profile, and a single slow third-party call
 * routinely dominates a request. Payment gateways, shipping-rate lookups, tax services and search
 * indexing all land here.
 *
 * Only the **host** is recorded. Full URLs regularly carry API keys and tokens in the query string, and
 * the report is appended to a log file that outlives the request.
 *
 * Declared on ClientInterface rather than on Client\Curl: Client\Socket implements the same contract and
 * was invisible while the plugin named the concrete class, and a third-party client implementing the
 * interface is covered without another declaration. The two are the only core implementations.
 *
 * The public get()/post() pair is the hook point - it is the whole request-issuing surface the interface
 * declares. Curl::makeRequest() is protected and cannot be intercepted, and nothing is lost by that:
 * both public methods delegate straight to it, and no non-test subclass adds another verb.
 *
 * Laminas traffic does NOT arrive here - Framework\HTTP\LaminasClient extends Laminas\Http\Client and
 * implements no part of this interface. See LaminasClientProfiler.
 */
class CurlProfiler
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
     * @param ClientInterface $subject
     * @param callable $proceed
     * @param string $uri
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundGet(ClientInterface $subject, callable $proceed, $uri)
    {
        return $this->run('GET', (string)$uri, static function () use ($proceed, $uri) {
            return $proceed($uri);
        });
    }

    /**
     * @param ClientInterface $subject
     * @param callable $proceed
     * @param string $uri
     * @param array<string, mixed>|string $params
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundPost(ClientInterface $subject, callable $proceed, $uri, $params)
    {
        return $this->run('POST', (string)$uri, static function () use ($proceed, $uri, $params) {
            return $proceed($uri, $params);
        });
    }

    /**
     * @param string $method
     * @param string $uri
     * @param callable $callback
     * @return mixed
     */
    private function run(string $method, string $uri, callable $callback)
    {
        if (!$this->guard->isActive(Settings::AREA_HTTP) || !$this->guard->enter(Settings::AREA_HTTP)) {
            return $callback();
        }

        try {
            return $this->timer->measure(
                $this->timerId->build(self::PREFIX, $method, $this->timerId->host($uri)),
                $callback
            );
        } finally {
            $this->guard->leave(Settings::AREA_HTTP);
        }
    }
}
