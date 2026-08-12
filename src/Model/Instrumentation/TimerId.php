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

use Magento\Framework\Profiler;

/**
 * Builds timer ids that aggregate.
 *
 * The profiler's Stat keys on the timer id, so ids must repeat across calls: one row per REST route,
 * per resolver, per table - never per entity. An id carrying a SKU or an order increment produces one
 * row per record, which is both useless as a report and a way to leak identifiers into a log file.
 *
 * A per-prefix cardinality cap is the backstop for ids we cannot fully predict (client-supplied GraphQL
 * operation names, unmatched REST paths): past the limit everything collapses into
 * `<PREFIX>:<overflow>` so the table degrades instead of exploding.
 */
class TimerId
{
    /**
     * Distinct ids allowed per prefix before collapsing.
     */
    public const DEFAULT_LIMIT = 250;

    /**
     * Longest detail (table, host, route) rendered inside the parentheses.
     */
    public const DEFAULT_MAX_DETAIL_LENGTH = 40;

    /**
     * Leading tokens kept when reducing a cache key to its family.
     */
    private const FAMILY_TOKENS = 3;

    private const ENV_LIMIT      = 'MAGE_PROFILER_MAX_IDS';
    private const ENV_MAX_DETAIL = 'MAGE_PROFILER_MAX_DETAIL';

    private const OVERFLOW = '<overflow>';

    /**
     * @var Settings
     */
    private $settings;

    /**
     * Distinct ids seen, keyed by prefix.
     *
     * @var array<string, array<string, true>>
     */
    private $seen = [];

    /**
     * @param Settings $settings
     */
    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Build "PREFIX:name (detail)", capped and sanitised.
     *
     * @param string $prefix e.g. WEBAPI, GRAPHQL, SQL
     * @param string $name Bounded-cardinality label, e.g. a route template or an operation.
     * @param string|null $detail Optional qualifier rendered in parentheses.
     * @return string
     */
    public function build(string $prefix, string $name, ?string $detail = null): string
    {
        $prefix = $this->sanitize($prefix);
        $name   = $this->sanitize($name);

        if ($name === '') {
            $name = 'unknown';
        }

        $id = $prefix . ':' . $name;

        $detail = $detail === null ? null : $this->truncate($this->sanitize($detail));
        if ($detail !== null && $detail !== '') {
            $id .= ' (' . $detail . ')';
        }

        return $this->applyLimit($prefix, $id);
    }

    /**
     * Shorten a FQCN to its last few segments: Magento\CatalogGraphQl\Model\Resolver\Products -> Resolver\Products.
     *
     * @param string $class
     * @param int $segments
     * @return string
     */
    public function shortClass(string $class, int $segments = 2): string
    {
        /* Interceptors are an implementation detail - report the class the developer wrote. */
        $class = (string)preg_replace('/\\\\Interceptor$/', '', $class);

        $parts = explode('\\', $class);
        if (count($parts) <= $segments) {
            return $class;
        }

        return implode('\\', array_slice($parts, -$segments));
    }

    /**
     * Collapse a search index reference to a label that repeats across runs.
     *
     * The write path targets a versioned physical index - magento2_product_1_v37 - whose suffix
     * increments on every full reindex (Elasticsearch\Model\Adapter\Index\IndexNameResolver). Left raw,
     * each reindex mints a fresh id and eats into the per-prefix cap, so the suffix is folded to _v*.
     * The read path uses the unversioned alias and passes through untouched.
     *
     * @param string|string[]|null $index One index, a list, or a comma-joined list.
     * @return string|null Null when there is nothing to report, so no parentheses are rendered.
     */
    public function indexName($index): ?string
    {
        if (is_string($index)) {
            $index = explode(',', $index);
        }

        if (!is_array($index)) {
            return null;
        }

        $names = [];
        foreach ($index as $name) {
            $name = $this->sanitize(trim((string)$name));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        if (!$names) {
            return null;
        }

        $label = (string)preg_replace('/_v\d+$/', '_v*', $names[0]);
        $extra = count($names) - 1;

        return $extra > 0 ? $label . ' +' . $extra : $label;
    }

    /**
     * Reduce a cache key to the family it belongs to: CUSTOM_BLOCK_0D87A5... -> CUSTOM_BLOCK.
     *
     * Raw cache ids are per-entity by nature - CAT_P_828, LAYOUT_..._4AB40B71..., bare SHA1s - and an id
     * per entity is the one thing this class exists to prevent: it produces a report with one row per
     * product, blows the per-prefix cap within a single page, and writes identifiers into a log file
     * that outlives the request. The family answers the useful question (which *kind* of key costs the
     * time) and stays bounded at a few dozen values.
     *
     * $raw is the escape hatch for debugging one specific key, and is documented as such: it reinstates
     * exactly the cardinality and disclosure problems described above.
     *
     * @param string|string[]|null $key One key, or the list handed to a multi-key command.
     * @param bool $raw Keep the key as-is, minus the storage prefix.
     * @return string|null
     */
    public function cacheKey($key, bool $raw = false): ?string
    {
        if (is_array($key)) {
            $extra = count($key) - 1;
            $first = $this->cacheKey(reset($key) ?: null, $raw);

            if ($first === null) {
                return null;
            }

            return $extra > 0 ? $first . ' +' . $extra : $first;
        }

        if (!is_string($key) || trim($key) === '') {
            return null;
        }

        $key = $this->stripStoragePrefix(trim($key));

        return $raw ? $this->sanitize($key) : $this->keyFamily($key);
    }

    /**
     * The leading tokens that are not an id: entity ids, hashes and store numbers are dropped.
     *
     * @param string $key
     * @return string
     */
    private function keyFamily(string $key): string
    {
        $family = [];

        /*
         * Every separator Magento uses, not just the underscore: ids like
         * 796DDF13...-9-FINAL_PRICE-LIST-CATEGORY-PAGE-USD-20260812 are hyphen-joined, and splitting on
         * "_" alone would keep the leading hash and put one row per price context in the report.
         */
        $tokens = preg_split('/[^A-Z0-9]+/', strtoupper($this->sanitize($key))) ?: [];

        foreach ($tokens as $token) {
            /* Digits and hex blobs are the identity of one record, never of a family. */
            if ($token === '' || ctype_digit($token) || preg_match('/^[0-9A-F]{8,}$/', $token)) {
                break;
            }

            $family[] = $token;
            if (count($family) === self::FAMILY_TOKENS) {
                break;
            }
        }

        return $family ? implode('_', $family) : '<hash>';
    }

    /**
     * Drop what the storage layer prepends: Symfony's `cache:tags:` / `cache:id_tags:` bookkeeping and
     * Magento's configured cache id prefix ("69d_", "69d_:").
     *
     * @param string $key
     * @return string
     */
    private function stripStoragePrefix(string $key): string
    {
        $key = (string)preg_replace('/^cache:(id_)?tags:/i', '', $key);

        /*
         * Magento's cache id prefix is derived from a hash - "69d_", "69d_:" - so the pattern is
         * hex-only on purpose. Matching any short token instead turns CUSTOM_BLOCK into BLOCK, which
         * is both wrong and impossible to notice in a report.
         */
        return (string)preg_replace('/^[0-9a-f]{2,8}_:?/', '', $key);
    }

    /**
     * Reduce a URL to its host. Query strings routinely carry tokens and must never be recorded.
     *
     * @param string $url
     * @return string
     */
    public function host(string $url): string
    {
        //phpcs:ignore Magento2.Functions.DiscouragedFunction
        $host = parse_url($url, PHP_URL_HOST);

        if (is_string($host) && $host !== '') {
            return $host;
        }

        /* Relative or unparseable - keep the path only, never the query. */
        $path = (string)strtok($url, '?');

        return $path !== '' ? $path : 'unknown';
    }

    /**
     * Collapse whitespace and remove the nesting separator, which Profiler::start() rejects outright.
     *
     * @param string $value
     * @return string
     */
    public function sanitize(string $value): string
    {
        $value = (string)preg_replace('/\s+/', ' ', $value);
        $value = str_replace(Profiler::NESTING_SEPARATOR, '_', $value);

        return trim($value);
    }

    /**
     * Keep the tail - prefixes collide, suffixes distinguish.
     *
     * @param string $value
     * @return string
     */
    private function truncate(string $value): string
    {
        $max = $this->settings->getInt(self::ENV_MAX_DETAIL, self::DEFAULT_MAX_DETAIL_LENGTH, 3);
        if (strlen($value) <= $max) {
            return $value;
        }

        return '...' . substr($value, -($max - 3));
    }

    /**
     * @param string $prefix
     * @param string $id
     * @return string
     */
    private function applyLimit(string $prefix, string $id): string
    {
        if (isset($this->seen[$prefix][$id])) {
            return $id;
        }

        $limit = $this->settings->getInt(self::ENV_LIMIT, self::DEFAULT_LIMIT, 0);
        if (count($this->seen[$prefix] ?? []) >= $limit) {
            return $prefix . ':' . self::OVERFLOW;
        }

        $this->seen[$prefix][$id] = true;

        return $id;
    }
}
