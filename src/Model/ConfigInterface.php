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
namespace MagePsycho\Profiler\Model;

/**
 * Interface representing configuration retrieval methods.
 */
interface ConfigInterface
{
    /**
     * Get configuration boolean value
     *
     * @param string $xmlPath
     * @param int $storeId
     * @return bool
     */
    public function getConfigFlag($xmlPath, $storeId = null);

    /**
     * Get configuration value
     *
     * @param string $xmlPath
     * @param int $storeId
     * @return string
     */
    public function getConfigValue($xmlPath, $storeId = null);
}
