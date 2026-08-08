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

namespace MagePsycho\Profiler\Plugin\Search;

use Magento\Framework\Search\AdapterInterface;
use Magento\Framework\Search\RequestInterface;
use MagePsycho\Profiler\Model\Instrumentation\Guard;
use MagePsycho\Profiler\Model\Instrumentation\Settings;
use MagePsycho\Profiler\Model\Instrumentation\Timer;
use MagePsycho\Profiler\Model\Instrumentation\TimerId;

/**
 * Times catalog search as "SEARCH:SearchAdapter\Adapter (quick_search_container)".
 *
 * Declared on the interface, so it is engine-agnostic: Elasticsearch, OpenSearch, the MySQL adapters and
 * any third-party engine are all covered without naming one. The request name (search container)
 * separates quick search from layered navigation, and its cardinality is bounded by the search config.
 */
class AdapterProfiler
{
    private const PREFIX = 'SEARCH';

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
     * @param AdapterInterface $subject
     * @param callable $proceed
     * @param RequestInterface $request
     * @return mixed
     */
    public function aroundQuery(AdapterInterface $subject, callable $proceed, RequestInterface $request)
    {
        $call = static function () use ($proceed, $request) {
            return $proceed($request);
        };

        if (!$this->guard->isActive(Settings::AREA_SEARCH)) {
            return $call();
        }

        return $this->timer->measure(
            $this->timerId->build(
                self::PREFIX,
                $this->timerId->shortClass(get_class($subject), 2),
                (string)$request->getName()
            ),
            $call
        );
    }
}
