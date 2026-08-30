<?php
/**
 * PHPUnit bootstrap for MagePsycho_Profiler's own unit suite.
 *
 * The module is checked out in one layout and consumed in two others, and a fixed relative
 * path cannot satisfy all three:
 *
 *   .modman/m2_magepsycho_profiler/src   (modman package; app/code/... is a symlink to it,
 *                                         and PHPUnit resolves the symlink before reading
 *                                         relative paths, so the app/code depth is never
 *                                         the one that applies)
 *   app/code/MagePsycho/Profiler         (plain copy)
 *   vendor/magepsycho/magento2-profiler  (composer install)
 *
 * All three sit somewhere under a Magento root, so this walks up until it finds one instead
 * of counting directories.
 *
 * @copyright Copyright (c) 2026 MagePsycho (https://www.magepsycho.com)
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 */

declare(strict_types=1);

$magentoUnitBootstrap = null;
$directory            = __DIR__;

// 8 is well past the deepest of the three layouts (vendor/<vendor>/<pkg>/Test is 4) and still
// terminates at a filesystem root, where dirname() stops changing the value.
for ($level = 0; $level < 8; $level++) {
    $parent = dirname($directory);
    if ($parent === $directory) {
        break;
    }
    $directory = $parent;

    $candidate = $directory . '/dev/tests/unit/framework/bootstrap.php';
    if (is_file($candidate)) {
        $magentoUnitBootstrap = $candidate;
        break;
    }
}

if ($magentoUnitBootstrap === null) {
    fwrite(
        STDERR,
        'MagePsycho_Profiler: could not locate dev/tests/unit/framework/bootstrap.php by walking'
        . ' up from ' . __DIR__ . '.' . PHP_EOL
        . 'These are unit tests, but they type-hint Magento framework classes, so they need a'
        . ' Magento installation to run against.' . PHP_EOL
    );
    exit(1);
}

require $magentoUnitBootstrap;
