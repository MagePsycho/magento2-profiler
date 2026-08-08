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

/**
 * Environment-only settings for the instrumentation plugins.
 *
 * Deliberately does NOT read store config. Instrumentation runs inside the DB adapter, the cache
 * frontend and the session handler; a ScopeConfig lookup from any of those issues a query or a cache
 * read and re-enters the very plugin asking the question.
 *
 * Each area is toggled with MAGE_PROFILER_<AREA>, all enabled by default while the profiler is on.
 */
class Settings
{
    public const AREA_SQL     = 'SQL';
    public const AREA_WEBAPI  = 'WEBAPI';
    public const AREA_GRAPHQL = 'GRAPHQL';
    public const AREA_INDEXER = 'INDEXER';
    public const AREA_CLI     = 'CLI';
    public const AREA_CACHE   = 'CACHE';
    public const AREA_SESSION = 'SESSION';
    public const AREA_HTTP    = 'HTTP';
    public const AREA_SEARCH  = 'SEARCH';

    private const ENV_PREFIX = 'MAGE_PROFILER_';

    private const FALSY = ['0', 'false', 'off', 'no'];

    /**
     * Resolved env values, keyed by variable name.
     *
     * @var array<string, string|null>
     */
    private $cache = [];

    /**
     * Whether an instrumentation area should record timers.
     *
     * @param string $area One of the AREA_* constants.
     * @return bool
     */
    public function isAreaEnabled(string $area): bool
    {
        $value = $this->getRaw(self::ENV_PREFIX . $area);
        if ($value === null || $value === '') {
            return true;
        }

        return !in_array(strtolower($value), self::FALSY, true);
    }

    /**
     * Read an arbitrary MAGE_PROFILER_* variable.
     *
     * @param string $name Full variable name, e.g. MAGE_PROFILER_SQL_MAXLEN.
     * @param string $default
     * @return string
     */
    public function getString(string $name, string $default = ''): string
    {
        $value = $this->getRaw($name);

        return $value === null || $value === '' ? $default : $value;
    }

    /**
     * Read a MAGE_PROFILER_* variable as a positive integer.
     *
     * @param string $name
     * @param int $default Returned when unset or not greater than $min.
     * @param int $min
     * @return int
     */
    public function getInt(string $name, int $default, int $min = 0): int
    {
        $value = (int)$this->getString($name);

        return $value > $min ? $value : $default;
    }

    /**
     * @param string $name
     * @return string|null
     */
    private function getRaw(string $name): ?string
    {
        if (array_key_exists($name, $this->cache)) {
            return $this->cache[$name];
        }

        //phpcs:ignore Magento2.Functions.DiscouragedFunction
        $value = getenv($name);

        return $this->cache[$name] = is_string($value) ? trim($value) : null;
    }
}
