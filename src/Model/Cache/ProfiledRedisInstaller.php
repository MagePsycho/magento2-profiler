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

namespace MagePsycho\Profiler\Model\Cache;

use MagePsycho\Profiler\Model\Instrumentation\Guard;
use MagePsycho\Profiler\Model\Instrumentation\Settings;
use MagePsycho\Profiler\Model\Instrumentation\Timer;
use MagePsycho\Profiler\Model\Instrumentation\TimerId;

/**
 * Swaps the cache's phpredis client for a ProfiledRedis, so every command gets its own timer.
 *
 * Driven from ProfilerDecorator rather than a DI plugin, and that is not a style choice. From 2.4.9 the
 * client is built inside SymfonyAdapterProvider's private createRedisAdapter(), so Symfony's supported
 * `class` parameter is unreachable, and naming a custom backend class in env.php no longer works - the
 * string is matched against a fixed list. The provider is ObjectManager-built, so a plugin on it looks
 * like the answer; it is not. Generating that interceptor needs the compiled_config cache, which needs
 * the very cache frontend being constructed, and the whole application dies with "Cache frontend
 * 'default' is not recognized". The decorator, by contrast, is instantiated by the Factory itself,
 * after the adapter exists and outside any DI resolution - which is exactly the seam this needs.
 *
 * Every holder of the client has to be swapped, and there are more of them than you would guess. A
 * single cache frontend keeps *two* RedisAdapter instances - one the Symfony frontend reads and writes
 * through, another wrapped by RedisTagAdapter, which additionally caches its own copy of the client in
 * its constructor (extractRedisClient) instead of reading it back per call. Swapping only the tag
 * adapter's copy yields tag traffic (SADD/SREM/SUNION) and not one single MGET, which is exactly the
 * wrong half. So this walks the object graph and replaces every `redis` property it finds.
 *
 * Every failure path leaves the original client untouched. Losing the timers is acceptable; breaking
 * the cache is not.
 */
class ProfiledRedisInstaller
{
    /**
     * Symfony holds the client here (Cache/Traits/RedisTrait.php), and so does Magento's tag adapter.
     */
    private const CLIENT_PROPERTY = 'redis';

    /**
     * How deep to walk from the frontend before giving up. The client sits two or three hops in
     * (frontend -> adapter -> pool -> redis); anything further is somebody else's object graph.
     */
    private const MAX_DEPTH = 4;

    /**
     * Backstop against walking into an unexpectedly large graph.
     */
    private const MAX_OBJECTS = 60;

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
     * Built with `new`, like everything the decorator owns - it has no DI of its own.
     *
     * @param Settings $settings
     */
    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
        $this->guard    = new Guard($settings);
        $this->timer    = new Timer();
        $this->timerId  = new TimerId($settings);
    }

    /**
     * Instrument the client behind a backend object, if there is one to instrument.
     *
     * @param object $backend A Symfony tag adapter, or anything else - non-Redis backends are ignored.
     * @return bool Whether a profiled client is now in place.
     */
    public function install($backend): bool
    {
        if (!class_exists('Redis', false) || !is_object($backend)) {
            return false;
        }

        try {
            return $this->swap($backend);
        } catch (\Throwable $e) {
            //phpcs:ignore Magento2.Functions.DiscouragedFunction
            error_log('MagePsycho_Profiler: Redis client not instrumented - ' . $e->getMessage());

            return false;
        }
    }

    /**
     * @param object $root
     * @return bool
     */
    private function swap($root): bool
    {
        $holders = $this->findClientHolders($root);
        if (!$holders) {
            return false;
        }

        $profiled = null;
        foreach ($holders as $holder) {
            $client = $this->read($holder, self::CLIENT_PROPERTY);

            if ($client instanceof ProfiledRedis) {
                /* Already swapped - through another frontend sharing the pooled client. */
                $profiled = $profiled ?? $client;
                continue;
            }

            if (!$client instanceof \Redis) {
                continue;
            }

            $profiled = $profiled ?? $this->replicate($client);
            if ($profiled === null) {
                return false;
            }

            $this->write($holder, self::CLIENT_PROPERTY, $profiled);
        }

        return $profiled !== null;
    }

    /**
     * Every object reachable from the frontend that holds a Redis client of its own.
     *
     * A breadth-first walk rather than a fixed path, because the holders differ by Magento version and
     * by which wrappers are configured (preloading adapters, tag adapters, the low-level frontend).
     *
     * @param object $root
     * @return object[]
     */
    private function findClientHolders($root): array
    {
        $holders = [];
        $seen    = [];
        $queue   = [[$root, 0]];

        while ($queue) {
            [$object, $depth] = array_shift($queue);

            $id = spl_object_id($object);
            if (isset($seen[$id]) || count($seen) >= self::MAX_OBJECTS) {
                continue;
            }
            $seen[$id] = true;

            if (property_exists($object, self::CLIENT_PROPERTY)) {
                $holders[] = $object;
            }

            if ($depth >= self::MAX_DEPTH) {
                continue;
            }

            foreach ((new \ReflectionClass($object))->getProperties() as $property) {
                if ($property->isStatic() || !$property->isInitialized($object)) {
                    continue;
                }

                $value = $property->getValue($object);

                /* Clients and closures are leaves; everything else is worth one more hop. */
                if (is_object($value) && !$value instanceof \Redis && !$value instanceof \Closure) {
                    $queue[] = [$value, $depth + 1];
                }
            }
        }

        return $holders;
    }

    /**
     * A ProfiledRedis on an equivalent connection, or null when the original cannot be described.
     *
     * A second connection is unavoidable - an existing \Redis cannot be re-blessed into a subclass - but
     * the provider connects persistently by default, so phpredis hands back the same socket.
     *
     * @param \Redis $client
     * @return ProfiledRedis|null
     */
    private function replicate(\Redis $client): ?ProfiledRedis
    {
        $host = (string)$client->getHost();
        if ($host === '') {
            return null;
        }

        $port         = (int)$client->getPort();
        $timeout      = (float)$client->getTimeout();
        $readTimeout  = (float)$client->getReadTimeout();
        $persistentId = $client->getPersistentID();

        $profiled = new ProfiledRedis();

        $connected = is_string($persistentId) && $persistentId !== ''
            ? $profiled->pconnect($host, $port, $timeout, $persistentId, 0, $readTimeout)
            : $profiled->connect($host, $port, $timeout, null, 0, $readTimeout);

        if (!$connected) {
            return null;
        }

        $auth = $client->getAuth();
        if ($auth !== null && $auth !== false && $auth !== '') {
            $profiled->auth($auth);
        }

        $db = (int)$client->getDbNum();
        if ($db > 0) {
            $profiled->select($db);
        }

        $this->copyOptions($client, $profiled);

        /* Last: the connect and select above belong to the caller's timer, not to a REDIS: row. */
        $profiled->setProfiler($this->guard, $this->timer, $this->timerId, $this->settings);

        return $profiled;
    }

    /**
     * Serializer, compression and key prefix decide how bytes are written; a client that disagrees with
     * the one that wrote them reads back garbage.
     *
     * @param \Redis $from
     * @param \Redis $to
     * @return void
     */
    private function copyOptions(\Redis $from, \Redis $to): void
    {
        $options = [\Redis::OPT_SERIALIZER, \Redis::OPT_PREFIX, \Redis::OPT_COMPRESSION];

        if (defined('Redis::OPT_COMPRESSION_LEVEL')) {
            $options[] = \Redis::OPT_COMPRESSION_LEVEL;
        }

        foreach ($options as $option) {
            $value = $from->getOption($option);
            if ($value !== false && $value !== null) {
                $to->setOption($option, $value);
            }
        }
    }

    /**
     * @param object $object
     * @param string $property
     * @return mixed
     */
    private function read($object, string $property)
    {
        if (!property_exists($object, $property)) {
            return null;
        }

        /* No setAccessible() call - a no-op since PHP 8.1 and deprecated in 8.5. */
        $reflection = new \ReflectionProperty($object, $property);

        return $reflection->isInitialized($object) ? $reflection->getValue($object) : null;
    }

    /**
     * @param object $object
     * @param string $property
     * @param mixed $value
     * @return void
     */
    private function write($object, string $property, $value): void
    {
        (new \ReflectionProperty($object, $property))->setValue($object, $value);
    }
}
