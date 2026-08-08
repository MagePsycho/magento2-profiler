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

namespace MagePsycho\Profiler\Model\Profiler\Output;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\Profiler;
use Magento\Framework\Profiler\Driver\Standard\AbstractOutput;
use Magento\Framework\Profiler\Driver\Standard\Stat;
use MagePsycho\Profiler\Model\Config;
use MagePsycho\Profiler\Model\Instrumentation\Settings;
use MagePsycho\Profiler\Model\Profiler\RequestContext;

/**
 * ASCII table profiler output, aimed at API (REST/GraphQL) and CLI request profiling.
 *
 * Unlike the built-in `html` output it never writes to the response body, so it is safe for JSON
 * endpoints; unlike `csvfile` it appends (instead of truncating), renders the timer nesting as a
 * tree and carries a per-request context header.
 *
 * Activated with `MAGE_PROFILER=tabular`, a `MAGE_PROFILER=tabular` cookie, or
 * `bin/magento dev:profiler:enable tabular`. See bootstrap.php for how the short alias is resolved.
 */
class Tabular extends AbstractOutput
{
    public const DEFAULT_FILEPATH = '/var/log/profiler_tabular.log';

    /**
     * Every report is confined to this directory, relative to the Magento root.
     */
    private const LOG_DIR = 'var/log';

    private const UNICODE_BRANCH = '|- ';
    private const UNICODE_PIPE   = '|  ';

    /**
     * @var array<string, string>
     */
    protected $_columns = [
        'Timer Id'     => Stat::ID,
        'Cnt'          => Stat::COUNT,
        'Time (ms)'    => Stat::TIME,
        'Avg (ms)'     => Stat::AVG,
        'Emalloc (KB)' => Stat::EMALLOC,
        'RealMem (KB)' => Stat::REALMEM,
    ];

    /**
     * @var string|null
     */
    private $filePath;

    /**
     * @var bool
     */
    private $cliStderr = true;

    /**
     * @var bool
     */
    private $outputConfigApplied = false;

    /**
     * Instantiated by Magento\Framework\Profiler\Driver\Standard\Output\Factory as `new $class($config)`.
     *
     * @param array<string, mixed>|null $config
     */
    public function __construct(?array $config = null)
    {
        parent::__construct($config);

        $this->baseDir = isset($config['baseDir'])
            ? rtrim((string)$config['baseDir'], '/')
            : (defined('BP') ? BP : '');

        $this->settings = new Settings();
        $this->context  = new RequestContext($this->settings);
        $this->filePath = isset($config['filePath']) ? (string)$config['filePath'] : null;
    }

    /**
     * Render and persist the collected statistics. Never allowed to break the request.
     *
     * @param Stat $stat
     * @return void
     */
    public function display(Stat $stat)
    {
        try {
            if (!$this->prepare()) {
                return;
            }
            $this->applyOutputConfig();

            $timerIds = $this->_getTimerIds($stat);
            if (!$timerIds) {
                return;
            }

            $output = $this->renderReport($this->collect($stat, $timerIds));

            $this->writeToFile($output);

            if ($this->context->isCli() && $this->cliStderr && defined('STDERR')) {
                //phpcs:ignore Magento2.Functions.DiscouragedFunction
                fwrite(STDERR, $output);
            }
        } catch (\Throwable $e) {
            //phpcs:ignore Magento2.Functions.DiscouragedFunction
            error_log('MagePsycho_Profiler: ' . $e->getMessage());
        }
    }

    /**
     * Tree-indent the timer id, keeping only its last path segment.
     *
     * @param string $timerId
     * @return string
     */
    protected function _renderTimerId($timerId)
    {
        $parts = explode(Profiler::NESTING_SEPARATOR, $timerId);
        $last  = (string)end($parts);
        $depth = count($parts) - 1;

        if ($depth <= 0) {
            return $last;
        }

        return str_repeat(self::UNICODE_PIPE, $depth - 1) . self::UNICODE_BRANCH . $last;
    }

    /**
     * Log path and STDERR behaviour - the settings that only apply to this output.
     *
     * @return void
     */
    private function applyOutputConfig(): void
    {
        if ($this->outputConfigApplied) {
            return;
        }
        $this->outputConfigApplied = true;

        $config = $this->getModuleConfig();
        if ($config !== null) {
            $this->cliStderr = $config->isCliStderrEnabled();
            if ($this->filePath === null && $config->getLogPath() !== '') {
                $this->filePath = $config->getLogPath();
            }
        }

        $envLog = $this->settings->getString('MAGE_PROFILER_LOG');
        if ($envLog !== '') {
            $this->filePath = $envLog;
        }

        $envStderr = $this->settings->getString('MAGE_PROFILER_CLI_STDERR');
        if ($envStderr !== '') {
            $this->cliStderr = filter_var($envStderr, FILTER_VALIDATE_BOOLEAN);
        }
    }

    /**
     * Build the full report: context header, totals line, table.
     *
     * @param array{rows: array<int, array<string, mixed>>, totalCalls: int, rootTime: float} $collected
     * @return string
     */
    private function renderReport(array $collected): string
    {
        $rows = [];
        foreach ($collected['rows'] as $row) {
            $rows[] = [
                $this->_renderTimerId((string)$row['id']),
                (string)$row['cnt'],
                sprintf('%.3f', $row['time_ms']),
                sprintf('%.3f', $row['avg_ms']),
                sprintf('%.2f', $row['emalloc_kb']),
                sprintf('%.2f', $row['realmem_kb']),
                $row['pct'] === null ? '-' : sprintf('%.1f', $row['pct']),
            ];
        }

        $header = ['Timer Id', 'Cnt', 'Time (ms)', 'Avg (ms)', 'Emalloc (KB)', 'RealMem (KB)', '%'];

        return "\n" . $this->renderContext()
            . sprintf(
                "Timers: %d | Calls: %d | Root time: %.3f ms | Peak real: %.2f MB | Peak emalloc: %.2f MB\n",
                count($rows),
                $collected['totalCalls'],
                $collected['rootTime'] * 1000,
                memory_get_peak_usage(true) / 1048576,
                memory_get_peak_usage() / 1048576
            )
            . $this->formatTable($header, $rows) . "\n";
    }

    /**
     * One-line description of what was profiled.
     *
     * @return string
     */
    private function renderContext(): string
    {
        return sprintf(
            "[%s] pid=%d sapi=%s %s%s\n",
            date('Y-m-d H:i:s'),
            $this->context->getPid(),
            $this->context->getSapi(),
            $this->context->isCli() ? 'CLI: ' : '',
            $this->context->getLabel()
        );
    }

    /**
     * Append the report to the log file, creating the directory when needed.
     *
     * The target always lives under <magento root>/var/log - see resolveLogPath().
     *
     * @param string $output
     * @return void
     */
    private function writeToFile(string $output): void
    {
        $path   = $this->resolveLogPath();
        $logDir = $this->baseDir . '/' . self::LOG_DIR;

        /*
         * Warnings are swallowed for the duration of the write: a failed profiler write must stay
         * silent, and a raw PHP warning would leak into CLI output or into the response of the very
         * request being profiled.
         */
        //phpcs:disable Magento2.Functions.DiscouragedFunction
        set_error_handler(static function (): bool {
            return true;
        });

        try {
            $dir = dirname($path);
            if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
                return;
            }

            /*
             * Second gate, after the directory exists: resolveLogPath() cannot see symlinks, so the
             * real target is compared against the real var/log before anything is written.
             */
            $realDir = realpath($dir);
            $realLog = realpath($logDir);
            if ($realDir === false || $realLog === false || strpos($realDir . '/', $realLog . '/') !== 0) {
                return;
            }

            file_put_contents($path, $output, FILE_APPEND | LOCK_EX);
        } finally {
            restore_error_handler();
        }
        //phpcs:enable Magento2.Functions.DiscouragedFunction
    }

    /**
     * Resolve the configured path to an absolute file underneath <magento root>/var/log.
     *
     * The value is never trusted. It can come from admin config, where an arbitrary write target
     * would be a privilege escalation: a report dropped into pub/ is publicly readable, and a
     * report dropped into a .php file is executable, since the context header echoes the raw
     * request URI. Traversal segments are dropped, the result is always nested under var/log and
     * always ends in .log, so neither location is reachable.
     *
     * @return string
     */
    private function resolveLogPath(): string
    {
        $configured = $this->filePath !== null ? trim($this->filePath) : '';
        $segments   = $this->sanitizeSegments($configured);
        if (!$segments) {
            $segments = $this->sanitizeSegments(self::DEFAULT_FILEPATH);
        }

        $file = (string)array_pop($segments);
        if (substr($file, -4) !== '.log') {
            $file .= '.log';
        }
        $segments[] = $file;

        return $this->baseDir . '/' . self::LOG_DIR . '/' . implode('/', $segments);
    }

    /**
     * Split a configured path into safe path segments, relative to var/log.
     *
     * Empty, "." and ".." segments are discarded rather than rejected, so a traversal attempt
     * degrades into a harmless nested path instead of failing the write silently.
     *
     * @param string $path
     * @return string[]
     */
    private function sanitizeSegments(string $path): array
    {
        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            $segment = trim($segment);
            if ($segment === '' || $segment === '.' || $segment === '..') {
                continue;
            }
            $segments[] = $segment;
        }

        /* var/log is how the path is normally written in config - do not nest it twice. */
        if (isset($segments[1]) && $segments[0] === 'var' && $segments[1] === 'log') {
            $segments = array_slice($segments, 2);
        }

        return $segments;
    }

    /**
     * ASCII table with UTF-8 safe alignment.
     *
     * @param string[] $header
     * @param array<int, string[]> $rows
     * @return string
     */
    private function formatTable(array $header, array $rows): string
    {
        $widths = [];
        foreach ([$header, ...$rows] as $row) {
            foreach ($row as $i => $col) {
                $widths[$i] = max($widths[$i] ?? 0, $this->strWidth((string)$col));
            }
        }

        $separator = '+' . implode('+', array_map(static function ($w) {
            return str_repeat('-', $w + 2);
        }, $widths)) . '+';

        $lines = [$separator, $this->formatRow($header, $widths), $separator];
        foreach ($rows as $row) {
            $lines[] = $this->formatRow($row, $widths);
        }
        $lines[] = $separator;

        return implode("\n", $lines);
    }

    /**
     * Render a single padded table row.
     *
     * @param string[] $row
     * @param int[] $widths
     * @return string
     */
    private function formatRow(array $row, array $widths): string
    {
        $cells = [];
        foreach ($row as $i => $col) {
            $cells[] = $this->padRight((string)$col, $widths[$i] ?? 0);
        }

        return '| ' . implode(' | ', $cells) . ' |';
    }

    /**
     * Display width for UTF-8 strings.
     *
     * @param string $value
     * @return int
     */
    private function strWidth(string $value): int
    {
        return function_exists('mb_strwidth') ? mb_strwidth($value, 'UTF-8') : strlen($value);
    }

    /**
     * Right-pad using display width.
     *
     * @param string $value
     * @param int $width
     * @return string
     */
    private function padRight(string $value, int $width): string
    {
        $currentWidth = $this->strWidth($value);

        return $currentWidth >= $width ? $value : $value . str_repeat(' ', $width - $currentWidth);
    }

    /**
     * Show every timer by default - the core defaults (1ms / 10 calls / 10KB) hide most of the
     * interesting rows when profiling a single API request.
     *
     * @var array<string, float|int>
     */
    protected $_thresholds = [];

    /**
     * @var string
     */
    protected $baseDir;

    /**
     * @var Settings
     */
    protected $settings;

    /**
     * @var RequestContext
     */
    protected $context;

    /**
     * @var bool
     */
    private $configApplied = false;

    /**
     * Timer ids in hierarchical order. Null thresholds means "everything".
     *
     * @param Stat $stat
     * @return string[]
     */
    protected function _getTimerIds(Stat $stat)
    {
        return $stat->getFilteredTimerIds($this->_thresholds ?: null, $this->_filterPattern);
    }

    /**
     * Resolve thresholds and filter: env beats admin config, admin config beats defaults.
     *
     * @return bool False when the display-stage kill switch is off.
     */
    protected function prepare(): bool
    {
        if ($this->configApplied) {
            return true;
        }
        $this->configApplied = true;

        $config = $this->getModuleConfig();

        if ($config !== null) {
            if (!$config->isEnabled()) {
                return false;
            }
            if ($this->_filterPattern === null && $config->getFilterPattern() !== '') {
                $this->setFilterPattern($config->getFilterPattern());
            }
            if (!$this->_thresholds && $config->getMinTimeMs() > 0) {
                $this->setThreshold(Stat::TIME, $config->getMinTimeMs() / 1000);
            }
        }

        $envFilter = $this->settings->getString('MAGE_PROFILER_FILTER');
        if ($envFilter !== '') {
            $this->setFilterPattern($envFilter);
        }

        $envMinMs = $this->settings->getString('MAGE_PROFILER_MIN_MS');
        if ($envMinMs !== '') {
            $this->_thresholds = [];
            if ((float)$envMinMs > 0) {
                $this->setThreshold(Stat::TIME, (float)$envMinMs / 1000);
            }
        }

        return true;
    }

    /**
     * Admin config reader, or null when the ObjectManager is not usable (early bootstrap failures).
     *
     * @return Config|null
     */
    protected function getModuleConfig(): ?Config
    {
        try {
            return ObjectManager::getInstance()->get(Config::class);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Numeric rows plus totals. Formatting is the subclass's business.
     *
     * @param Stat $stat
     * @param string[] $timerIds
     * @return array{rows: array<int, array<string, mixed>>, totalCalls: int, rootTime: float}
     */
    protected function collect(Stat $stat, array $timerIds): array
    {
        $rows       = [];
        $totalCalls = 0;
        $rootTime   = 0.0;

        foreach ($timerIds as $timerId) {
            $time  = (float)$stat->fetch($timerId, Stat::TIME);
            $count = (int)$stat->fetch($timerId, Stat::COUNT);
            $parts = explode(Profiler::NESTING_SEPARATOR, $timerId);

            $totalCalls += $count;
            if (count($parts) === 1) {
                $rootTime += $time;
            }

            $rows[] = [
                'id'         => $timerId,
                'name'       => (string)end($parts),
                'depth'      => count($parts) - 1,
                'cnt'        => $count,
                'time_ms'    => $time * 1000,
                'avg_ms'     => (float)$stat->fetch($timerId, Stat::AVG) * 1000,
                'emalloc_kb' => (float)$stat->fetch($timerId, Stat::EMALLOC) / 1024,
                'realmem_kb' => (float)$stat->fetch($timerId, Stat::REALMEM) / 1024,
            ];
        }

        foreach ($rows as &$row) {
            $row['pct'] = $rootTime > 0 ? $row['time_ms'] / ($rootTime * 1000) * 100 : null;
        }
        unset($row);

        return ['rows' => $rows, 'totalCalls' => $totalCalls, 'rootTime' => $rootTime];
    }
}
