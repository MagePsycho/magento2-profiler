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

namespace MagePsycho\Profiler\Plugin\Webapi;

use Magento\Framework\Webapi\ServiceOutputProcessor;
use MagePsycho\Profiler\Model\Instrumentation\Guard;
use MagePsycho\Profiler\Model\Instrumentation\Settings;
use MagePsycho\Profiler\Model\Instrumentation\Timer;
use MagePsycho\Profiler\Model\Instrumentation\TimerId;

/**
 * Times REST output serialization as "WEBAPI:output (ProductRepositoryInterface::getList)".
 *
 * Consistently one of the most expensive parts of a REST response and one of the least obvious: the
 * processor reflects over every returned DTO to convert it to an array. On list endpoints it frequently
 * costs more than the query that produced the data.
 */
class OutputProcessorProfiler
{
    private const PREFIX = 'WEBAPI';

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
     * @param ServiceOutputProcessor $subject
     * @param callable $proceed
     * @param mixed $data
     * @param string $serviceClassName
     * @param string $serviceMethodName
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundProcess(
        ServiceOutputProcessor $subject,
        callable $proceed,
        $data,
        $serviceClassName,
        $serviceMethodName
    ) {
        if (!$this->guard->isActive(Settings::AREA_WEBAPI)) {
            return $proceed($data, $serviceClassName, $serviceMethodName);
        }

        $detail = $this->timerId->shortClass((string)$serviceClassName, 1) . '::' . $serviceMethodName;

        return $this->timer->measure(
            $this->timerId->build(self::PREFIX, 'output', $detail),
            static function () use ($proceed, $data, $serviceClassName, $serviceMethodName) {
                return $proceed($data, $serviceClassName, $serviceMethodName);
            }
        );
    }
}
