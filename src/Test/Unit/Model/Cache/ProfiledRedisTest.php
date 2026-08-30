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

use Magento\Framework\Profiler;
use Magento\Framework\Profiler\DriverInterface;
use MagePsycho\Profiler\Model\Cache\ProfiledRedis;
use MagePsycho\Profiler\Model\Instrumentation\Guard;
use MagePsycho\Profiler\Model\Instrumentation\RedisCapture;
use MagePsycho\Profiler\Model\Instrumentation\Settings;
use MagePsycho\Profiler\Model\Instrumentation\Timer;
use MagePsycho\Profiler\Model\Instrumentation\TimerId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The client is never connected here - a command against a dead socket throws, which is precisely what
 * these tests need: the timer must open and close either way, and no network is involved.
 */
class ProfiledRedisTest extends TestCase
{
    /**
     * @var string[]
     */
    private $startedIds = [];

    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis is not installed');
        }

        $this->startedIds = [];
        Profiler::reset();

        /* Wire commands are opt-in, so every test that expects one has to ask for it. */
        putenv('MAGE_PROFILER_REDIS=1');
    }

    protected function tearDown(): void
    {
        Profiler::reset();
        putenv('MAGE_PROFILER_REDIS');
    }

    /**
     * @return void
     */
    public function testCommandsAreTimedUnderTheirOwnName(): void
    {
        $this->registerDriver();

        $this->call($this->createClient(), 'mget', [['a', 'b']]);

        /* The key family, plus how many more keys the command asked for. */
        $this->assertSame(['REDIS:MGET (A +1)'], $this->startedIds);
    }

    /**
     * The whole point of the family reduction: a per-entity key must not become a per-entity row.
     *
     * @return void
     */
    public function testKeysAreReducedToTheirFamily(): void
    {
        $this->registerDriver();
        $client = $this->createClient();

        $this->call($client, 'get', ['69d_:CAT_P_828']);
        $this->call($client, 'get', ['69d_:CAT_P_1904']);

        $this->assertSame(['REDIS:GET (CAT_P)', 'REDIS:GET (CAT_P)'], $this->startedIds);
    }

    /**
     * MAGE_PROFILER_REDIS=keys is the opt-in escape hatch, with all the cardinality that implies.
     *
     * @return void
     */
    public function testKeysModeRecordsTheWholeKey(): void
    {
        putenv('MAGE_PROFILER_REDIS=keys');
        $this->registerDriver();

        $this->call($this->createClient(), 'get', ['69d_:CAT_P_828']);

        $this->assertSame(['REDIS:GET (CAT_P_828)'], $this->startedIds);
    }

    /**
     * The command set the Symfony cache and the tag adapter actually issue.
     *
     * @param string $method
     * @param array<int, mixed> $args
     * @param string $expected
     * @return void
     * @dataProvider commandDataProvider
     */
    #[DataProvider('commandDataProvider')]
    public function testCommandNames(string $method, array $args, string $expected): void
    {
        $this->registerDriver();

        $this->call($this->createClient(), $method, $args);

        $this->assertSame(['REDIS:' . $expected], $this->startedIds);
    }

    /**
     * Commands that act on a key carry its family; the rest carry nothing.
     *
     * @return array<string, array{0: string, 1: array<int, mixed>, 2: string}>
     */
    public static function commandDataProvider(): array
    {
        return [
            'get' => ['get', ['BLOCK_HTML_9F2'], 'GET (BLOCK_HTML_9F2)'],
            'setex' => ['setex', ['CAT_P_828', 60, 'v'], 'SETEX (CAT_P)'],
            'del' => ['del', ['CAT_P_828'], 'DEL (CAT_P)'],
            'unlink' => ['unlink', ['CAT_P_828'], 'UNLINK (CAT_P)'],
            'expire' => ['expire', ['CAT_P_828', 60], 'EXPIRE (CAT_P)'],
            'exec' => ['exec', [], 'EXEC'],
            'sadd' => ['sadd', ['CACHE_TAG_1', 'v'], 'SADD (CACHE_TAG)'],
            'srem' => ['srem', ['CACHE_TAG_1', 'v'], 'SREM (CACHE_TAG)'],
            'smembers' => ['smembers', ['CACHE_TAG_1'], 'SMEMBERS (CACHE_TAG)'],
            'sunion' => ['sunion', ['CACHE_TAG_1', 'CACHE_TAG_2'], 'SUNION (CACHE_TAG)'],
            'sinter' => ['sinter', ['CACHE_TAG_1', 'CACHE_TAG_2'], 'SINTER (CACHE_TAG)'],
            'sdiff' => ['sdiff', ['CACHE_TAG_1', 'CACHE_TAG_2'], 'SDIFF (CACHE_TAG)'],
            'select carries no key' => ['select', [1], 'SELECT'],
            'multi carries no key' => ['multi', [], 'MULTI'],
        ];
    }

    /**
     * The connect is lowercase, so it never reads as a Redis command word.
     *
     * @return void
     */
    public function testConnectIsTimed(): void
    {
        $this->registerDriver();

        $client = $this->createClient();

        try {
            /* Port 1 is reserved and refuses instantly - no waiting, no live server needed. */
            $client->connect('127.0.0.1', 1, 0.01);
        } catch (\Throwable $e) {
            /* Expected. */
        }

        $this->assertSame(['REDIS:connect'], $this->startedIds);
    }

    /**
     * A client whose collaborators were never handed over must still work, untimed.
     *
     * @return void
     */
    public function testUnconfiguredClientRecordsNothing(): void
    {
        $this->registerDriver();

        $this->call(new ProfiledRedis(), 'mget', [['a']]);

        $this->assertSame([], $this->startedIds);
    }

    /**
     * @param string $value
     * @return void
     * @dataProvider offValueDataProvider
     */
    #[DataProvider('offValueDataProvider')]
    public function testOffModeRecordsNothing(string $value): void
    {
        putenv('MAGE_PROFILER_REDIS=' . $value);
        $this->registerDriver();

        $this->call($this->createClient(), 'mget', [['a']]);

        $this->assertSame([], $this->startedIds);
    }

    /**
     * @return array<string, string[]>
     */
    public static function offValueDataProvider(): array
    {
        return [
            'zero' => ['0'],
            'false' => ['false'],
            'off' => ['off'],
            'no' => ['no'],
        ];
    }

    /**
     * @return void
     */
    public function testNothingIsRecordedWhileProfilerDisabled(): void
    {
        $this->registerDriver();
        Profiler::disable();

        $this->call($this->createClient(), 'mget', [['a']]);

        $this->assertSame([], $this->startedIds);
    }

    /**
     * A dead connection throws from inside the timer; a leaked timer would nest every later id
     * underneath this one.
     *
     * @return void
     */
    public function testTimerIsClosedWhenTheCommandThrows(): void
    {
        $this->registerDriver();
        $client = $this->createClient();

        $this->call($client, 'mget', [['CAT_P_1']]);
        $this->call($client, 'sadd', ['CACHE_TAG_1', 'v']);

        $this->assertSame(['REDIS:MGET (CAT_P)', 'REDIS:SADD (CACHE_TAG)'], $this->startedIds);
    }

    /**
     * Call a command and swallow the connection error it raises.
     *
     * @param ProfiledRedis $client
     * @param string $method
     * @param array<int, mixed> $args
     * @return void
     */
    private function call(ProfiledRedis $client, string $method, array $args): void
    {
        try {
            $client->{$method}(...$args);
        } catch (\Throwable $e) {
            /* No server - the point is the timer, not the reply. */
        }
    }

    /**
     * Wire commands are opt-in.
     *
     * They nest inside the CACHE row that issued them, so on by default meant every report carried a
     * second, finer copy of what the REDIS:load and REDIS:save rows above them already said - hundreds
     * of spans on a cache-cold page. Unset now means off; MAGE_PROFILER_REDIS=1 asks for them.
     *
     * @return void
     */
    public function testUnsetRecordsNothing(): void
    {
        putenv('MAGE_PROFILER_REDIS');
        $this->registerDriver();

        $this->call($this->createClient(), 'get', ['CAT_P_828']);

        $this->assertSame([], $this->startedIds);
    }

    /**
     * @return ProfiledRedis
     */
    private function createClient(): ProfiledRedis
    {
        $settings = new Settings();
        $client   = new ProfiledRedis();
        $client->setProfiler(
            new Guard($settings),
            new Timer(),
            new TimerId($settings),
            $settings,
            new RedisCapture()
        );

        return $client;
    }

    /**
     * Register a spying driver. Profiler::add() enables the profiler as a side effect.
     *
     * @return void
     */
    private function registerDriver(): void
    {
        $driver = $this->createMock(DriverInterface::class);
        $driver->method('start')
            ->willReturnCallback(function ($timerId): void {
                $this->startedIds[] = $timerId;
            });

        Profiler::add($driver);
    }
}
