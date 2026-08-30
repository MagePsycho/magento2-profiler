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

namespace MagePsycho\Profiler\Test\Unit\Model\Instrumentation;

use MagePsycho\Profiler\Model\Instrumentation\RedisCapture;
use PHPUnit\Framework\TestCase;

class RedisCaptureTest extends TestCase
{
    /**
     * The whole point: "MGET (BLOCK)" does not say it fetched three keys, and this does.
     *
     * @return void
     */
    public function testKeysAreRenderedOntoTheCommandLine(): void
    {
        $payload = (new RedisCapture())->capture('MGET', [['zc:k:BLOCK_a', 'zc:k:BLOCK_b']]);

        $this->assertSame('MGET zc:k:BLOCK_a zc:k:BLOCK_b', $payload['sql']);
        $this->assertArrayNotHasKey('binds', $payload);
    }

    /**
     * phpredis takes a list where it takes many, and Magento's callers use both forms.
     *
     * @return void
     */
    public function testVariadicAndArrayFormsRenderIdentically(): void
    {
        $capture = new RedisCapture();

        $this->assertSame(
            $capture->capture('DEL', [['a', 'b']])['sql'],
            $capture->capture('DEL', ['a', 'b'])['sql']
        );
    }

    /**
     * A payload is not a key. It goes to the bind list, where the viewer shows it separately, and the
     * command line stays readable.
     *
     * @return void
     */
    public function testPayloadArgumentsBecomeBinds(): void
    {
        $payload = (new RedisCapture())->capture('SETEX', ['zc:k:BLOCK_a', 3600, '<div>cached</div>']);

        $this->assertSame('SETEX zc:k:BLOCK_a 3600', $payload['sql']);
        $this->assertSame(['<div>cached</div>'], $payload['binds']);
    }

    /**
     * SADD members are cache ids, not payloads - they belong on the line.
     *
     * @return void
     */
    public function testMembersStayOnTheLine(): void
    {
        $payload = (new RedisCapture())->capture('SADD', ['zc:tags', 'CAT_P_1', 'CAT_P_2']);

        $this->assertSame('SADD zc:tags CAT_P_1 CAT_P_2', $payload['sql']);
        $this->assertArrayNotHasKey('binds', $payload);
    }

    /**
     * A cached block runs to tens of kilobytes. The first line of it is worth seeing; the rest is not,
     * and neither is the cost of carrying it.
     *
     * @return void
     */
    public function testLongValuesAreTruncatedAndSized(): void
    {
        $payload = (new RedisCapture())->capture('SET', ['zc:k:BLOCK_a', str_repeat('x', 5000)]);

        $bind = $payload['binds'][0];

        $this->assertStringStartsWith(str_repeat('x', 96) . '...', $bind);
        $this->assertStringContainsString('4.9 KB', $bind);
        $this->assertLessThan(200, strlen($bind));
    }

    /**
     * Serialized and compressed payloads are binary. A raw one in a log file is unreadable at best and
     * a terminal escape sequence at worst, so it is reported by size instead.
     *
     * @return void
     */
    public function testBinaryValuesAreReportedBySizeNotContent(): void
    {
        $payload = (new RedisCapture())->capture('SET', ['zc:k:BLOCK_a', "gz\x00\x01\x02binary"]);

        $this->assertStringStartsWith('<binary ', $payload['binds'][0]);
        $this->assertStringNotContainsString("\x00", $payload['binds'][0]);
    }

    /**
     * On a stock install almost every value here is compressed, so naming the encoding is the
     * difference between answering "why can I not see this" and only raising it.
     *
     * @return void
     */
    public function testKnownEncodingsAreNamed(): void
    {
        $capture = new RedisCapture();

        $this->assertStringStartsWith(
            '<gzip ',
            $capture->capture('SET', ['k', "\x1f\x8b\x08\x00compressed payload"])['binds'][0]
        );
        $this->assertStringStartsWith(
            '<zstd ',
            $capture->capture('SET', ['k', "\x28\xb5\x2f\xfd\x00payload"])['binds'][0]
        );
        $this->assertStringStartsWith(
            '<gzip ',
            $capture->capture('SET', ['k', "gz:\x00\x01payload"])['binds'][0]
        );
    }

    /**
     * @return void
     */
    public function testScalarArgumentsAreReadable(): void
    {
        $payload = (new RedisCapture())->capture('EXPIRE', ['zc:k:BLOCK_a', 3600]);

        $this->assertSame('EXPIRE zc:k:BLOCK_a 3600', $payload['sql']);
    }

    /**
     * @return void
     */
    public function testCommandWithNoArgumentsIsStillCaptured(): void
    {
        $this->assertSame('MULTI', (new RedisCapture())->capture('MULTI', [])['sql']);
    }

    /**
     * A tag SUNION can name hundreds of sets. The line says how many it did not draw rather than
     * growing without limit.
     *
     * @return void
     */
    public function testArgumentCountIsCapped(): void
    {
        $keys = [];
        for ($i = 0; $i < 40; $i++) {
            $keys[] = 'zc:ti:TAG_' . $i;
        }

        $line = (new RedisCapture())->capture('SUNION', [$keys])['sql'];

        $this->assertStringContainsString('zc:ti:TAG_0 ', $line);
        $this->assertStringContainsString('...(16 more)', $line);
        $this->assertStringNotContainsString('TAG_39', $line);
    }

    /**
     * MAXLEN bounds one command; only a budget bounds a cache-cold page. Once it is gone, capture
     * stops rather than degrading.
     *
     * @return void
     */
    public function testBudgetIsSpentAndThenCaptureStops(): void
    {
        $capture = new RedisCapture();
        $key     = str_repeat('k', 90);

        /* 262144 bytes at ~90 per command needs a few thousand calls to exhaust. */
        $captured = 0;
        for ($i = 0; $i < 6000; $i++) {
            if ($capture->capture('GET', [$key]) !== null) {
                $captured++;
            }
        }

        $this->assertGreaterThan(0, $captured, 'The first commands must be captured');
        $this->assertLessThan(6000, $captured, 'The budget must eventually stop capture');
        $this->assertNull($capture->capture('GET', [$key]), 'Once spent, it stays spent');
    }
}
