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

namespace MagePsycho\Profiler\Model\Instrumentation;

use Magento\Framework\DB\Select;

/**
 * Turns a statement and its binds into the payload a span carries under MAGE_PROFILER_SQL=query.
 *
 * Opt-in for a reason: a captured statement plus its bound values is the most sensitive thing the
 * profiler can write. Treat a report recorded with capture on the way you would treat a query log.
 * No value redaction is attempted - positional binds carry no column name, so any heuristic would
 * be theatre. The controls that do work are the flag being off by default and the retention limit.
 *
 * Sizing is deliberately in one place: per-statement MAGE_PROFILER_SQL_MAXLEN, per-request
 * MAGE_PROFILER_SQL_BUDGET, and two class constants for the binds, which are a hint rather than the
 * payload.
 */
class QueryCapture
{
    /**
     * Longest captured statement.
     *
     * A storefront SELECT with a few joins runs 400-900 bytes, so most statements survive whole
     * while the pathological tail - `IN (...3000 ids...)`, batched INSERTs, url_rewrite upserts -
     * stays bounded. At the 5000-span default this is a 5 MB ceiling before the budget applies.
     */
    public const DEFAULT_MAXLEN = 1000;

    /**
     * Total captured bytes per request, across every statement.
     *
     * MAXLEN bounds one statement; only a budget bounds a reindex. Checked before any work, so
     * exhausting it also stops paying to assemble Selects for the rest of the request.
     */
    public const DEFAULT_BUDGET = 1048576;

    private const ENV_MAXLEN = 'MAGE_PROFILER_SQL_MAXLEN';

    private const ENV_BUDGET = 'MAGE_PROFILER_SQL_BUDGET';

    /**
     * Binds are context for the statement, not the statement. Capped by constant rather than env:
     * the module already carries eleven environment knobs.
     */
    private const MAX_BINDS = 20;

    private const MAX_BIND_LENGTH = 64;

    private const ELLIPSIS = '...';

    /**
     * @var Settings
     */
    private $settings;

    /**
     * Bytes charged against the budget so far, this request.
     *
     * @var int
     */
    private $spent = 0;

    /**
     * @param Settings $settings
     */
    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Capture a statement, or null when there is nothing usable left to capture.
     *
     * $bind is typed loosely on purpose: Mysql::_prepareQuery() is what normalises a scalar bind
     * into an array and named binds into positional ones, and that runs *after* this plugin. What
     * arrives here is whatever the caller passed.
     *
     * @param string|Select|object|null $sql
     * @param mixed $bind
     * @return array{sql: string, binds?: list<string>}|null
     */
    public function capture($sql, $bind): ?array
    {
        /* Cheapest possible exit, and the one that makes the budget a CPU saving as well as a size cap. */
        if ($this->spent >= $this->budget()) {
            return null;
        }

        $statement = $this->stringify($sql);
        if ($statement === null) {
            return null;
        }

        $payload = ['sql' => $statement];
        $binds   = $this->formatBinds($bind);

        if ($binds) {
            $payload['binds'] = $binds;
        }

        $this->spent += strlen($statement);
        foreach ($binds as $entry) {
            $this->spent += strlen($entry);
        }

        return $payload;
    }

    /**
     * @param string|Select|object|null $sql
     * @return string|null
     */
    private function stringify($sql): ?string
    {
        if (is_string($sql)) {
            return $this->normalize($sql);
        }

        if (!is_object($sql)) {
            return null;
        }

        /*
         * assemble(), never (string)$select. Zend_Db_Select::__toString() catches Exception only, so
         * a PHP 8 Error from a broken renderer escapes and takes the request with it, and on failure
         * it raises E_USER_WARNING - a warning visible in developer mode caused purely by profiling.
         */
        try {
            if ($sql instanceof Select) {
                /* assemble() is annotated as nullable upstream; the cast keeps that contained. */
                return $this->normalize((string)$sql->assemble());
            }

            if (method_exists($sql, '__toString')) {
                return $this->normalize((string)$sql);
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }

    /**
     * Collapse whitespace and cut to MAXLEN.
     *
     * Deliberately NOT TimerId::sanitize(): that rewrites `->` to `_` because the profiler uses it
     * as the nesting separator, which would silently corrupt `WHERE json_col->'$.sku'`. Span fields
     * never pass through Profiler::start(), so the restriction does not apply to them.
     *
     * @param string $text
     * @return string|null
     */
    private function normalize(string $text): ?string
    {
        $text = trim((string)preg_replace('/\s+/', ' ', $text));
        if ($text === '') {
            return null;
        }

        $max = $this->settings->getInt(self::ENV_MAXLEN, self::DEFAULT_MAXLEN, 0);
        if (strlen($text) <= $max) {
            return $text;
        }

        /*
         * Cut the tail, the opposite of TimerId::truncate(): a statement leads with the operation
         * and the tables, and repeats itself in the WHERE. mb_strcut rather than substr because a
         * byte cut can split a multi-byte literal, and Timeline::flush() encodes with
         * JSON_PARTIAL_OUTPUT_ON_ERROR - invalid UTF-8 would turn the whole string into null with
         * no trace of why. The trailing ellipsis is the only truncation marker needed.
         */
        return mb_strcut($text, 0, max(0, $max - strlen(self::ELLIPSIS)), 'UTF-8') . self::ELLIPSIS;
    }

    /**
     * @param mixed $bind
     * @return list<string>
     */
    private function formatBinds($bind): array
    {
        if ($bind === null || $bind === []) {
            return [];
        }

        $bind = is_array($bind) ? $bind : [$bind];
        $out  = [];
        $seen = 0;

        foreach ($bind as $key => $value) {
            if ($seen >= self::MAX_BINDS) {
                $out[] = '+' . (count($bind) - $seen) . ' more';
                break;
            }

            /* Strip the colon so :foo and foo read alike - _prepareQuery() may not have added it yet. */
            $label = is_string($key) ? ltrim($key, ':') . '=' : '';
            $out[] = $label . $this->formatValue($value);
            $seen++;
        }

        return $out;
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function formatValue($value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }

        if (is_array($value)) {
            return '<array:' . count($value) . '>';
        }

        if (is_object($value)) {
            /* Not stringified: a Zend_Db_Expr would drag arbitrary SQL in through the back door. */
            return '<' . get_class($value) . '>';
        }

        if (is_resource($value)) {
            return '<resource>';
        }

        $text = trim((string)preg_replace('/\s+/', ' ', (string)$value));
        if (strlen($text) <= self::MAX_BIND_LENGTH) {
            return $text;
        }

        return mb_strcut($text, 0, self::MAX_BIND_LENGTH - strlen(self::ELLIPSIS), 'UTF-8') . self::ELLIPSIS;
    }

    /**
     * @return int
     */
    private function budget(): int
    {
        return $this->settings->getInt(self::ENV_BUDGET, self::DEFAULT_BUDGET, 0);
    }
}
