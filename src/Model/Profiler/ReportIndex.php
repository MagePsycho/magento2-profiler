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
 * Owns the report directory: naming, the index, and retention.
 *
 * The index (`index.jsonl`, one JSON object per line) exists so the viewer's run picker can be built
 * from a single file instead of opening every report to read its header. A browsing session with the
 * profiler cookie set writes one report per request, AJAX included, so that difference is not academic.
 *
 * Retention is enforced at write time - keep the newest MAGE_PROFILER_KEEP runs, drop anything older
 * than MAGE_PROFILER_KEEP_DAYS - because a forgotten cookie on a shared box otherwise fills the disk.
 * The default is 100 rather than 200 because reports carry spans: a storefront page runs ~300KB.
 *
 * Plain `new`, no DI: the output types that use it are built before the ObjectManager exists.
 */
class ReportIndex
{
    public const DEFAULT_DIRECTORY = 'var/log/profiler';

    public const INDEX_FILE = 'index.jsonl';

    public const DEFAULT_KEEP      = 100;
    public const DEFAULT_KEEP_DAYS = 7;

    private const ENV_DIR       = 'MAGE_PROFILER_REPORT_DIR';
    private const ENV_KEEP      = 'MAGE_PROFILER_KEEP';
    private const ENV_KEEP_DAYS = 'MAGE_PROFILER_KEEP_DAYS';

    /**
     * @var Settings
     */
    private $settings;

    /**
     * @var string
     */
    private $baseDir;

    /**
     * @param string $baseDir Magento root.
     * @param Settings|null $settings
     */
    public function __construct(string $baseDir, ?Settings $settings = null)
    {
        $this->baseDir  = rtrim($baseDir, '/');
        $this->settings = $settings ?? new Settings();
    }

    /**
     * Absolute path of the report directory.
     *
     * @return string
     */
    public function getDirectory(): string
    {
        $dir = $this->settings->getString(self::ENV_DIR, self::DEFAULT_DIRECTORY);

        return $this->baseDir . '/' . trim($dir, '/');
    }

    /**
     * Collision-proof, sortable file name. No request data in it - that lives in the index.
     *
     * @param int $pid
     * @return string
     */
    public function generateFileName(int $pid): string
    {
        //phpcs:ignore Magento2.Functions.DiscouragedFunction
        $suffix = bin2hex(random_bytes(3));

        return sprintf('%s-%d-%s.json', date('Ymd-His'), $pid, $suffix);
    }

    /**
     * Persist one report and record it in the index, then prune.
     *
     * @param string $fileName
     * @param string $contents
     * @param array<string, mixed> $indexEntry
     * @return bool
     */
    public function write(string $fileName, string $contents, array $indexEntry): bool
    {
        $dir = $this->getDirectory();

        //phpcs:disable Magento2.Functions.DiscouragedFunction
        set_error_handler(static function (): bool {
            return true;
        });

        try {
            if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
                return false;
            }

            if (file_put_contents($dir . '/' . $fileName, $contents, LOCK_EX) === false) {
                return false;
            }

            $entry = json_encode(['file' => $fileName] + $indexEntry, JSON_UNESCAPED_SLASHES);
            if (is_string($entry)) {
                file_put_contents($dir . '/' . self::INDEX_FILE, $entry . "\n", FILE_APPEND | LOCK_EX);
            }

            $this->prune($dir);

            return true;
        } finally {
            restore_error_handler();
        }
        //phpcs:enable Magento2.Functions.DiscouragedFunction
    }

    /**
     * Drop reports beyond the count limit or older than the age limit, then rewrite the index to match.
     *
     * @param string $dir
     * @return void
     */
    private function prune(string $dir): void
    {
        $keep     = $this->settings->getInt(self::ENV_KEEP, self::DEFAULT_KEEP, 0);
        $keepDays = $this->settings->getInt(self::ENV_KEEP_DAYS, self::DEFAULT_KEEP_DAYS, 0);

        //phpcs:disable Magento2.Functions.DiscouragedFunction
        $files = glob($dir . '/*.json');
        if (!is_array($files) || !$files) {
            return;
        }

        /* Names start with a sortable timestamp, so a plain sort is newest-last. */
        sort($files);

        $cutoff = time() - ($keepDays * 86400);
        $stale  = [];

        foreach ($files as $index => $file) {
            $tooOld   = $keepDays > 0 && (int)filemtime($file) < $cutoff;
            $tooMany  = $keep > 0 && count($files) - $index > $keep;
            if ($tooOld || $tooMany) {
                $stale[basename($file)] = true;
                unlink($file);
            }
        }

        if ($stale) {
            $this->rewriteIndex($dir, $stale);
        }
        //phpcs:enable Magento2.Functions.DiscouragedFunction
    }

    /**
     * @param string $dir
     * @param array<string, true> $removed
     * @return void
     */
    private function rewriteIndex(string $dir, array $removed): void
    {
        $path = $dir . '/' . self::INDEX_FILE;

        //phpcs:disable Magento2.Functions.DiscouragedFunction
        if (!is_file($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return;
        }

        $kept = [];
        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if (is_array($entry) && isset($entry['file']) && isset($removed[$entry['file']])) {
                continue;
            }
            $kept[] = $line;
        }

        file_put_contents($path, $kept ? implode("\n", $kept) . "\n" : '', LOCK_EX);
        //phpcs:enable Magento2.Functions.DiscouragedFunction
    }
}
