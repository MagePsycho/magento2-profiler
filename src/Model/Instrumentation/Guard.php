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
use MagePsycho\Profiler\Model\Instrumentation\Settings as InstrumentationSettings;

/**
 * Gate + re-entrancy guard shared by every instrumentation plugin.
 *
 * Re-entrancy matters where a hook can nest inside itself: a cache read triggered while serving a cache
 * read, an HTTP call made from inside an HTTP client. Timing the inner call would double-count it and,
 * worse, unbalance the timer stack if the inner call throws.
 */
class Guard
{
    /**
     * @var InstrumentationSettings
     */
    private $settings;

    /**
     * @var array<string, bool>
     */
    private $inside = [];

    /**
     * @param InstrumentationSettings $settings
     */
    public function __construct(InstrumentationSettings $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Whether this area should record right now.
     *
     * @param string $area One of Settings::AREA_*
     * @return bool
     */
    public function isActive(string $area): bool
    {
        return Profiler::isEnabled() && $this->settings->isAreaEnabled($area);
    }

    /**
     * Claim the area. Returns false when already inside it - the caller must then skip timing.
     *
     * @param string $area
     * @return bool
     */
    public function enter(string $area): bool
    {
        if (!empty($this->inside[$area])) {
            return false;
        }

        $this->inside[$area] = true;

        return true;
    }

    /**
     * Release the area. Safe to call unconditionally from a finally block.
     *
     * @param string $area
     * @return void
     */
    public function leave(string $area): void
    {
        unset($this->inside[$area]);
    }
}
