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

/**
 * Turns a Redis command and its arguments into the payload a span carries under MAGE_PROFILER_REDIS.
 *
 * The counterpart to QueryCapture, and deliberately its mirror image: a timer id says a command ran
 * and how long it took, and the row above says which cache asked for it, but neither says *what was
 * on the wire*. "MGET (BLOCK)" does not tell you it fetched forty keys, and "SETEX (PRODUCT_LISTING_SORT)"
 * does not tell you it wrote 80 KB.
 *
 * The payload is written into the same span fields the SQL capture uses - `sql` for the command line
 * and `binds` for the value arguments - so the admin viewer renders it in the popup it already has,
 * with no change on that side. The field names are a small lie in the JSON; a second pair of keys the
 * viewer did not know about would have been a bigger one.
 *
 * Security: this writes fragments of whatever is in the cache, and the cache holds rendered blocks -
 * customer names, addresses, cart contents. Treat a report recorded with this on the way you would
 * treat the cache itself. The controls are the flag being off by default and the truncation below;
 * as with SQL binds, no value redaction is attempted, because a cache payload carries no column names
 * and any heuristic would be theatre.
 */
class RedisCapture
{
    /**
     * Longest rendered command line.
     *
     * An MGET of forty prefixed cache ids runs about 2 KB, which is the shape worth seeing whole.
     * Past this the line is cut and marked, because the interesting part of a long command is which
     * keys it touched, and those come first.
     */
    private const MAXLEN = 2000;

    /**
     * Total captured bytes per request, across every command.
     *
     * MAXLEN bounds one command; only a budget bounds a cache-cold page, which can issue hundreds.
     * Checked before any work, so exhausting it also stops paying to render the rest.
     */
    private const BUDGET = 262144;

    /**
     * Arguments rendered onto the command line before it is summarised.
     */
    private const MAX_ARGS = 24;

    /**
     * Longest single argument. Keys survive whole at this width; payloads do not, which is the point.
     */
    private const MAX_ARG_LENGTH = 96;

    /**
     * Value arguments reported separately, as the viewer's bind list.
     */
    private const MAX_VALUES = 8;

    private const ELLIPSIS = '...';

    /**
     * Commands whose trailing argument is a payload rather than a key or a member.
     *
     * Everything else - MGET, DEL, SADD, SUNION - carries identifiers the whole way along, and those
     * are worth reading in full.
     *
     * @var array<string, int> command => index of the first value argument
     */
    private const VALUE_ARG_AT = [
        'SET'   => 1,
        'SETEX' => 2,
        'EVAL'  => 0,
    ];

    /**
     * Bytes charged against the budget so far, this request.
     *
     * @var int
     */
    private $spent = 0;

    /**
     * Capture a command, or null when there is nothing usable left to capture.
     *
     * @param string $command
     * @param array<int, mixed> $args
     * @return array{sql: string, binds?: list<string>}|null
     */
    public function capture(string $command, array $args): ?array
    {
        /* Cheapest possible exit, and the one that makes the budget a CPU saving as well as a size cap. */
        if ($this->spent >= self::BUDGET) {
            return null;
        }

        $flat      = $this->flatten($args);
        $valueFrom = self::VALUE_ARG_AT[$command] ?? null;

        $line   = $command;
        $values = [];
        $shown  = 0;

        foreach ($flat as $index => $argument) {
            if ($shown >= self::MAX_ARGS) {
                $line .= ' ' . self::ELLIPSIS . '(' . (count($flat) - $shown) . ' more)';
                break;
            }

            $rendered = $this->stringify($argument);

            if ($valueFrom !== null && $index >= $valueFrom) {
                /* Payloads go to the bind list, where the viewer shows them one per line. */
                if (count($values) < self::MAX_VALUES) {
                    $values[] = $rendered;
                }
                $shown++;
                continue;
            }

            $line .= ' ' . $rendered;
            $shown++;
        }

        if (strlen($line) > self::MAXLEN) {
            $line = substr($line, 0, self::MAXLEN) . self::ELLIPSIS;
        }

        $payload = ['sql' => $line];
        if ($values) {
            $payload['binds'] = $values;
        }

        $this->spent += strlen($line);
        foreach ($values as $value) {
            $this->spent += strlen($value);
        }

        return $payload;
    }

    /**
     * One level of nesting only.
     *
     * phpredis takes a list where it takes many - mget(['a','b']), del(['a','b']) - and the callers
     * in Magento use both forms. Deeper than one level is not a shape Redis commands have.
     *
     * @param array<int, mixed> $args
     * @return array<int, mixed>
     */
    private function flatten(array $args): array
    {
        $flat = [];

        foreach ($args as $argument) {
            if (is_array($argument)) {
                foreach ($argument as $item) {
                    $flat[] = $item;
                }
                continue;
            }

            $flat[] = $argument;
        }

        return $flat;
    }

    /**
     * One argument as a bounded, printable string.
     *
     * Serialized and compressed cache payloads are binary, and a raw one in a log file is at best
     * unreadable and at worst a terminal escape sequence, so anything non-printable is reported by
     * size instead of by content.
     *
     * @param mixed $argument
     * @return string
     */
    private function stringify($argument): string
    {
        if ($argument === null) {
            return 'NULL';
        }

        if (is_bool($argument)) {
            return $argument ? 'true' : 'false';
        }

        if (is_int($argument) || is_float($argument)) {
            return (string)$argument;
        }

        if (!is_string($argument)) {
            return '<' . gettype($argument) . '>';
        }

        $length = strlen($argument);

        if ($argument === '') {
            return '""';
        }

        /* Anything with a control byte in it is a serialized or compressed payload, not text. */
        if (preg_match('/[^\P{C}\n\r\t]/u', $argument) === 1 || preg_match('//u', $argument) !== 1) {
            return '<' . $this->encoding($argument) . ' ' . $this->bytes($length) . '>';
        }

        if ($length > self::MAX_ARG_LENGTH) {
            return substr($argument, 0, self::MAX_ARG_LENGTH) . self::ELLIPSIS
                . '<' . $this->bytes($length) . '>';
        }

        return $argument;
    }

    /**
     * Name the encoding when the leading bytes give it away.
     *
     * Worth the fifteen lines: on a stock install almost every value here is unreadable, and a report
     * that says <gzip 668.9 KB> answers "why can I not see this" where <binary 668.9 KB> only raises
     * it. Magento's Redis backend compresses above compress_threshold, so the same key is readable
     * when it is small and not when it grew - which is itself the interesting fact.
     *
     * Sniffed rather than decoded on purpose. Decompressing 668 KB to show 96 characters of it would
     * cost more than everything else this class does, on every command, for a peek.
     *
     * @param string $value
     * @return string
     */
    private function encoding(string $value): string
    {
        static $magic = [
            "\x1f\x8b"         => 'gzip',
            "\x28\xb5\x2f\xfd" => 'zstd',
            "\x04\x22\x4d\x18" => 'lz4',
            'gz:'              => 'gzip',
            'zs:'              => 'zstd',
            'l4:'              => 'lz4',
            'sn:'              => 'snappy',
        ];

        foreach ($magic as $prefix => $name) {
            if (strncmp($value, $prefix, strlen($prefix)) === 0) {
                return $name;
            }
        }

        /* igbinary announces itself with a version byte; the serializer is the other common case. */
        if (strncmp($value, "\x00\x00\x00\x02", 4) === 0) {
            return 'igbinary';
        }

        return 'binary';
    }

    /**
     * @param int $length
     * @return string
     */
    private function bytes(int $length): string
    {
        if ($length < 1024) {
            return $length . ' B';
        }

        return round($length / 1024, 1) . ' KB';
    }
}
