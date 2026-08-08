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

namespace MagePsycho\Profiler\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Display-stage settings for the tabular profiler output.
 *
 * Only consumed from Tabular::display(), which runs at PHP shutdown when the ObjectManager exists.
 * Activation itself happens in bootstrap.php, long before any config is readable.
 */
class Config implements ConfigInterface
{
    public const XML_PATH_ENABLED        = 'magepsycho_profiler/general/enabled';
    public const XML_PATH_LOG_PATH       = 'magepsycho_profiler/general/log_path';
    public const XML_PATH_MIN_TIME_MS    = 'magepsycho_profiler/general/min_time_ms';
    public const XML_PATH_FILTER_PATTERN = 'magepsycho_profiler/general/filter_pattern';
    public const XML_PATH_CLI_STDERR     = 'magepsycho_profiler/general/cli_stderr';

    public function __construct(
        private ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getConfigFlag($xmlPath, $storeId = null)
    {
        return $this->scopeConfig->isSetFlag(
            $xmlPath,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * @inheritDoc
     */
    public function getConfigValue($xmlPath, $storeId = null)
    {
        return $this->scopeConfig->getValue(
            $xmlPath,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Display-stage kill switch.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->getConfigFlag(self::XML_PATH_ENABLED);
    }

    /**
     * Log file path, relative to the Magento root when not absolute.
     *
     * @return string
     */
    public function getLogPath(): string
    {
        return trim((string)$this->getConfigValue(self::XML_PATH_LOG_PATH));
    }

    /**
     * Hide timers faster than this. 0 shows everything.
     *
     * @return float
     */
    public function getMinTimeMs(): float
    {
        return (float)$this->getConfigValue(self::XML_PATH_MIN_TIME_MS);
    }

    /**
     * Optional PCRE pattern applied to timer ids.
     *
     * @return string
     */
    public function getFilterPattern(): string
    {
        return trim((string)$this->getConfigValue(self::XML_PATH_FILTER_PATTERN));
    }

    /**
     * Whether CLI runs should also print the table to STDERR.
     *
     * @return bool
     */
    public function isCliStderrEnabled(): bool
    {
        return $this->getConfigFlag(self::XML_PATH_CLI_STDERR);
    }
}
