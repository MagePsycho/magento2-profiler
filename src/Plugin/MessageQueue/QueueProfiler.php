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

namespace MagePsycho\Profiler\Plugin\MessageQueue;

use Magento\Framework\MessageQueue\ConsumerInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use MagePsycho\Profiler\Model\Instrumentation\Guard;
use MagePsycho\Profiler\Model\Instrumentation\Settings;
use MagePsycho\Profiler\Model\Instrumentation\Timer;
use MagePsycho\Profiler\Model\Instrumentation\TimerId;

/**
 * Times both ends of the queue: "QUEUE:publish (product_action_attribute.update)" and
 * "QUEUE:consume (Consumer)".
 *
 * Publishing happens inside a normal request - a mass action, a bulk API call - and a slow broker
 * charges that wait to the user who pressed the button. Consuming happens in a long-running CLI
 * process, which is the workload most worth profiling and the one least visible today: without this,
 * `queue:consumers:start` produces a report of SQL with nothing to attribute it to.
 *
 * Topic names come from queue configuration, so their cardinality is fixed by the codebase.
 */
class QueueProfiler
{
    private const PREFIX = 'QUEUE';

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
     * @param PublisherInterface $subject
     * @param callable $proceed
     * @param string $topicName
     * @param mixed $data
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundPublish(PublisherInterface $subject, callable $proceed, $topicName, $data)
    {
        $call = static function () use ($proceed, $topicName, $data) {
            return $proceed($topicName, $data);
        };

        /*
         * enter()/leave(), because PublisherPool implements this interface and forwards to the
         * configured publisher, which implements it too - both are interceptors, so without the guard
         * every publish is timed twice, once nested inside itself.
         */
        if (!$this->guard->isActive(Settings::AREA_QUEUE) || !$this->guard->enter(Settings::AREA_QUEUE)) {
            return $call();
        }

        try {
            return $this->timer->measure(
                $this->timerId->build(self::PREFIX, 'publish', (string)$topicName),
                $call
            );
        } finally {
            $this->guard->leave(Settings::AREA_QUEUE);
        }
    }

    /**
     * One timer for the whole batch, which is what process() is: the consumer takes up to
     * $maxNumberOfMessages off the queue and runs them.
     *
     * @param ConsumerInterface $subject
     * @param callable $proceed
     * @param int|null $maxNumberOfMessages
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundProcess(ConsumerInterface $subject, callable $proceed, $maxNumberOfMessages = null)
    {
        if (!$this->guard->isActive(Settings::AREA_QUEUE)) {
            return $proceed($maxNumberOfMessages);
        }

        return $this->timer->measure(
            $this->timerId->build(self::PREFIX, 'consume', $this->timerId->shortClass(get_class($subject), 1)),
            static function () use ($proceed, $maxNumberOfMessages) {
                return $proceed($maxNumberOfMessages);
            }
        );
    }
}
