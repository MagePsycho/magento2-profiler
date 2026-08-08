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

namespace MagePsycho\Profiler\Util;

use Magento\Framework\Profiler;

/**
 * Thin static facade over Magento\Framework\Profiler for ad-hoc instrumentation.
 *
 * Everything recorded here lands in the same timer tree as Magento's own timers, so it shows up in
 * whichever profiler output is active (tabular, json, html, csvfile).
 *
 * Example:
 *   Benchmark::start('my_hook');
 *   // ...do something...
 *   Benchmark::stop('my_hook');
 */
// phpcs:disable Magento2.Functions.StaticFunction
class Benchmark
{
    /**
     * Open timer identifiers, in call order.
     *
     * @var string[]
     */
    private static $stack = [];

    /**
     * Start a timer. No-op when profiling is disabled.
     *
     * @param string $identifier
     * @param array<string, string>|null $tags
     * @return void
     */
    public static function start(string $identifier, ?array $tags = null): void
    {
        if (!Profiler::isEnabled()) {
            return;
        }

        $identifier = self::normalize($identifier);
        if ($identifier === '') {
            return;
        }

        self::$stack[] = $identifier;
        Profiler::start($identifier, $tags);
    }

    /**
     * Stop a timer. Without an identifier the most recently opened one is closed.
     *
     * Named to match Magento\Framework\Profiler::stop(), which this delegates to.
     *
     * @param string|null $identifier
     * @return void
     */
    public static function stop(?string $identifier = null): void
    {
        if (!Profiler::isEnabled()) {
            return;
        }

        if ($identifier === null) {
            $identifier = array_pop(self::$stack);
            if ($identifier === null) {
                return;
            }
        } else {
            $identifier = self::normalize($identifier);
            if ($identifier === '') {
                return;
            }

            $position = array_search($identifier, self::$stack, true);
            if ($position === false) {
                /* end() without a matching start() - Profiler::stop() would walk the wrong branch. */
                return;
            }
            self::$stack = array_slice(self::$stack, 0, (int)$position);
        }

        Profiler::stop($identifier);
    }

    /**
     * Measure a callable and return its result.
     *
     * @param string $identifier
     * @param callable $callback
     * @return mixed
     */
    public static function measure(string $identifier, callable $callback)
    {
        self::start($identifier);
        try {
            return $callback();
        } finally {
            self::stop($identifier);
        }
    }

    /**
     * Collapse whitespace and strip the nesting separator, which Profiler::start() rejects.
     *
     * @param string $identifier
     * @return string
     */
    private static function normalize(string $identifier): string
    {
        $identifier = (string)preg_replace('/\s+/', ' ', $identifier);
        $identifier = str_replace(Profiler::NESTING_SEPARATOR, '_', $identifier);

        return trim($identifier);
    }
}
