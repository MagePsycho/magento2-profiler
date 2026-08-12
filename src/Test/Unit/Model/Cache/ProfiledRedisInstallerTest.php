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

namespace MagePsycho\Profiler\Test\Unit\Model\Cache;

use MagePsycho\Profiler\Model\Cache\ProfiledRedis;
use MagePsycho\Profiler\Model\Cache\ProfiledRedisInstaller;
use MagePsycho\Profiler\Model\Instrumentation\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Stand-in for the Symfony pool: an object holding a client in a private `redis` property.
 */
class ClientHolderStub
{
    /**
     * @var mixed
     */
    private $redis;

    /**
     * @param mixed $redis
     */
    public function __construct($redis)
    {
        $this->redis = $redis;
    }

    /**
     * @return mixed
     */
    public function getRedis()
    {
        return $this->redis;
    }
}

/**
 * Stand-in for the low-level frontend: holds the pool, which holds the client.
 */
class FrontendStub
{
    /**
     * @var object
     */
    private $pool;

    /**
     * @param object $pool
     */
    public function __construct($pool)
    {
        $this->pool = $pool;
    }

    /**
     * Only the installer's reflection walk reads $pool - this keeps static analysis honest about that.
     *
     * @return object
     */
    public function getPool()
    {
        return $this->pool;
    }
}

class ProfiledRedisInstallerTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis is not installed');
        }
    }

    /**
     * A backend with nothing that looks like a Redis client is left alone.
     *
     * @return void
     */
    public function testNonRedisBackendIsIgnored(): void
    {
        $backend = new \stdClass();

        $this->assertFalse($this->createInstaller()->install($backend));
    }

    /**
     * @return void
     */
    public function testNonObjectIsIgnored(): void
    {
        $this->assertFalse($this->createInstaller()->install('not an object'));
    }

    /**
     * An unconnected client cannot describe its own connection, so it is left in place rather than
     * replaced by something that would fail differently.
     *
     * @return void
     */
    public function testUndescribableClientIsLeftUntouched(): void
    {
        $client = new \Redis();
        $holder = new ClientHolderStub($client);

        $this->assertFalse($this->createInstaller()->install($holder));
        $this->assertSame($client, $holder->getRedis(), 'The original client must survive a failed swap');
    }

    /**
     * The pooled client is shared, so a second frontend finds it already swapped and must report
     * success without building yet another connection.
     *
     * @return void
     */
    public function testAlreadyProfiledClientIsAccepted(): void
    {
        $client = new ProfiledRedis();
        $holder = new ClientHolderStub($client);

        $this->assertTrue($this->createInstaller()->install($holder));
        $this->assertSame($client, $holder->getRedis());
    }

    /**
     * The client is two hops from the frontend, which is why the installer walks rather than
     * following one fixed path.
     *
     * @return void
     */
    public function testClientIsFoundThroughNestedObjects(): void
    {
        $client   = new ProfiledRedis();
        $frontend = new FrontendStub(new ClientHolderStub($client));

        $this->assertTrue($this->createInstaller()->install($frontend));
    }

    /**
     * @return ProfiledRedisInstaller
     */
    private function createInstaller(): ProfiledRedisInstaller
    {
        return new ProfiledRedisInstaller(new Settings());
    }
}
