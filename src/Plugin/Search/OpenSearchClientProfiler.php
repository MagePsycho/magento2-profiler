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

namespace MagePsycho\Profiler\Plugin\Search;

use Magento\OpenSearch\Model\SearchClient;
use MagePsycho\Profiler\Model\Instrumentation\Guard;
use MagePsycho\Profiler\Model\Instrumentation\Settings;
use MagePsycho\Profiler\Model\Instrumentation\Timer;
use MagePsycho\Profiler\Model\Instrumentation\TimerId;

/**
 * Times the OpenSearch client itself: "OPENSEARCH:query (magento2_product_1)".
 *
 * One level below AdapterProfiler, which reports only that a search container was queried. This says
 * which index was touched and by which operation, and - unlike the adapter, which the write path never
 * goes through - it covers reindexing: createIndex, addFieldsMapping, bulkQuery and updateAlias are
 * otherwise invisible, making a catalogsearch_fulltext run look like pure SQL.
 *
 * Both layers share MAGE_PROFILER_SEARCH: `0` switches search profiling off, `operation` keeps the rows
 * but drops the index detail.
 *
 * testConnection(), applyFieldsMappingPreprocessors() and getOpenSearchClient() are deliberately not
 * instrumented. The interceptor is a subclass, so the client's own $this->ping() and
 * $this->applyFieldsMappingPreprocessors() calls route back through plugins; timing the outer method as
 * well would nest a timer inside itself and force a Guard::enter()/leave() pair. Nothing else in the
 * intercepted set calls another public method, so the plain isActive() gate is enough.
 */
class OpenSearchClientProfiler
{
    private const PREFIX = 'OPENSEARCH';

    private const MODE_OPERATION = 'operation';

    /**
     * Bulk actions whose body carries a document line after the action line.
     */
    private const BULK_ACTIONS_WITH_BODY = ['index' => true, 'create' => true, 'update' => true];

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
     * The search itself. Deep pagination drops the index and pages through a point in time instead.
     *
     * @param SearchClient $subject
     * @param callable $proceed
     * @param array<string, mixed> $query
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundQuery(SearchClient $subject, callable $proceed, array $query)
    {
        return $this->measure(
            'query',
            $this->searchIndex($query),
            static function () use ($proceed, $query) {
                return $proceed($query);
            },
            'degraded'
        );
    }

    /**
     * Indexing. The batch size is bucketed into the id, so the report ranks batch cost, not just total.
     *
     * @param SearchClient $subject
     * @param callable $proceed
     * @param array<string, mixed> $query
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundBulkQuery(SearchClient $subject, callable $proceed, array $query)
    {
        return $this->measure(
            'bulkQuery',
            $this->bulkDetail($query),
            static function () use ($proceed, $query) {
                return $proceed($query);
            },
            'errors'
        );
    }

    /**
     * @param SearchClient $subject
     * @param callable $proceed
     * @param array<string, mixed> $query
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundSuggest(SearchClient $subject, callable $proceed, array $query)
    {
        return $this->measure('suggest', $query['index'] ?? null, static function () use ($proceed, $query) {
            return $proceed($query);
        });
    }

    /**
     * @param SearchClient $subject
     * @param callable $proceed
     * @param array<string, mixed> $params
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundGetMapping(SearchClient $subject, callable $proceed, array $params)
    {
        return $this->measure('getMapping', $params['index'] ?? null, static function () use ($proceed, $params) {
            return $proceed($params);
        });
    }

    /**
     * @param SearchClient $subject
     * @param callable $proceed
     * @param array<string, mixed> $params
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundOpenPointInTime(SearchClient $subject, callable $proceed, array $params = [])
    {
        return $this->measure('openPointInTime', $params['index'] ?? null, static function () use ($proceed, $params) {
            return $proceed($params);
        });
    }

    /**
     * Carries only the point-in-time id - there is no index to report.
     *
     * @param SearchClient $subject
     * @param callable $proceed
     * @param array<string, mixed> $params
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundClosePointInTime(SearchClient $subject, callable $proceed, array $params = [])
    {
        return $this->measure('closePointInTime', null, static function () use ($proceed, $params) {
            return $proceed($params);
        });
    }

    /**
     * @param SearchClient $subject
     * @param callable $proceed
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundPing(SearchClient $subject, callable $proceed)
    {
        return $this->measure('ping', null, static function () use ($proceed) {
            return $proceed();
        });
    }

    /**
     * @param SearchClient $subject
     * @param callable $proceed
     * @param string $index
     * @param array<string, mixed> $settings
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundCreateIndex(SearchClient $subject, callable $proceed, string $index, array $settings)
    {
        return $this->measure('createIndex', $index, static function () use ($proceed, $index, $settings) {
            return $proceed($index, $settings);
        });
    }

    /**
     * @param SearchClient $subject
     * @param callable $proceed
     * @param string $index
     * @param array<string, mixed> $settings
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundPutIndexSettings(SearchClient $subject, callable $proceed, string $index, array $settings)
    {
        return $this->measure('putIndexSettings', $index, static function () use ($proceed, $index, $settings) {
            return $proceed($index, $settings);
        });
    }

    /**
     * @param SearchClient $subject
     * @param callable $proceed
     * @param string $index
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundDeleteIndex(SearchClient $subject, callable $proceed, string $index)
    {
        return $this->measure('deleteIndex', $index, static function () use ($proceed, $index) {
            return $proceed($index);
        });
    }

    /**
     * @param SearchClient $subject
     * @param callable $proceed
     * @param string $index
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundIsEmptyIndex(SearchClient $subject, callable $proceed, string $index)
    {
        return $this->measure('isEmptyIndex', $index, static function () use ($proceed, $index) {
            return $proceed($index);
        });
    }

    /**
     * @param SearchClient $subject
     * @param callable $proceed
     * @param string $index
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundIndexExists(SearchClient $subject, callable $proceed, string $index)
    {
        return $this->measure('indexExists', $index, static function () use ($proceed, $index) {
            return $proceed($index);
        });
    }

    /**
     * @param SearchClient $subject
     * @param callable $proceed
     * @param string $index
     * @param string $entityType
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundDeleteMapping(SearchClient $subject, callable $proceed, string $index, string $entityType)
    {
        return $this->measure('deleteMapping', $index, static function () use ($proceed, $index, $entityType) {
            return $proceed($index, $entityType);
        });
    }

    /**
     * The index is the second argument here, not the first.
     *
     * @param SearchClient $subject
     * @param callable $proceed
     * @param array<string, mixed> $fields
     * @param string $index
     * @param string $entityType
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundAddFieldsMapping(
        SearchClient $subject,
        callable $proceed,
        array $fields,
        string $index,
        string $entityType
    ) {
        return $this->measure('addFieldsMapping', $index, static function () use ($proceed, $fields, $index, $entityType) {
            return $proceed($fields, $index, $entityType);
        });
    }

    /**
     * Reported under the alias, which is stable across reindexes - the versioned index is not.
     *
     * @param SearchClient $subject
     * @param callable $proceed
     * @param string $alias
     * @param string $newIndex
     * @param string $oldIndex
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundUpdateAlias(
        SearchClient $subject,
        callable $proceed,
        string $alias,
        string $newIndex,
        string $oldIndex = ''
    ) {
        return $this->measure('updateAlias', $alias, static function () use ($proceed, $alias, $newIndex, $oldIndex) {
            return $proceed($alias, $newIndex, $oldIndex);
        });
    }

    /**
     * @param SearchClient $subject
     * @param callable $proceed
     * @param string $alias
     * @param string $index
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundExistsAlias(SearchClient $subject, callable $proceed, string $alias, string $index = '')
    {
        return $this->measure('existsAlias', $alias, static function () use ($proceed, $alias, $index) {
            return $proceed($alias, $index);
        });
    }

    /**
     * @param SearchClient $subject
     * @param callable $proceed
     * @param string $alias
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundGetAlias(SearchClient $subject, callable $proceed, string $alias)
    {
        return $this->measure('getAlias', $alias, static function () use ($proceed, $alias) {
            return $proceed($alias);
        });
    }

    /**
     * Gate, time, and optionally flag a degraded response.
     *
     * @param string $method Bounded label - the client method name.
     * @param string|string[]|null $index Rendered in parentheses unless operation-only mode is on.
     * @param callable $call
     * @param string|null $marker Suffix of the nested marker timer opened when the response is degraded.
     * @return mixed
     */
    private function measure(string $method, $index, callable $call, ?string $marker = null)
    {
        if (!$this->guard->isActive(Settings::AREA_SEARCH)) {
            return $call();
        }

        $timerId = $this->timerId->build(self::PREFIX, $method, $this->detail($index));

        return $this->timer->measure($timerId, function () use ($call, $method, $marker) {
            $result = $call();

            if ($marker !== null && $this->isDegraded($result)) {
                /* Zero-duration marker: its Cnt column is the number of degraded responses. */
                $this->timer->measure(
                    $this->timerId->build(self::PREFIX, $method . ':' . $marker),
                    static function (): void {
                    }
                );
            }

            return $result;
        });
    }

    /**
     * @param string|string[]|null $index
     * @return string|null
     */
    private function detail($index): ?string
    {
        return $this->isOperationOnly() ? null : $this->timerId->indexName($index);
    }

    /**
     * @return bool
     */
    private function isOperationOnly(): bool
    {
        $mode = strtolower($this->settings->getString('MAGE_PROFILER_' . Settings::AREA_SEARCH));

        return $mode === self::MODE_OPERATION || $mode === 'op';
    }

    /**
     * The index, or `pit` when the query pages through a point in time and carries none.
     *
     * @param array<string, mixed> $query
     * @return string|string[]|null
     */
    private function searchIndex(array $query)
    {
        if (isset($query['index'])) {
            /** @var string|string[] $index */
            $index = $query['index'];

            return $index;
        }

        return isset($query['body']['pit']) ? 'pit' : null;
    }

    /**
     * "magento2_product_1_v* x1k" - the batch size snapped to a power of ten, so the ids stay few.
     *
     * @param array<string, mixed> $query
     * @return string|null
     */
    private function bulkDetail(array $query): ?string
    {
        $index = $this->detail($query['index'] ?? null);
        if ($index === null) {
            return null;
        }

        $bucket = $this->bulkBucket($query);

        return $bucket === null ? $index : $index . ' ' . $bucket;
    }

    /**
     * @param array<string, mixed> $query
     * @return string|null
     */
    private function bulkBucket(array $query): ?string
    {
        $body = $query['body'] ?? null;
        if (!is_array($body) || !$body) {
            return null;
        }

        /*
         * The body alternates an action line and a document line for index/create/update, and carries
         * action lines only for delete. Reading the first line keeps this O(1) on a 1000-document batch.
         */
        $first  = reset($body);
        $action = is_array($first) ? (string)key($first) : '';
        $lines  = count($body);
        $docs   = isset(self::BULK_ACTIONS_WITH_BODY[$action]) ? intdiv($lines, 2) : $lines;

        if ($docs < 1) {
            return null;
        }

        $magnitude = (int)pow(10, (int)floor(log10($docs)));

        if ($magnitude >= 1000000) {
            return 'x' . intdiv($magnitude, 1000000) . 'm';
        }

        return $magnitude >= 1000 ? 'x' . intdiv($magnitude, 1000) . 'k' : 'x' . $magnitude;
    }

    /**
     * A response that timed out, lost a shard, or reported bulk errors.
     *
     * ES8's client returns a response object rather than an array, hence the is_array() gate.
     *
     * @param mixed $result
     * @return bool
     */
    private function isDegraded($result): bool
    {
        if (!is_array($result)) {
            return false;
        }

        if (!empty($result['timed_out']) || !empty($result['errors'])) {
            return true;
        }

        return isset($result['_shards']['failed']) && (int)$result['_shards']['failed'] > 0;
    }
}
