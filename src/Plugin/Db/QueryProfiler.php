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

namespace MagePsycho\Profiler\Plugin\Db;

use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Select;
use MagePsycho\Profiler\Model\Instrumentation\Guard;
use MagePsycho\Profiler\Model\Instrumentation\Settings;
use MagePsycho\Profiler\Model\Instrumentation\Timer;
use MagePsycho\Profiler\Model\Instrumentation\TimerId;

/**
 * Times every SQL statement and groups it under "SQL:<OPERATION> (<table>)".
 *
 * Because timer ids repeat, the profiler's Stat aggregates them automatically: one row per
 * operation/table pair, with the call count, total time and average. That turns a request into a
 * ranked list of which tables are actually costing you time.
 *
 * Supersedes Magento\Framework\Model\ResourceModel\Db\Profiler, which groups only by operation and DB
 * host (`DB_QUERY:pdo_mysql_select_<host>`), requires a `profiler` flag in the connection config, and
 * retains every executed query in memory through Zend_Db_Profiler.
 *
 * Intentionally free of any ScopeConfig lookup. This runs *inside* the DB adapter, and reading store
 * config issues a query, which would re-enter this plugin. Configuration is environment-only.
 *
 * Disable, or drop back to operation-only ids, with MAGE_PROFILER_SQL - see README.
 */
class QueryProfiler
{
    private const PREFIX = 'SQL';

    private const MODE_OPERATION = 'operation';

    /**
     * What a stringified query has and a table name never does: whitespace, parentheses, quoting.
     *
     * Deliberately not an identifier whitelist - a legacy or third-party table may be named in ways
     * MySQL only accepts quoted, and reporting it is better than hiding it behind an alias.
     */
    private const NOT_A_TABLE_NAME = '/[\s()`\'"]/';

    /**
     * Longest plausible table name; MySQL stops at 64 characters.
     */
    private const MAX_TABLE_NAME = 64;

    /**
     * Reported in place of a stringified subquery.
     */
    private const SUBQUERY = '<subquery>';

    /**
     * Leading keyword of the statement.
     */
    private const OPERATION_PATTERN = '/^\s*\(?\s*('
        . 'SELECT|INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE|'
        . 'EXEC|DESCRIBE|SHOW|SET|USE|BEGIN|COMMIT|ROLLBACK|EXPLAIN'
        . ')\b/i';

    /**
     * Where the table name sits, per operation.
     *
     * @var array<string, string>
     */
    private const TABLE_PATTERNS = [
        'SELECT'   => '/\bFROM\s+`?([a-zA-Z0-9_$.]+)`?/i',
        'DELETE'   => '/\bFROM\s+`?([a-zA-Z0-9_$.]+)`?/i',
        'INSERT'   => '/\bINTO\s+`?([a-zA-Z0-9_$.]+)`?/i',
        'REPLACE'  => '/\bINTO\s+`?([a-zA-Z0-9_$.]+)`?/i',
        'UPDATE'   => '/\bUPDATE\s+(?:LOW_PRIORITY\s+)?(?:IGNORE\s+)?`?([a-zA-Z0-9_$.]+)`?/i',
        'TRUNCATE' => '/\bTRUNCATE\s+(?:TABLE\s+)?`?([a-zA-Z0-9_$.]+)`?/i',
        'ALTER'    => '/\bALTER\s+TABLE\s+`?([a-zA-Z0-9_$.]+)`?/i',
        'CREATE'   => '/\bCREATE\s+(?:TEMPORARY\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-zA-Z0-9_$.]+)`?/i',
        'DROP'     => '/\bDROP\s+(?:TEMPORARY\s+)?TABLE\s+(?:IF\s+EXISTS\s+)?`?([a-zA-Z0-9_$.]+)`?/i',
        'DESCRIBE' => '/\bDESCRIBE\s+`?([a-zA-Z0-9_$.]+)`?/i',
    ];

    /**
     * @var Guard
     */
    private $guard;

    /**
     * @var Timer
     */
    private $timer;

    /**
     * @var TimerId
     */
    private $timerId;

    /**
     * @var Settings
     */
    private $settings;

    /**
     * @param Guard $guard
     * @param Timer $timer
     * @param TimerId $timerId
     * @param Settings $settings
     */
    public function __construct(Guard $guard, Timer $timer, TimerId $timerId, Settings $settings)
    {
        $this->guard    = $guard;
        $this->timer    = $timer;
        $this->timerId  = $timerId;
        $this->settings = $settings;
    }

    /**
     * @param Mysql $subject
     * @param callable $proceed
     * @param string|Select|null $sql
     * @param array<int|string, mixed> $bind
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundQuery(Mysql $subject, callable $proceed, $sql = null, $bind = [])
    {
        if (!$this->guard->isActive(Settings::AREA_SQL)) {
            return $proceed($sql, $bind);
        }

        return $this->timer->measure(
            $this->buildTimerId($sql),
            static function () use ($proceed, $sql, $bind) {
                return $proceed($sql, $bind);
            }
        );
    }

    /**
     * Build "SQL:SELECT (catalog_product_entity)".
     *
     * @param string|Select|null $sql
     * @return string
     */
    private function buildTimerId($sql): string
    {
        $withTable = !$this->isOperationOnly();

        if ($sql instanceof Select) {
            return $this->timerId->build(
                self::PREFIX,
                'SELECT',
                $withTable ? $this->extractTableFromSelect($sql) : null
            );
        }

        $statement = is_string($sql) ? $sql : '';
        $operation = $this->extractOperation($statement);

        return $this->timerId->build(
            self::PREFIX,
            $operation,
            $withTable ? $this->extractTableFromString($statement, $operation) : null
        );
    }

    /**
     * @return bool
     */
    private function isOperationOnly(): bool
    {
        $mode = strtolower($this->settings->getString('MAGE_PROFILER_' . Settings::AREA_SQL));

        return $mode === self::MODE_OPERATION || $mode === 'op';
    }

    /**
     * @param string $statement
     * @return string
     */
    private function extractOperation(string $statement): string
    {
        if (preg_match(self::OPERATION_PATTERN, $statement, $matches)) {
            return strtoupper($matches[1]);
        }

        return 'UNKNOWN';
    }

    /**
     * Read the FROM part directly - far cheaper than rendering the Select to a string.
     *
     * @param Select $select
     * @return string|null
     */
    private function extractTableFromSelect(Select $select): ?string
    {
        try {
            $from = $select->getPart(Select::FROM);
        } catch (\Throwable $e) {
            return null;
        }

        if (!is_array($from) || !$from) {
            return null;
        }

        $primary  = null;
        $fallback = null;
        foreach ($from as $correlation => $part) {
            $name = $this->tableLabel($correlation, $part);

            $fallback = $fallback ?? $name;
            if (is_array($part) && ($part['joinType'] ?? '') === Select::FROM) {
                $primary = $name;
                break;
            }
        }

        /* $from is non-empty here, so the loop always produced a fallback. */
        $primary = $primary ?? (string)$fallback;
        if ($primary === '') {
            return null;
        }

        return $this->withJoinCount($primary, count($from) - 1);
    }

    /**
     * The name to report for one entry of the FROM part.
     *
     * A derived table - SELECT ... FROM (SELECT ...) AS main_table - carries a nested Select or a
     * Zend_Db_Expr in `tableName`, and casting that to a string yields the entire subquery, bound
     * values and all. That produced ids like "SQL:SELECT (..., 141)) AND (`store_id` IN (1, 0)))":
     * unreadable, different for every parameter set - one row per entity, the failure this module
     * exists to prevent - and query fragments written into a log file.
     *
     * The alias is the useful name there: it is what the developer called the derived table, and it
     * repeats across calls. Only when no usable alias exists does this fall back to a marker.
     *
     * @param int|string $correlation
     * @param mixed $part
     * @return string
     */
    private function tableLabel($correlation, $part): string
    {
        $name = is_array($part) && isset($part['tableName']) ? $part['tableName'] : null;

        if ($this->isTableName($name)) {
            return (string)$name;
        }

        /* Numeric keys are Zend's positional correlation names, not something a human chose. */
        if (is_string($correlation) && $this->isTableName($correlation)) {
            return $correlation;
        }

        return self::SUBQUERY;
    }

    /**
     * @param mixed $value
     * @return bool
     */
    private function isTableName($value): bool
    {
        if (!is_string($value) || $value === '' || strlen($value) > self::MAX_TABLE_NAME) {
            return false;
        }

        return !preg_match(self::NOT_A_TABLE_NAME, $value);
    }

    /**
     * @param string $statement
     * @param string $operation
     * @return string|null
     */
    private function extractTableFromString(string $statement, string $operation): ?string
    {
        if (!isset(self::TABLE_PATTERNS[$operation])) {
            return null;
        }

        if (!preg_match(self::TABLE_PATTERNS[$operation], $statement, $matches)) {
            return null;
        }

        $joins = preg_match_all('/\bJOIN\b/i', $statement);

        return $this->withJoinCount($matches[1], is_int($joins) ? $joins : 0);
    }

    /**
     * Note how many further tables the statement touches. Truncation is left to TimerId.
     *
     * @param string $table
     * @param int $extraTables
     * @return string
     */
    private function withJoinCount(string $table, int $extraTables): string
    {
        $table = trim($table, '`" ');

        return $extraTables > 0 ? $table . ' +' . $extraTables : $table;
    }
}
