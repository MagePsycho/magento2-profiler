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

namespace MagePsycho\Profiler\Plugin\Checkout;

use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\Validator;
use MagePsycho\Profiler\Model\Instrumentation\Guard;
use MagePsycho\Profiler\Model\Instrumentation\Settings;
use MagePsycho\Profiler\Model\Instrumentation\Timer;
use MagePsycho\Profiler\Model\Instrumentation\TimerId;

/**
 * Times cart price rule evaluation: "RULE:process (12)" and "RULE:processShippingAmount".
 *
 * Rule validation is quadratic in the shape that matters: every active rule is evaluated against
 * every quote item, and each evaluation walks the rule's condition tree, which can itself query the
 * catalog. A store that has accumulated forty rules over a few years pays for all forty on every
 * totals collection, and a single rule with an expensive condition is invisible in aggregate.
 *
 * Hence the rule id in the detail. The Cnt column already says how many item-by-rule evaluations
 * happened; the id is what lets one row be traced back to one row in the admin grid and switched off.
 * Rule *names* are admin-entered free text - long, translated, and prone to punctuation that has no
 * business in a timer id - so the id is the stable, bounded identifier.
 *
 * The method is named `process` rather than `validate`; the timer follows the method, as everywhere
 * else in this module, so a reader grepping the codebase for the row they are looking at finds it.
 */
class SalesRuleProfiler
{
    private const PREFIX = 'RULE';

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
     * One rule against one item.
     *
     * @param Validator $subject
     * @param callable $proceed
     * @param AbstractItem $item
     * @param Rule $rule
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundProcess(Validator $subject, callable $proceed, AbstractItem $item, Rule $rule)
    {
        if (!$this->guard->isActive(Settings::AREA_CHECKOUT)) {
            return $proceed($item, $rule);
        }

        return $this->timer->measure(
            $this->timerId->build(self::PREFIX, 'process', $this->ruleId($rule)),
            static function () use ($proceed, $item, $rule) {
                return $proceed($item, $rule);
            }
        );
    }

    /**
     * Shipping discounts are a separate pass over the same rules, against the address.
     *
     * @param Validator $subject
     * @param callable $proceed
     * @param Address $address
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundProcessShippingAmount(Validator $subject, callable $proceed, Address $address)
    {
        if (!$this->guard->isActive(Settings::AREA_CHECKOUT)) {
            return $proceed($address);
        }

        return $this->timer->measure(
            $this->timerId->build(self::PREFIX, 'processShippingAmount'),
            static function () use ($proceed, $address) {
                return $proceed($address);
            }
        );
    }

    /**
     * A rule with no id has not been persisted, which a validator should never be handed - but it is
     * reachable from third-party code, and an id-less row is better than a fatal in a profiler.
     *
     * @param Rule $rule
     * @return string
     */
    private function ruleId(Rule $rule): string
    {
        $id = $rule->getId();

        return $id === null || $id === '' ? 'unsaved' : (string)$id;
    }
}
