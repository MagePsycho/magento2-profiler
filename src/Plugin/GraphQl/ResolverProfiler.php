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

namespace MagePsycho\Profiler\Plugin\GraphQl;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use MagePsycho\Profiler\Model\Instrumentation\Guard;
use MagePsycho\Profiler\Model\Instrumentation\Settings;
use MagePsycho\Profiler\Model\Instrumentation\Timer;
use MagePsycho\Profiler\Model\Instrumentation\TimerId;

/**
 * Times every GraphQL resolver as "GRAPHQL:Resolver\Products (products)".
 *
 * Declared on the interface, so it covers every resolver in every module without naming any of them.
 * The value is the Cnt column: an N+1 resolver shows up as one row with a call count in the hundreds,
 * which is the single hardest thing to see in Magento GraphQL performance work.
 *
 * Resolvers nest inside themselves, which is why the timing goes through Timer (Profiler::stop() closes
 * the most recent occurrence of an id) rather than Util\Benchmark (which closes the first).
 */
class ResolverProfiler
{
    private const PREFIX = 'GRAPHQL';

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
     * @param Guard $guard
     * @param Timer $timer
     * @param TimerId $timerId
     */
    public function __construct(Guard $guard, Timer $timer, TimerId $timerId)
    {
        $this->guard   = $guard;
        $this->timer   = $timer;
        $this->timerId = $timerId;
    }

    /**
     * @param ResolverInterface $subject
     * @param callable $proceed
     * @param Field $field
     * @param mixed $context
     * @param ResolveInfo $info
     * @param array<string, mixed>|null $value
     * @param array<string, mixed>|null $args
     * @return mixed
     */
    public function aroundResolve(
        ResolverInterface $subject,
        callable $proceed,
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        $call = static function () use ($proceed, $field, $context, $info, $value, $args) {
            return $proceed($field, $context, $info, $value, $args);
        };

        if (!$this->guard->isActive(Settings::AREA_GRAPHQL)) {
            return $call();
        }

        return $this->timer->measure(
            $this->timerId->build(
                self::PREFIX,
                $this->timerId->shortClass(get_class($subject), 2),
                (string)$field->getName()
            ),
            $call
        );
    }
}
