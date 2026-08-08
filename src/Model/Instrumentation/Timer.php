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

namespace MagePsycho\Profiler\Model\Instrumentation;

use Magento\Framework\Profiler;

/**
 * Wraps a call in a profiler timer.
 *
 * Uses Magento\Framework\Profiler directly rather than Util\Benchmark: Benchmark keeps its own stack and
 * closes the *first* matching id, which is wrong when the same id recurses - GraphQL resolvers of the
 * same class nest inside themselves routinely. Profiler::stop() walks its path index to the most recent
 * occurrence, which is the behaviour instrumentation needs.
 */
class Timer
{
    /**
     * Time $callback under $timerId. The timer is always closed, including when the call throws -
     * a leaked timer would nest every subsequent id underneath it.
     *
     * @param string $timerId
     * @param callable $callback
     * @return mixed
     */
    public function measure(string $timerId, callable $callback)
    {
        Profiler::start($timerId);
        try {
            return $callback();
        } finally {
            Profiler::stop($timerId);
        }
    }
}
