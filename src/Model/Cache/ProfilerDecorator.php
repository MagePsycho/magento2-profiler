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

use Magento\Framework\Cache\Frontend\Decorator\Bare;
use Magento\Framework\Cache\FrontendInterface;
use Magento\Framework\Profiler;
use MagePsycho\Profiler\Model\Instrumentation\Settings;
use MagePsycho\Profiler\Model\Instrumentation\TimerId;

/**
 * Times cache backend operations as "REDIS:load", "FILE:load", "DATABASE:load" - the prefix names the
 * backend actually doing the I/O, so a profile says which store the time went to without a second
 * column. Falls back to "CACHE:" when the backend cannot be identified.
 *
 * Operations are lowercase, and the wire-level commands added by ProfiledRedis are uppercase
 * ("REDIS:MGET"), so the two layers stay apart under one prefix; commands nest under the operation
 * that issued them.
 *
 * A decorator rather than a plugin, because the backend cannot be intercepted: cache backends are built
 * inside Magento\Framework\App\Cache\Frontend\Factory - by Zend_Cache::factory() up to 2.4.8, by the
 * Symfony adapter provider from 2.4.9 - never by the ObjectManager, so no interceptor is ever generated
 * for them. Registering a decorator on the frontend is the only supported way in.
 *
 * Magento ships Magento\Framework\Cache\Frontend\Decorator\Profiler, which does almost this - but it is
 * dead code: app/etc/di.xml wires only the TagScope and Logger decorators, so its timers have never
 * appeared in anyone's profile. It also passes the cache type as profiler *tags*, which the standard
 * driver discards, so every cache type would collapse into one row. This puts the backend in the id
 * instead, where it survives.
 */
class ProfilerDecorator extends Bare
{
    /**
     * Used when the backend cannot be identified - never as a normal prefix.
     */
    private const FALLBACK_PREFIX = 'CACHE';

    /**
     * @var Settings
     */
    private $settings;

    /**
     * Short backend name, resolved once.
     *
     * @var string|null
     */
    private $backend;

    /**
     * Whether the per-command client swap has been attempted for this frontend.
     *
     * @var bool
     */
    private $clientChecked = false;

    /**
     * Built on first use - the decorator has no DI to hand it one.
     *
     * @var TimerId|null
     */
    private $timerId;

    /**
     * Whether an inner instance is already timing this frontend.
     *
     * @var bool
     */
    private $passthrough = false;

    /**
     * @param FrontendInterface $frontend
     * @param Settings|null $settings
     */
    public function __construct(FrontendInterface $frontend, ?Settings $settings = null)
    {
        parent::__construct($frontend);

        /* Decorators are built with positional parameters by Cache\Frontend\Factory, not by DI. */
        $this->settings = $settings ?? new Settings();

        $this->passthrough = $this->isAlreadyDecorated($frontend);

        /*
         * As early as possible: Magento preloads a batch of config keys on the first cache use, and
         * anything before the swap is invisible. instrumentClient() retries on the first operation if
         * the backend is not reachable yet, so an early failure costs nothing.
         */
        $this->instrumentClient();
    }

    /**
     * @inheritdoc
     */
    public function test($identifier)
    {
        return $this->time('test', function () use ($identifier) {
            return parent::test($identifier);
        }, $identifier);
    }

    /**
     * @inheritdoc
     */
    public function load($identifier)
    {
        return $this->time('load', function () use ($identifier) {
            return parent::load($identifier);
        }, $identifier);
    }

    /**
     * @inheritdoc
     *
     * @param mixed $data
     * @param string $identifier
     * @param string[] $tags
     * @param int|null $lifeTime
     */
    public function save($data, $identifier, array $tags = [], $lifeTime = null)
    {
        return $this->time('save', function () use ($data, $identifier, $tags, $lifeTime) {
            return parent::save($data, $identifier, $tags, $lifeTime);
        }, $identifier);
    }

    /**
     * @inheritdoc
     */
    public function remove($identifier)
    {
        return $this->time('remove', function () use ($identifier) {
            return parent::remove($identifier);
        }, $identifier);
    }

    /**
     * @inheritdoc
     *
     * @param string $mode
     * @param string[] $tags
     */
    public function clean($mode = \Zend_Cache::CLEANING_MODE_ALL, array $tags = [])
    {
        return $this->time('clean', function () use ($mode, $tags) {
            return parent::clean($mode, $tags);
        }, $tags);
    }

    /**
     * The cache id reduced to its family: "REDIS:load (BLOCK_HTML)".
     *
     * The family, not the id itself: Magento's ids are per-entity - CAT_P_828, CUSTOM_BLOCK_<sha1> -
     * and one timer row per entity is useless as a report and writes identifiers into a log file.
     * MAGE_PROFILER_REDIS=keys opts into the raw id when you are chasing one specific key.
     *
     * Built through TimerId rather than by concatenation, so the detail gets the same truncation and
     * per-prefix cardinality cap as every other id in the report.
     *
     * @param string $prefix
     * @param string $operation
     * @param string|string[]|null $key
     * @return string
     */
    private function buildTimerId(string $prefix, string $operation, $key): string
    {
        if ($this->timerId === null) {
            $this->timerId = new TimerId($this->settings);
        }

        $raw = strtolower($this->settings->getString('MAGE_PROFILER_' . Settings::AREA_REDIS)) === 'keys';

        return $this->timerId->build($prefix, $operation, $this->timerId->cacheKey($key, $raw));
    }

    /**
     * @param string $operation
     * @param callable $callback
     * @param string|string[]|null $key
     * @return mixed
     */
    private function time(string $operation, callable $callback, $key = null)
    {
        if ($this->passthrough
            || !Profiler::isEnabled()
            || !$this->settings->isAreaEnabled(Settings::AREA_CACHE)
        ) {
            return $callback();
        }

        $timerId = $this->buildTimerId($this->getPrefix(), $operation, $key);

        $this->instrumentClient();

        Profiler::start($timerId);
        try {
            return $callback();
        } finally {
            Profiler::stop($timerId);
        }
    }

    /**
     * Whether this frontend is already wrapped by another instance of this decorator.
     *
     * Magento\Framework\App\Cache\Frontend\Factory applies its decorator list *twice* on 2.4.9 - once
     * inside createSymfonyCache() (Factory.php:595) and again in create() (Factory.php:196) - so every
     * configured decorator is built twice, one wrapping the other. Core's own Decorator\Profiler has
     * the same problem, which is why its cache_load rows appear nested inside themselves.
     *
     * Rather than report every cache call twice, the outer instance becomes a pass-through: the timing
     * stays with the innermost one, which is closest to the backend and therefore measures the least
     * decorator overhead.
     *
     * @param FrontendInterface $frontend
     * @return bool
     */
    private function isAlreadyDecorated(FrontendInterface $frontend): bool
    {
        $node  = $frontend;
        $depth = 0;

        while ($node instanceof Bare && $depth++ < 10) {
            if ($node instanceof self) {
                return true;
            }

            $node = \Closure::bind(
                static function (Bare $bare) {
                    return $bare->_getFrontend();
                },
                null,
                Bare::class
            )($node);
        }

        return false;
    }

    /**
     * Hand the Redis client over to ProfiledRedis, once per frontend.
     *
     * Deliberately here and not in the constructor: at construction time the decorator chain is still
     * being assembled, and the first cache call is the earliest point where the backend is reachable
     * and the profiler's own state is settled.
     *
     * @return void
     */
    private function instrumentClient(): void
    {
        if ($this->clientChecked || !$this->settings->isAreaEnabled(Settings::AREA_REDIS)) {
            return;
        }

        try {
            /*
             * The low-level frontend, not getBackend(): a single frontend keeps two RedisAdapters -
             * the one it reads and writes through, and the one behind the tag adapter - and only this
             * object can see both. getBackend() reaches the tag adapter alone, which instruments the
             * SADD/SREM traffic and none of the MGETs.
             */
            $root = method_exists($this, 'getLowLevelFrontend') ? $this->getLowLevelFrontend() : $this->getBackend();

            $this->clientChecked = (new ProfiledRedisInstaller($this->settings))->install($root);
        } catch (\Throwable $e) {
            /* A backend that cannot be reached yet is retried on the first operation. */
        }
    }

    /**
     * Backend name as a timer prefix: Redis -> REDIS, Filesystem -> FILESYSTEM.
     *
     * Sanitised to A-Z0-9 because the prefix is the first half of a timer id, and an unexpected
     * backend class name must not put punctuation - least of all the profiler's nesting separator -
     * into it.
     *
     * @return string
     */
    private function getPrefix(): string
    {
        $name = strtoupper($this->getBackendName());
        $name = (string)preg_replace('/[^A-Z0-9]/', '', $name);

        return $name !== '' && $name !== 'UNKNOWN' ? $name : self::FALLBACK_PREFIX;
    }

    /**
     * Short class name of the backend actually doing the I/O, e.g. Redis, Filesystem, Generic.
     *
     * @return string
     */
    private function getBackendName(): string
    {
        if ($this->backend !== null) {
            return $this->backend;
        }

        try {
            $backend = $this->getBackend();
            $name    = $this->shortName(get_class($backend));

            /* 2.4.9+: the Symfony frontend returns a generic BackendWrapper for every backend type.
               Unwrap it to the tag adapter, the only part that still identifies the real backend. */
            if ($name === 'BackendWrapper') {
                $name = $this->unwrapSymfony($backend) ?? $name;
            }
        } catch (\Throwable $e) {
            $name = 'unknown';
        }

        return $this->backend = $name !== '' ? $name : 'unknown';
    }

    /**
     * Backend name behind a Symfony BackendWrapper, or null when it cannot be resolved.
     *
     * @param object $backend
     * @return string|null
     */
    private function unwrapSymfony($backend): ?string
    {
        $adapter = $this->readAdapter($backend);
        if ($adapter === null) {
            return null;
        }

        /* RedisTagAdapter -> Redis, FilesystemTagAdapter -> Filesystem, GenericTagAdapter -> Generic. */
        $name = preg_replace('/TagAdapter$/', '', $this->shortName(get_class($adapter)));

        return (string)$name !== '' ? $name : null;
    }

    /**
     * The tag adapter behind a 2.4.9 Symfony BackendWrapper - the object that actually knows the store.
     *
     * @param object $backend
     * @return object|null
     */
    private function readAdapter($backend)
    {
        /* Private core property - guard so an upstream rename degrades instead of fatals. */
        if (!property_exists($backend, 'adapter')) {
            return null;
        }

        /* No setAccessible() call - a no-op since PHP 8.1 and deprecated in 8.5. */
        $adapter = (new \ReflectionProperty($backend, 'adapter'))->getValue($backend);

        return is_object($adapter) ? $adapter : null;
    }

    /**
     * Last segment of a class name, for both namespaced and underscore-namespaced classes.
     *
     * @param string $class
     * @return string
     */
    private function shortName(string $class): string
    {
        $parts = explode('\\', $class);
        /* Zend backends are underscore-namespaced: Cm_Cache_Backend_Redis. */
        $parts = explode('_', (string)end($parts));

        return (string)end($parts);
    }
}
