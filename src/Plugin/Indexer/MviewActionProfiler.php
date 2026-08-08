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

namespace MagePsycho\Profiler\Plugin\Indexer;

use Magento\Framework\Mview\ActionInterface;
use MagePsycho\Profiler\Model\Instrumentation\Guard;
use MagePsycho\Profiler\Model\Instrumentation\Settings;
use MagePsycho\Profiler\Model\Instrumentation\Timer;
use MagePsycho\Profiler\Model\Instrumentation\TimerId;

/**
 * Times materialised-view updates as "MVIEW:Price\Action\Rows::execute".
 *
 * This is the "update on schedule" path that cron drives - the one that silently eats a server at night
 * and never shows up in a storefront profile.
 *
 * The view id is not reachable from here: Magento\Framework\Mview\View::executeAction(), which knows it,
 * is private and therefore cannot be intercepted. The action class name is the next best identity.
 */
class MviewActionProfiler
{
    private const PREFIX = 'MVIEW';

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
     * @param ActionInterface $subject
     * @param callable $proceed
     * @param int[] $ids
     * @return mixed
     */
    public function aroundExecute(ActionInterface $subject, callable $proceed, $ids)
    {
        if (!$this->guard->isActive(Settings::AREA_INDEXER)) {
            return $proceed($ids);
        }

        $name = $this->timerId->shortClass(get_class($subject), 3) . '::execute';

        return $this->timer->measure(
            $this->timerId->build(self::PREFIX, $name),
            static function () use ($proceed, $ids) {
                return $proceed($ids);
            }
        );
    }
}
