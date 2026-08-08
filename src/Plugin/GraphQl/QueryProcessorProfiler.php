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

use GraphQL\Language\AST\DocumentNode;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use Magento\Framework\GraphQl\Query\QueryProcessor;
use Magento\Framework\GraphQl\Schema;
use MagePsycho\Profiler\Model\Instrumentation\Guard;
use MagePsycho\Profiler\Model\Instrumentation\Settings;
use MagePsycho\Profiler\Model\Instrumentation\Timer;
use MagePsycho\Profiler\Model\Instrumentation\TimerId;

/**
 * Root timer for a GraphQL request: "GRAPHQL:query (getProducts)".
 *
 * Gives the resolver rows a parent to hang from, so the tabular output shows a tree and the percentage
 * column means something.
 *
 * The operation name is client-supplied, so it is sanitised and subject to the shared cardinality cap -
 * a client sending a unique operation name per request cannot blow up the report.
 */
class QueryProcessorProfiler
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
     * @param QueryProcessor $subject
     * @param callable $proceed
     * @param Schema $schema
     * @param DocumentNode|string $source
     * @param ContextInterface|null $contextValue
     * @param array<string, mixed>|null $variableValues
     * @param string|null $operationName
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundProcess(
        QueryProcessor $subject,
        callable $proceed,
        Schema $schema,
        $source,
        ?ContextInterface $contextValue = null,
        ?array $variableValues = null,
        ?string $operationName = null
    ) {
        $call = static function () use ($proceed, $schema, $source, $contextValue, $variableValues, $operationName) {
            return $proceed($schema, $source, $contextValue, $variableValues, $operationName);
        };

        if (!$this->guard->isActive(Settings::AREA_GRAPHQL)) {
            return $call();
        }

        $operation = $operationName !== null && $operationName !== '' ? $operationName : 'anonymous';

        return $this->timer->measure(
            $this->timerId->build(self::PREFIX, 'query', $operation),
            $call
        );
    }
}
