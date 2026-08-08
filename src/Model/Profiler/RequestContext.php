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

namespace MagePsycho\Profiler\Model\Profiler;

use MagePsycho\Profiler\Model\Instrumentation\Settings;

/**
 * Describes what was profiled, for the report header.
 *
 * Instantiated with `new` from the output types, which the profiler's Output\Factory builds before the
 * ObjectManager exists - so no constructor dependencies.
 *
 * The query string is stripped by default. API calls routinely carry tokens in it and the report is
 * persisted to disk, the same reasoning that already applies to TimerId::host(). Set
 * MAGE_PROFILER_KEEP_QUERY=1 when you genuinely need it.
 */
class RequestContext
{
    private const ENV_KEEP_QUERY = 'MAGE_PROFILER_KEEP_QUERY';

    /**
     * @var Settings
     */
    private $settings;

    /**
     * @var string|null
     */
    private $label;

    /**
     * @param Settings|null $settings
     */
    public function __construct(?Settings $settings = null)
    {
        $this->settings = $settings ?? new Settings();
    }

    /**
     * "GET /rest/V1/directory/currency" on web, "bin/magento cache:clean" on CLI.
     *
     * @return string
     */
    public function getLabel(): string
    {
        if ($this->label !== null) {
            return $this->label;
        }

        //phpcs:disable Magento2.Security.Superglobal
        if ($this->isCli()) {
            $argv  = $_SERVER['argv'] ?? [];
            $label = is_array($argv) ? implode(' ', array_map('strval', $argv)) : '';
        } else {
            $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
            if (!$this->keepQuery()) {
                $uri = (string)strtok($uri, '?');
            }
            $label = trim(((string)($_SERVER['REQUEST_METHOD'] ?? '-')) . ' ' . ($uri !== '' ? $uri : '-'));
        }
        //phpcs:enable Magento2.Security.Superglobal

        return $this->label = $label !== '' ? $label : '-';
    }

    /**
     * @return string
     */
    public function getSapi(): string
    {
        return PHP_SAPI;
    }

    /**
     * @return int
     */
    public function getPid(): int
    {
        //phpcs:ignore Magento2.Functions.DiscouragedFunction
        return (int)getmypid();
    }

    /**
     * @return bool
     */
    public function isCli(): bool
    {
        return PHP_SAPI === 'cli';
    }

    /**
     * @return bool
     */
    private function keepQuery(): bool
    {
        return $this->settings->getString(self::ENV_KEEP_QUERY) !== '';
    }
}
