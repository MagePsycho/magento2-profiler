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
     * @param array<string, mixed>|null $tags Forwarded verbatim to the drivers; only Timeline reads them.
     * @return mixed
     */
    public function measure(string $timerId, callable $callback, ?array $tags = null)
    {
        /*
         * Core checks the passed tags in start() but only the default tags in stop()
         * (Profiler.php:261 vs :292). Should anyone ever call Profiler::addTagFilter(), a start that
         * passes the filter would pair with a stop that returns early and the frame would leak.
         * Passing no tags at all is what makes this immune today, and there is no getter to detect a
         * filter defensively. In practice bootstrap.php applies 'tagFilters' => [] and nothing in the
         * framework adds one; Timeline::stop() pops rather than matches, which caps the damage at a
         * mis-parented subtree rather than an unbounded stack.
         */
        Profiler::start($timerId, $tags);
        try {
            return $callback();
        } finally {
            Profiler::stop($timerId);
        }
    }
}
