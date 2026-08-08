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
/*
 * This file runs before the ObjectManager exists, so the request abstractions are unavailable and
 * superglobals / include / filesystem functions are the only option here.
 */
// phpcs:disable Magento2.Functions.DiscouragedFunction
// phpcs:disable Magento2.Security.IncludeFile
// phpcs:disable Magento2.Security.Superglobal

(static function (): void {
    if (!defined('BP')) {
        return;
    }

    /**
     * Short names this module answers to. Anything else - html, csvfile, a JSON config - is left to
     * stock Magento. Several may be combined: `MAGE_PROFILER=tabular,json`.
     */
    $aliases = [
        'tabular' => ['output' => \MagePsycho\Profiler\Model\Profiler\Output\Tabular::class],
        /*
         * `json` and `timeline` are the same capture. Splitting them only bought a ~4x smaller file at
         * the price of having to decide, before recording, whether you would later want a timeline -
         * and getting it wrong meant re-running. Set MAGE_PROFILER_MAX_SPANS=0 for aggregate-only.
         *
         * A driver, not an output: per-call spans need both edges of every call, which Stat discards.
         */
        'json'     => ['driver' => \MagePsycho\Profiler\Model\Profiler\Driver\Timeline::class],
        'timeline' => ['driver' => \MagePsycho\Profiler\Model\Profiler\Driver\Timeline::class],
    ];

    /**
     * Resolve the raw profiler config value, first match wins.
     *
     * getenv() is checked before $_SERVER because `MAGE_PROFILER=tabular bin/magento ...` only lands
     * in $_SERVER when variables_order includes "E", which is not guaranteed on CLI.
     */
    $fromCookie = false;
    $value      = getenv('MAGE_PROFILER');

    if (($value === false || $value === '') && !empty($_SERVER['MAGE_PROFILER'])) {
        $value = $_SERVER['MAGE_PROFILER'];
    }

    if (($value === false || $value === '') && !empty($_COOKIE['MAGE_PROFILER'])) {
        $value      = $_COOKIE['MAGE_PROFILER'];
        $fromCookie = true;
    }

    if ($value === false || $value === '') {
        $flagFile = BP . '/var/profiler.flag';
        if (is_file($flagFile)) {
            $value = trim((string)file_get_contents($flagFile));
        }
    }

    $value = is_string($value) ? trim($value) : '';
    if ($value === '') {
        return;
    }

    /**
     * Supported forms: `tabular`, `tabular,json`, and either with a `:<secret>` suffix.
     * The secret is carried once, after the last type.
     */
    $secretGiven = '';
    if (strpos($value, ':') !== false) {
        [$value, $secretGiven] = explode(':', $value, 2);
    }

    $requested = [];
    foreach (explode(',', $value) as $name) {
        $name = strtolower(trim($name));
        if ($name === '') {
            continue;
        }
        /* One unknown type means the whole value is not ours - hand it back to stock Magento. */
        if (!isset($aliases[$name])) {
            return;
        }
        $requested[$name] = $aliases[$name];
    }

    if (!$requested) {
        return;
    }

    /**
     * Cookie activation is a live-site information-disclosure and disk-write vector, so it is only
     * honoured in developer mode, or when the cookie carries the MAGE_PROFILER_SECRET value.
     */
    if ($fromCookie) {
        $mode = '';
        $env  = BP . '/app/etc/env.php';
        if (is_file($env)) {
            $envData = include $env;
            $mode    = is_array($envData) && isset($envData['MAGE_MODE']) ? (string)$envData['MAGE_MODE'] : '';
        }

        if ($mode !== 'developer') {
            $secretExpected = (string)(getenv('MAGE_PROFILER_SECRET') ?: '');
            if ($secretExpected === '' || !hash_equals($secretExpected, $secretGiven)) {
                return;
            }
        }
    }

    /* Do not profile when the module is disabled. Only reached when a tabular flag is present. */
    $configFile = BP . '/app/etc/config.php';
    if (is_file($configFile)) {
        $appConfig = include $configFile;
        if (is_array($appConfig)
            && isset($appConfig['modules'])
            && empty($appConfig['modules']['MagePsycho_Profiler'])
        ) {
            return;
        }
    }

    foreach ($requested as $spec) {
        if (!class_exists(reset($spec))) {
            return;
        }
    }

    /*
     * Do not profile the report viewer itself. Browsing it with the cookie set would record a new run
     * on every page view, burying the run you opened the viewer to look at.
     */
    $path = (string)($_SERVER['REQUEST_URI'] ?? '');
    if ($path !== '' && strpos($path, '/magepsycho_profiler/') !== false) {
        return;
    }

    /*
     * Outputs are rendered by one shared Standard driver; drivers stand on their own. A lone output
     * keeps the scalar form Magento's _parseConfig() understands directly, everything else needs the
     * array form.
     */
    $outputs = [];
    $drivers = [];
    foreach ($requested as $spec) {
        $kind  = array_key_first($spec);
        $class = $spec[$kind];

        if ($kind === 'output') {
            $outputs[] = ['type' => $class];
        } else {
            $drivers[] = ['type' => $class];
        }
    }

    if (count($outputs) === 1 && !$drivers) {
        $profilerConfig = $outputs[0]['type'];
    } else {
        if ($outputs) {
            array_unshift($drivers, ['outputs' => $outputs]);
        }
        $profilerConfig = ['drivers' => $drivers];
    }

    $isHtmlRequest = isset($_SERVER['HTTP_ACCEPT'])
        && strpos($_SERVER['HTTP_ACCEPT'], 'text/html') !== false;

    if ($isHtmlRequest) {
        /*
         * Let core app/bootstrap.php do the applyConfig() call - $_SERVER['MAGE_PROFILER'] takes
         * precedence over var/profiler.flag there, so this simply substitutes a value core can resolve.
         * Applying it here as well would register the driver twice. Core json_decode()s the value
         * first, so the multi-output array travels as JSON.
         */
        $_SERVER['MAGE_PROFILER'] = is_array($profilerConfig)
            ? (string)json_encode($profilerConfig)
            : $profilerConfig;

        return;
    }

    /* CLI / REST / GraphQL / cron: core's text/html guard skips its block, so apply it ourselves. */
    \Magento\Framework\Profiler::applyConfig($profilerConfig, BP);
})();
