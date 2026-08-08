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

/**
 * Times cache backend operations as "CACHE:load (Redis)".
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
    private const PREFIX = 'CACHE';

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
     * @param FrontendInterface $frontend
     * @param Settings|null $settings
     */
    public function __construct(FrontendInterface $frontend, ?Settings $settings = null)
    {
        parent::__construct($frontend);

        /* Decorators are built with positional parameters by Cache\Frontend\Factory, not by DI. */
        $this->settings = $settings ?? new Settings();
    }

    /**
     * @inheritdoc
     */
    public function test($identifier)
    {
        return $this->time('test', function () use ($identifier) {
            return parent::test($identifier);
        });
    }

    /**
     * @inheritdoc
     */
    public function load($identifier)
    {
        return $this->time('load', function () use ($identifier) {
            return parent::load($identifier);
        });
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
        });
    }

    /**
     * @inheritdoc
     */
    public function remove($identifier)
    {
        return $this->time('remove', function () use ($identifier) {
            return parent::remove($identifier);
        });
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
        });
    }

    /**
     * @param string $operation
     * @param callable $callback
     * @return mixed
     */
    private function time(string $operation, callable $callback)
    {
        if (!Profiler::isEnabled() || !$this->settings->isAreaEnabled(Settings::AREA_CACHE)) {
            return $callback();
        }

        $timerId = self::PREFIX . ':' . $operation . ' (' . $this->getBackendName() . ')';

        Profiler::start($timerId);
        try {
            return $callback();
        } finally {
            Profiler::stop($timerId);
        }
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
        /* Private core property - guard so an upstream rename degrades instead of fatals. */
        if (!property_exists($backend, 'adapter')) {
            return null;
        }

        /* No setAccessible() call - a no-op since PHP 8.1 and deprecated in 8.5. */
        $adapter = (new \ReflectionProperty($backend, 'adapter'))->getValue($backend);
        if (!is_object($adapter)) {
            return null;
        }

        /* RedisTagAdapter -> Redis, FilesystemTagAdapter -> Filesystem, GenericTagAdapter -> Generic. */
        $name = preg_replace('/TagAdapter$/', '', $this->shortName(get_class($adapter)));

        return (string)$name !== '' ? $name : null;
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
