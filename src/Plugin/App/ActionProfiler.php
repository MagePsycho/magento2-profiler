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

namespace MagePsycho\Profiler\Plugin\App;

use Magento\Framework\App\Action\Action as LegacyAction;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use MagePsycho\Profiler\Model\Instrumentation\Guard;
use MagePsycho\Profiler\Model\Instrumentation\Timer;
use MagePsycho\Profiler\Model\Instrumentation\TimerId;

/**
 * Times controller dispatch: "CONTROLLER:catalog_product_view".
 *
 * Core times controllers, but only the ones extending the deprecated Magento\Framework\App\Action\Action
 * base class, which emits CONTROLLER_ACTION:<full action name> from its dispatch(). Controllers that
 * implement ActionInterface directly - the modern majority, and every Hyva controller - go through
 * FrontController::processRequest, which has no per-action timer at all. Between the root "magento"
 * timer and the individual SQL and cache rows there is currently nothing.
 *
 * That gap is the one this module already fills for the other two request types: a REST call reports
 * WEBAPI:<METHOD> <route> and a GraphQL call reports GRAPHQL:query. A storefront or adminhtml page had
 * no equivalent. This is it.
 *
 * Unlike most instrumentation here there is no area flag. It is one timer per request - too cheap to be
 * worth a switch, and the row every other row hangs underneath.
 */
class ActionProfiler
{
    private const PREFIX = 'CONTROLLER';

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
     * @var RequestInterface
     */
    private $request;

    /**
     * @param Guard $guard
     * @param Timer $timer
     * @param TimerId $timerId
     * @param RequestInterface $request
     */
    public function __construct(Guard $guard, Timer $timer, TimerId $timerId, RequestInterface $request)
    {
        $this->guard   = $guard;
        $this->timer   = $timer;
        $this->timerId = $timerId;
        $this->request = $request;
    }

    /**
     * @param ActionInterface $subject
     * @param callable $proceed
     * @return mixed
     */
    public function aroundExecute(ActionInterface $subject, callable $proceed)
    {
        /*
         * Legacy actions are core's already: Action::dispatch() opens CONTROLLER_ACTION:<name> and
         * calls execute() inside it, so timing execute() here would report the same work twice under
         * two different names, one nested in the other.
         */
        if (!$this->guard->isProfiling() || $subject instanceof LegacyAction) {
            return $proceed();
        }

        return $this->timer->measure(
            $this->timerId->build(self::PREFIX, $this->resolveName($subject)),
            $proceed
        );
    }

    /**
     * The route as configured, falling back to the class that serves it.
     *
     * getFullActionName() is what core's own CONTROLLER_ACTION: uses and it is the more useful label -
     * "checkout_index_index" says where you are, "Index\Index" does not. It only exists on the HTTP
     * request though, and it is empty until the router has matched, so both cases fall back to the
     * class name rather than reporting an empty id.
     *
     * @param ActionInterface $subject
     * @return string
     */
    private function resolveName(ActionInterface $subject): string
    {
        if ($this->request instanceof HttpRequest) {
            $name = (string)$this->request->getFullActionName();
            if ($name !== '') {
                return $name;
            }
        }

        return $this->timerId->shortClass(get_class($subject), 3);
    }
}
