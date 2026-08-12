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

namespace MagePsycho\Profiler\Plugin\Cache;

use Magento\Framework\App\PageCache\Kernel;
use MagePsycho\Profiler\Model\Instrumentation\Guard;
use MagePsycho\Profiler\Model\Instrumentation\Settings;
use MagePsycho\Profiler\Model\Instrumentation\Timer;
use MagePsycho\Profiler\Model\Instrumentation\TimerId;

/**
 * Times Magento's built-in full page cache: "FPC:load (hit)", "FPC:process".
 *
 * Only meaningful when the built-in cache is the page cache - behind Varnish this never runs, which is
 * itself worth seeing in a profile.
 *
 * load() is reported with its outcome, because the two cases are different requests: a hit ends the
 * request there, a miss means the whole page is about to be built. process() is the write side, and it
 * carries the serialisation and tag bookkeeping for the page body.
 */
class PageCacheProfiler
{
    private const PREFIX = 'FPC';

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
     * @param Kernel $subject
     * @param callable $proceed
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundLoad(Kernel $subject, callable $proceed)
    {
        if (!$this->guard->isActive(Settings::AREA_FPC)) {
            return $proceed();
        }

        return $this->timer->measure(
            $this->timerId->build(self::PREFIX, 'load'),
            function () use ($proceed) {
                $result = $proceed();

                /*
                 * The outcome is only known after the call, and the id of a running timer cannot be
                 * changed - so it rides a nested zero-duration marker whose Cnt column is the hit and
                 * miss count.
                 */
                $this->timer->measure(
                    $this->timerId->build(self::PREFIX, 'load:' . ($result ? 'hit' : 'miss')),
                    static function (): void {
                    }
                );

                return $result;
            }
        );
    }

    /**
     * @param Kernel $subject
     * @param callable $proceed
     * @param mixed $response
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundProcess(Kernel $subject, callable $proceed, $response)
    {
        if (!$this->guard->isActive(Settings::AREA_FPC)) {
            return $proceed($response);
        }

        return $this->timer->measure(
            $this->timerId->build(self::PREFIX, 'process'),
            static function () use ($proceed, $response) {
                return $proceed($response);
            }
        );
    }
}
