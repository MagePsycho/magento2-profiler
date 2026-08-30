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

/*
 * Composer includes this file from its `files` autoloader, so it also runs whenever the module
 * directory is itself a Composer root - which is what CI does: the coding-standard job installs
 * phpcs into the module directory, and from then on every vendor/bin script there boots this
 * file with no Magento in sight and fatals on the register() call below.
 *
 * class_exists() resolves through the PSR-4 loader Composer registers before it includes any
 * `files` entry, so inside a real Magento installation this is always true regardless of the
 * order the two packages are autoloaded in. When it is false, Magento genuinely is not present
 * and there is nothing to register.
 */
if (!class_exists(ComponentRegistrar::class)) {
    return;
}

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
