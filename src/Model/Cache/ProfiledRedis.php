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
 * A phpredis client that times every command it runs: "REDIS:MGET", "REDIS:EXEC", "REDIS:connect".
 *
 * One level below ProfilerDecorator. That decorator times the cache *frontend* call - REDIS:load - and
 * cannot see how the time splits: three MGETs or thirty, a slow connect, or the tag adapter's SADD /
 * SUNION traffic, which fires outside any frontend operation at all.
 *
 * A subclass rather than a wrapper, deliberately. Symfony's RedisTrait types its client property as
 * \Redis|Relay|... (Traits/RedisTrait.php) and Magento's RedisTagAdapter reflects the client back out and
 * type-checks it; a proxy object would be rejected by both. Extending \Redis passes every check.
 *
 * Signature strategy, verified against phpredis 6.3 on PHP 8.5:
 *   - parameters are widened to an untyped variadic, which PHP permits (contravariance);
 *   - return types are declared exactly as 6.x has them, which is also valid on 5.x, where the parent
 *     declares none and this merely narrows;
 *   - scan(), hscan() and sscan() take $iterator by reference, which a variadic cannot express, so they
 *     are deliberately not overridden and simply run unprofiled.
 *
 * Commands are uppercase so they never collide with ProfilerDecorator's lowercase operations.
 * Controlled with MAGE_PROFILER_REDIS.
 */
class ProfiledRedis extends \Redis
{
    private const PREFIX = 'REDIS';

    /**
     * MAGE_PROFILER_REDIS=keys puts the whole key in the id instead of its family.
     */
    private const MODE_KEYS = 'keys';

    /**
     * @var Settings|null
     */
    private $settings;

    /**
     * @var Guard|null
     */
    private $guard;

    /**
     * @var Timer|null
     */
    private $timer;

    /**
     * @var TimerId|null
     */
    private $timerId;

    /**
     * Collaborators arrive through setters, not the constructor: \Redis::__construct() takes its own
     * arguments and the plugin constructs this the way phpredis expects.
     *
     * @param Guard $guard
     * @param Timer $timer
     * @param TimerId $timerId
     * @param Settings $settings
     * @return void
     */
    public function setProfiler(Guard $guard, Timer $timer, TimerId $timerId, Settings $settings): void
    {
        $this->guard    = $guard;
        $this->timer    = $timer;
        $this->timerId  = $timerId;
        $this->settings = $settings;
    }

    /**
     * @param mixed ...$args
     * @return bool
     */
    public function connect(...$args): bool
    {
        return $this->profile('connect', function () use ($args) {
            return parent::connect(...$args);
        });
    }

    /**
     * @param mixed ...$args
     * @return bool
     */
    public function pconnect(...$args): bool
    {
        return $this->profile('pconnect', function () use ($args) {
            return parent::pconnect(...$args);
        });
    }

    /**
     * @param mixed ...$args
     * @return mixed
     */
    public function get(...$args): mixed
    {
        return $this->profile('GET', function () use ($args) {
            return parent::get(...$args);
        }, $args[0] ?? null);
    }

    /**
     * @param mixed ...$args
     * @return \Redis|array<int|string, mixed>|false
     */
    public function mget(...$args): \Redis|array|false
    {
        return $this->profile('MGET', function () use ($args) {
            return parent::mget(...$args);
        }, $args[0] ?? null);
    }

    /**
     * @param mixed ...$args
     * @return \Redis|string|bool
     */
    public function set(...$args): \Redis|string|bool
    {
        return $this->profile('SET', function () use ($args) {
            return parent::set(...$args);
        }, $args[0] ?? null);
    }

    /**
     * phpredis 6.3 declares no return type here - matching that is required for compatibility.
     *
     * @param mixed ...$args
     * @return mixed
     */
    public function setex(...$args)
    {
        return $this->profile('SETEX', function () use ($args) {
            return parent::setex(...$args);
        }, $args[0] ?? null);
    }

    /**
     * @param mixed ...$args
     * @return \Redis|int|false
     */
    public function del(...$args): \Redis|int|false
    {
        return $this->profile('DEL', function () use ($args) {
            return parent::del(...$args);
        }, $args[0] ?? null);
    }

    /**
     * @param mixed ...$args
     * @return \Redis|int|false
     */
    public function unlink(...$args): \Redis|int|false
    {
        return $this->profile('UNLINK', function () use ($args) {
            return parent::unlink(...$args);
        }, $args[0] ?? null);
    }

    /**
     * @param mixed ...$args
     * @return \Redis|bool
     */
    public function expire(...$args): \Redis|bool
    {
        return $this->profile('EXPIRE', function () use ($args) {
            return parent::expire(...$args);
        }, $args[0] ?? null);
    }

    /**
     * @param mixed ...$args
     * @return \Redis|int|false
     */
    public function ttl(...$args): \Redis|int|false
    {
        return $this->profile('TTL', function () use ($args) {
            return parent::ttl(...$args);
        }, $args[0] ?? null);
    }

    /**
     * @param mixed ...$args
     * @return mixed
     */
    public function eval(...$args): mixed
    {
        return $this->profile('EVAL', function () use ($args) {
            return parent::eval(...$args);
        });
    }

    /**
     * @param mixed ...$args
     * @return mixed
     */
    public function evalsha(...$args): mixed
    {
        return $this->profile('EVALSHA', function () use ($args) {
            return parent::evalsha(...$args);
        });
    }

    /**
     * Opens a pipeline or a transaction. Cheap on its own - the wait is in EXEC - but worth a row,
     * because a pipeline that is never exec'd is a bug worth seeing.
     *
     * @param mixed ...$args
     * @return \Redis|bool
     */
    public function multi(...$args): \Redis|bool
    {
        return $this->profile('MULTI', function () use ($args) {
            return parent::multi(...$args);
        });
    }

    /**
     * @param mixed ...$args
     * @return \Redis|array<int|string, mixed>|false
     */
    public function exec(...$args): \Redis|array|false
    {
        return $this->profile('EXEC', function () use ($args) {
            return parent::exec(...$args);
        });
    }

    /**
     * @param mixed ...$args
     * @return \Redis|bool
     */
    public function select(...$args): \Redis|bool
    {
        return $this->profile('SELECT', function () use ($args) {
            return parent::select(...$args);
        });
    }

    /**
     * @param mixed ...$args
     * @return \Redis|array<int|string, mixed>|false
     */
    public function info(...$args): \Redis|array|false
    {
        return $this->profile('INFO', function () use ($args) {
            return parent::info(...$args);
        });
    }

    /**
     * @param mixed ...$args
     * @return \Redis|bool
     */
    public function flushdb(...$args): \Redis|bool
    {
        return $this->profile('FLUSHDB', function () use ($args) {
            return parent::flushdb(...$args);
        });
    }

    /**
     * @param mixed ...$args
     * @return \Redis|int|false
     */
    public function sadd(...$args): \Redis|int|false
    {
        return $this->profile('SADD', function () use ($args) {
            return parent::sadd(...$args);
        }, $args[0] ?? null);
    }

    /**
     * @param mixed ...$args
     * @return \Redis|int|false
     */
    public function srem(...$args): \Redis|int|false
    {
        return $this->profile('SREM', function () use ($args) {
            return parent::srem(...$args);
        }, $args[0] ?? null);
    }

    /**
     * @param mixed ...$args
     * @return \Redis|array<int|string, mixed>|false
     */
    public function smembers(...$args): \Redis|array|false
    {
        return $this->profile('SMEMBERS', function () use ($args) {
            return parent::smembers(...$args);
        }, $args[0] ?? null);
    }

    /**
     * @param mixed ...$args
     * @return \Redis|array<int|string, mixed>|false
     */
    public function sunion(...$args): \Redis|array|false
    {
        return $this->profile('SUNION', function () use ($args) {
            return parent::sunion(...$args);
        }, $args[0] ?? null);
    }

    /**
     * @param mixed ...$args
     * @return \Redis|array<int|string, mixed>|false
     */
    public function sinter(...$args): \Redis|array|false
    {
        return $this->profile('SINTER', function () use ($args) {
            return parent::sinter(...$args);
        }, $args[0] ?? null);
    }

    /**
     * @param mixed ...$args
     * @return \Redis|array<int|string, mixed>|false
     */
    public function sdiff(...$args): \Redis|array|false
    {
        return $this->profile('SDIFF', function () use ($args) {
            return parent::sdiff(...$args);
        }, $args[0] ?? null);
    }

    /**
     * Time one command. Untimed - and unconditionally forwarded - until setProfiler() has run and the
     * area is active, so a client built before the profiler is armed still works.
     *
     * @param string $command
     * @param callable $callback
     * @param string|string[]|null $key The key the command acts on, when it acts on one.
     * @return mixed
     */
    private function profile(string $command, callable $callback, $key = null)
    {
        if ($this->guard === null
            || $this->timer === null
            || $this->timerId === null
            || !$this->guard->isActive(Settings::AREA_REDIS)
        ) {
            return $callback();
        }

        return $this->timer->measure(
            $this->timerId->build(self::PREFIX, $command, $this->timerId->cacheKey($key, $this->wantsRawKeys())),
            $callback
        );
    }

    /**
     * @return bool
     */
    private function wantsRawKeys(): bool
    {
        if ($this->settings === null) {
            return false;
        }

        return strtolower($this->settings->getString('MAGE_PROFILER_' . Settings::AREA_REDIS)) === self::MODE_KEYS;
    }
}
