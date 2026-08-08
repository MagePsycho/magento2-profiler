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

namespace MagePsycho\Profiler\Model\Profiler\Driver;

use Magento\Framework\Profiler;
use Magento\Framework\Profiler\DriverInterface;
use MagePsycho\Profiler\Model\Instrumentation\Settings;
use MagePsycho\Profiler\Model\Profiler\ReportIndex;
use MagePsycho\Profiler\Model\Profiler\RequestContext;

/**
 * Records every individual invocation as a span, so a report can be drawn as a timeline.
 *
 * A driver rather than an output type, because the standard driver's Stat cannot carry this: it keeps
 * one aggregate row per timer id and destroys the per-call start on stop
 * (`TIME += $time - $start; START = false`, Profiler/Driver/Standard/Stat.php:81-86). The stop
 * timestamp is never stored at all. Aggregates answer "how long in total"; only spans answer "when, and
 * what overlapped".
 *
 * The driver also aggregates its own spans at flush time, emitting the same `rows` shape the `json`
 * output produces. One `timeline` report therefore drives both the tree and the timeline views.
 *
 * Constructed by Profiler\Driver\Factory as `new $class($config)` - a single array argument, no DI.
 */
class Timeline implements DriverInterface
{
    /**
     * Spans kept before the recorder stops appending. Guards against a runaway page filling the disk.
     */
    public const DEFAULT_MAX_SPANS = 5000;

    private const ENV_MAX_SPANS = 'MAGE_PROFILER_MAX_SPANS';

    private const PRECISION = 3;

    /**
     * Open invocations, innermost last.
     *
     * @var array<int, array{id: string, start: float, emalloc: int, realmem: int}>
     */
    private $stack = [];

    /**
     * Completed spans, in completion order.
     *
     * @var array<int, array<string, mixed>>
     */
    private $spans = [];

    /**
     * Running aggregate per timer id, kept independently of the span list.
     *
     * Aggregating from the retained spans instead would under-report every total once the span cap is
     * reached - the calls still happened, they just are not drawn.
     *
     * @var array<string, array<string, mixed>>
     */
    private $totals = [];

    /**
     * @var float|null
     */
    private $origin;

    /**
     * @var int
     */
    private $dropped = 0;

    /**
     * @var bool
     */
    private $flushed = false;

    /**
     * @var string
     */
    private $baseDir;

    /**
     * @var Settings
     */
    private $settings;

    /**
     * @var RequestContext
     */
    private $context;

    /**
     * @param array<string, mixed>|null $config
     */
    public function __construct(?array $config = null)
    {
        $this->baseDir = isset($config['baseDir'])
            ? rtrim((string)$config['baseDir'], '/')
            : (defined('BP') ? BP : '');

        $this->settings = new Settings();
        $this->context  = new RequestContext($this->settings);

        /*
         * Both, deliberately. __destruct() alone is not enough: Profiler::reset() drops the drivers
         * mid-request (App\StaticResource::launch() does exactly that on every static file), and
         * destructor order at shutdown is not guaranteed while exit() skips destructors entirely.
         * flush() is idempotent, so being called twice is harmless.
         */
        //phpcs:ignore Magento2.Functions.DiscouragedFunction
        register_shutdown_function([$this, 'flush']);
    }

    /**
     * @return void
     */
    public function __destruct()
    {
        $this->flush();
    }

    /**
     * @param string $timerId Full nested id, e.g. magento->routers_match->X
     * @param array<string, string>|null $tags
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function start($timerId, ?array $tags = null)
    {
        $now = microtime(true);

        if ($this->origin === null) {
            $this->origin = $now;
        }

        $this->stack[] = [
            'id'      => (string)$timerId,
            'start'   => $now,
            'emalloc' => memory_get_usage(),
            'realmem' => memory_get_usage(true),
        ];
    }

    /**
     * Close the most recent matching frame.
     *
     * Pops rather than matches by id, because Profiler::stop() can close several levels in a single
     * call when unwinding unbalanced nesting (Profiler.php:312-321) and the same id can legitimately
     * recurse. Frames left above the match are recorded as truncated.
     *
     * @param string $timerId
     * @return void
     */
    public function stop($timerId)
    {
        if (!$this->stack) {
            return;
        }

        $timerId = (string)$timerId;
        $index   = null;

        for ($i = count($this->stack) - 1; $i >= 0; $i--) {
            if ($this->stack[$i]['id'] === $timerId) {
                $index = $i;
                break;
            }
        }

        if ($index === null) {
            /* No matching frame - close the innermost so the stack cannot grow unbounded. */
            $this->record((array)array_pop($this->stack), true);

            return;
        }

        while (count($this->stack) - 1 > $index) {
            $this->record((array)array_pop($this->stack), true);
        }

        $this->record((array)array_pop($this->stack), false);
    }

    /**
     * @param string|null $timerId
     * @return void
     */
    public function clear($timerId = null)
    {
        if ($timerId === null) {
            $this->stack   = [];
            $this->spans   = [];
            $this->totals  = [];
            $this->dropped = 0;

            return;
        }

        $timerId = (string)$timerId;
        $this->spans = array_values(array_filter($this->spans, static function (array $span) use ($timerId) {
            return $span['id'] !== $timerId;
        }));
        unset($this->totals[$timerId]);
    }

    /**
     * Write the report. Idempotent, and deliberately not gated on Profiler::isEnabled().
     *
     * That gate is why Standard::display() emits nothing once the profiler is disabled: reset() sets
     * $_enabled = false *before* dropping the drivers (Profiler.php:233-244). Skipping the gate means a
     * plain Profiler::disable() late in a request still yields a report.
     *
     * Profiler::reset() is a different case and still produces nothing, correctly: it calls clear(null)
     * on every driver first, emptying the spans. Its only caller is App\StaticResource::launch(), which
     * resets precisely to disable profiling for static assets - those must not litter the report
     * directory.
     *
     * @return void
     */
    public function flush(): void
    {
        if ($this->flushed) {
            return;
        }
        $this->flushed = true;

        try {
            /* Anything still open ended in an exception or an exit(). */
            while ($this->stack) {
                $this->record((array)array_pop($this->stack), true);
            }

            if (!$this->totals) {
                return;
            }

            $report  = $this->buildReport();
            $payload = json_encode($report, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if (!is_string($payload)) {
                return;
            }

            $index = new ReportIndex($this->baseDir, $this->settings);
            $index->write(
                $index->generateFileName($this->context->getPid()),
                $payload,
                $report['meta']
            );
        } catch (\Throwable $e) {
            //phpcs:ignore Magento2.Functions.DiscouragedFunction
            error_log('MagePsycho_Profiler: ' . $e->getMessage());
        }
    }

    /**
     * Turn a closed frame into a span.
     *
     * No parent pointer is stored: children complete before their parents, so a parent's span index does
     * not exist yet at the child's start. Depth plus start order reconstructs the nesting exactly, which
     * is all the timeline and the Chrome trace format need.
     *
     * @param array<string, mixed> $frame
     * @param bool $truncated
     * @return void
     */
    private function record(array $frame, bool $truncated): void
    {
        if (!isset($frame['id'])) {
            return;
        }

        $now   = microtime(true);
        $id    = (string)$frame['id'];
        $parts = explode(Profiler::NESTING_SEPARATOR, $id);

        $span = [
            'id'         => $id,
            'name'       => (string)end($parts),
            'depth'      => count($parts) - 1,
            'start_ms'   => round(((float)$frame['start'] - (float)$this->origin) * 1000, self::PRECISION),
            'dur_ms'     => round(($now - (float)$frame['start']) * 1000, self::PRECISION),
            'emalloc_kb' => round((memory_get_usage() - (int)$frame['emalloc']) / 1024, 2),
            'realmem_kb' => round((memory_get_usage(true) - (int)$frame['realmem']) / 1024, 2),
        ];

        if ($truncated) {
            $span['truncated'] = true;
        }

        $this->accumulate($span);

        /* Past the cap the call still counts, it just is not drawn. */
        if (count($this->spans) >= $this->getMaxSpans()) {
            $this->dropped++;

            return;
        }

        $this->spans[] = $span;
    }

    /**
     * Fold one span into the running totals.
     *
     * @param array<string, mixed> $span
     * @return void
     */
    private function accumulate(array $span): void
    {
        $id = (string)$span['id'];

        if (!isset($this->totals[$id])) {
            $this->totals[$id] = [
                'id'         => $id,
                'name'       => $span['name'],
                'depth'      => $span['depth'],
                'cnt'        => 0,
                'time_ms'    => 0.0,
                'avg_ms'     => 0.0,
                'emalloc_kb' => 0.0,
                'realmem_kb' => 0.0,
            ];
        }

        $this->totals[$id]['cnt']++;
        $this->totals[$id]['time_ms']    += (float)$span['dur_ms'];
        $this->totals[$id]['emalloc_kb'] += (float)$span['emalloc_kb'];
        $this->totals[$id]['realmem_kb'] += (float)$span['realmem_kb'];
    }

    /**
     * Spans in start order, plus the aggregate rows the tree view consumes.
     *
     * @return array{
     *     meta: array<string, mixed>,
     *     rows: array<int, array<string, mixed>>,
     *     spans?: array<int, array<string, mixed>>
     * }
     */
    private function buildReport(): array
    {
        $spans = $this->spans;
        usort($spans, static function (array $a, array $b) {
            return $a['start_ms'] <=> $b['start_ms'];
        });

        $rows     = $this->finalizeRows();
        $rootTime = 0.0;
        foreach ($rows as $row) {
            if ($row['depth'] === 0) {
                $rootTime += (float)$row['time_ms'];
            }
        }

        /*
         * Two different totals, deliberately:
         *   total_ms - sum of the root timers, which is what the % column is a share of and what the
         *              tree/tabular views already report.
         *   wall_ms  - first start to last end. Under php-fpm everything nests below `magento` so the
         *              two nearly agree, but a CLI run has many sequential roots and they diverge
         *              sharply. The timeline x-axis must use wall_ms or bars run off the chart.
         */
        $wall = 0.0;
        foreach ($spans as $span) {
            $end = (float)$span['start_ms'] + (float)$span['dur_ms'];
            if ($end > $wall) {
                $wall = $end;
            }
        }

        foreach ($rows as &$row) {
            $row['pct'] = $rootTime > 0 ? round((float)$row['time_ms'] / $rootTime * 100, 1) : null;
        }
        unset($row);

        $meta = [
            'ts'              => date('c'),
            'sapi'            => $this->context->getSapi(),
            'label'           => $this->context->getLabel(),
            'pid'             => $this->context->getPid(),
            'total_ms'        => round($rootTime, self::PRECISION),
            'wall_ms'         => round($wall, self::PRECISION),
            'timers'          => count($rows),
            'calls'           => count($spans) + $this->dropped,
            'spans'           => count($spans),
            'peak_real_mb'    => round(memory_get_peak_usage(true) / 1048576, 2),
            'peak_emalloc_mb' => round(memory_get_peak_usage() / 1048576, 2),
        ];

        if ($this->dropped > 0) {
            $meta['dropped'] = $this->dropped;
        }

        $report = ['meta' => $meta, 'rows' => $rows];
        if ($spans) {
            $report['spans'] = $spans;
        }

        return $report;
    }

    /**
     * Round and order the running totals. Parent ids are a prefix of their children, so a plain sort
     * groups them hierarchically.
     *
     * @return array<int, array<string, mixed>>
     */
    private function finalizeRows(): array
    {
        $rows = $this->totals;
        ksort($rows);

        $result = [];
        foreach ($rows as $row) {
            $row['time_ms']    = round((float)$row['time_ms'], self::PRECISION);
            $row['avg_ms']     = round((float)$row['time_ms'] / max(1, (int)$row['cnt']), self::PRECISION);
            $row['emalloc_kb'] = round((float)$row['emalloc_kb'], 2);
            $row['realmem_kb'] = round((float)$row['realmem_kb'], 2);
            $result[]          = $row;
        }

        return $result;
    }

    /**
     * @return int
     */
    private function getMaxSpans(): int
    {
        /* An explicit 0 is meaningful - aggregate-only, no spans in the file - so it is read directly. */
        $raw = $this->settings->getString(self::ENV_MAX_SPANS);
        if ($raw !== '' && (string)(int)$raw === $raw) {
            return max(0, (int)$raw);
        }

        return self::DEFAULT_MAX_SPANS;
    }
}
