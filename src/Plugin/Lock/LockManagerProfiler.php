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

namespace MagePsycho\Profiler\Plugin\Lock;

use Magento\Framework\Lock\LockManagerInterface;
use MagePsycho\Profiler\Model\Instrumentation\Guard;
use MagePsycho\Profiler\Model\Instrumentation\Settings;
use MagePsycho\Profiler\Model\Instrumentation\Timer;
use MagePsycho\Profiler\Model\Instrumentation\TimerId;

/**
 * Times lock acquisition: "LOCK:lock (CATALOG_PRODUCT_PRICE)".
 *
 * A lock wait is dead time that looks like nothing at all: the request is not running SQL, not talking
 * to Redis, not rendering - it is queued behind another process. On the db provider that wait is a
 * blocking GET_LOCK, and it is the classic explanation for "the same page is fast alone and slow under
 * load". Nothing else in a profile reports it.
 *
 * Lock names are *not* safe to record as they come. Magento locks around individual cache entries and
 * price contexts, so the names are per-entity - "5a41e1d6c8f391cd2a65f9e7d21524c5e34c1",
 * "...-list-category-page-USD-20260812-1-0-" - and putting them in an id gives one row per key. They go
 * through the same family reduction as cache keys, which leaves the useful part and drops the identity.
 */
class LockManagerProfiler
{
    private const PREFIX = 'LOCK';

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
     * @param LockManagerInterface $subject
     * @param callable $proceed
     * @param string $name
     * @param int $timeout
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundLock(LockManagerInterface $subject, callable $proceed, $name, $timeout = -1)
    {
        return $this->measure('lock', (string)$name, static function () use ($proceed, $name, $timeout) {
            return $proceed($name, $timeout);
        });
    }

    /**
     * @param LockManagerInterface $subject
     * @param callable $proceed
     * @param string $name
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundUnlock(LockManagerInterface $subject, callable $proceed, $name)
    {
        return $this->measure('unlock', (string)$name, static function () use ($proceed, $name) {
            return $proceed($name);
        });
    }

    /**
     * @param LockManagerInterface $subject
     * @param callable $proceed
     * @param string $name
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundIsLocked(LockManagerInterface $subject, callable $proceed, $name)
    {
        return $this->measure('isLocked', (string)$name, static function () use ($proceed, $name) {
            return $proceed($name);
        });
    }

    /**
     * @param string $operation
     * @param string $name
     * @param callable $callback
     * @return mixed
     */
    private function measure(string $operation, string $name, callable $callback)
    {
        /*
         * enter()/leave(), because Magento\Framework\Lock\Proxy implements this interface and forwards
         * to the configured provider, which implements it too - both are interceptors, so without the
         * guard every lock is timed twice, once nested inside itself.
         */
        if (!$this->guard->isActive(Settings::AREA_LOCK) || !$this->guard->enter(Settings::AREA_LOCK)) {
            return $callback();
        }

        try {
            return $this->timer->measure(
                $this->timerId->build(self::PREFIX, $operation, $this->timerId->cacheKey($name)),
                $callback
            );
        } finally {
            $this->guard->leave(Settings::AREA_LOCK);
        }
    }
}
