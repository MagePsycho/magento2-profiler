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
use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(
    ComponentRegistrar::MODULE,
    'MagePsycho_Profiler',
    __DIR__
);

/**
 * Pre-ObjectManager profiler activation hook.
 *
 * This file is executed from vendor/autoload.php (app/etc/NonComposerComponentRegistration.php),
 * which app/bootstrap.php requires *before* its own Magento\Framework\Profiler::applyConfig() block.
 * That is the only point where a third-party profiler output type can be registered - see bootstrap.php.
 */
//phpcs:ignore Magento2.Security.IncludeFile
require_once __DIR__ . '/bootstrap.php';
