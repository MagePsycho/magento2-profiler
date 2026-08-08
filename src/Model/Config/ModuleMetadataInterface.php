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
namespace MagePsycho\Profiler\Model\Config;

/**
 * Module metadata
 *
 * @api
 */
interface ModuleMetadataInterface
{
    /**
     * Get Module version
     *
     * @return string
     */
    public function getVersion();

    /**
     * Get Module edition
     *
     * @return string
     */
    public function getEdition();

    /**
     * Get Module name
     *
     * @return string
     */
    public function getName();
}
