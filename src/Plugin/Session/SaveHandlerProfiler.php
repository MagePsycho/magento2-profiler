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

namespace MagePsycho\Profiler\Plugin\Session;

use Magento\Framework\Session\SaveHandler;
use MagePsycho\Profiler\Model\Instrumentation\Guard;
use MagePsycho\Profiler\Model\Instrumentation\Settings;
use MagePsycho\Profiler\Model\Instrumentation\Timer;
use MagePsycho\Profiler\Model\Instrumentation\TimerId;

/**
 * Times session persistence as "SESSION:read" / "SESSION:write".
 *
 * Core instruments only `session_start`, which hides the expensive part. On a Redis session backend the
 * read blocks on the session lock, so a slow checkout or a slow authenticated API call frequently shows
 * up here and nowhere else.
 */
class SaveHandlerProfiler
{
    private const PREFIX = 'SESSION';

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
     * @param SaveHandler $subject
     * @param callable $proceed
     * @param string $sessionId
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundRead(SaveHandler $subject, callable $proceed, $sessionId)
    {
        return $this->run('read', static function () use ($proceed, $sessionId) {
            return $proceed($sessionId);
        });
    }

    /**
     * @param SaveHandler $subject
     * @param callable $proceed
     * @param string $sessionId
     * @param string $data
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundWrite(SaveHandler $subject, callable $proceed, $sessionId, $data)
    {
        return $this->run('write', static function () use ($proceed, $sessionId, $data) {
            return $proceed($sessionId, $data);
        });
    }

    /**
     * @param SaveHandler $subject
     * @param callable $proceed
     * @param string $sessionId
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundDestroy(SaveHandler $subject, callable $proceed, $sessionId)
    {
        return $this->run('destroy', static function () use ($proceed, $sessionId) {
            return $proceed($sessionId);
        });
    }

    /**
     * @param string $operation
     * @param callable $callback
     * @return mixed
     */
    private function run(string $operation, callable $callback)
    {
        if (!$this->guard->isActive(Settings::AREA_SESSION)) {
            return $callback();
        }

        return $this->timer->measure($this->timerId->build(self::PREFIX, $operation), $callback);
    }
}
