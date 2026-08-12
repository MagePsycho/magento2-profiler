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

namespace MagePsycho\Profiler\Plugin\Mail;

use Magento\Framework\Mail\TransportInterface;
use MagePsycho\Profiler\Model\Instrumentation\Guard;
use MagePsycho\Profiler\Model\Instrumentation\Settings;
use MagePsycho\Profiler\Model\Instrumentation\Timer;
use MagePsycho\Profiler\Model\Instrumentation\TimerId;

/**
 * Times message sending: "MAIL:send (Smtp\Transport)".
 *
 * Magento sends transactional mail synchronously inside the request that triggered it, so an SMTP
 * handshake to a slow relay is charged to the customer placing the order. That cost currently appears
 * nowhere - the transport does no SQL, no cache work, and does not go through the HTTP clients.
 *
 * The detail is the transport class, not the recipient: one row per transport, never one per customer.
 * No address, subject or body is recorded anywhere.
 */
class TransportProfiler
{
    private const PREFIX = 'MAIL';

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
     * @param TransportInterface $subject
     * @param callable $proceed
     * @return mixed
     */
    public function aroundSendMessage(TransportInterface $subject, callable $proceed)
    {
        if (!$this->guard->isActive(Settings::AREA_MAIL)) {
            return $proceed();
        }

        return $this->timer->measure(
            $this->timerId->build(self::PREFIX, 'send', $this->timerId->shortClass(get_class($subject), 2)),
            $proceed
        );
    }
}
