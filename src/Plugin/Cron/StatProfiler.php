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

namespace MagePsycho\Profiler\Plugin\Cron;

use Magento\Framework\Profiler;
use Magento\Framework\Profiler\Driver\Standard\Stat;
use MagePsycho\Profiler\Model\Instrumentation\Guard;
use MagePsycho\Profiler\Model\Instrumentation\Settings;
use MagePsycho\Profiler\Model\Instrumentation\TimerId;

/**
 * Times individual cron jobs: "CRON:catalog_product_outdated_price_values_cleanup".
 *
 * Magento already measures every cron job. It just measures it somewhere nobody can see:
 * Cron\Observer\ProcessCronQueueObserver builds its *own* Stat through StatFactory, starts a timer
 * named "job <code>" around each job, and writes the result out as a line of JSON. None of it goes
 * through \Magento\Framework\Profiler, so none of it reaches this module - and `bin/magento cron:run`
 * profiles as a single opaque CLI:cron:run row with an hour of work hidden inside it.
 *
 * This bridges the two. Stat is the object cron drives directly, so plugging start() and stop() picks
 * the job timings up at the source and mirrors them into the real profiler, where they nest under
 * CLI:cron:run alongside the SQL, cache and index rows the jobs produced.
 *
 * The seam is not a novelty: Adobe's own NewRelicReporting\Plugin\StatPlugin plugs these same two
 * methods, keyed off the same "job" prefix, to name cron transactions.
 *
 * Gated on MAGE_PROFILER_CLI rather than a flag of its own. Cron is a console command on 2.4.9, its
 * parent row is CLI:cron:run, and one switch that turns off both is easier to reason about than two.
 */
class StatProfiler
{
    private const PREFIX = 'CRON';

    /**
     * Cron's own timer id shape - sprintf('job %s', $jobName), ProcessCronQueueObserver::CRON_TIMERID.
     */
    private const CRON_TIMER_PREFIX = 'job ';

    /**
     * @var Guard
     */
    private $guard;

    /**
     * @var TimerId
     */
    private $timerId;

    /**
     * @param Guard $guard
     * @param TimerId $timerId
     */
    public function __construct(Guard $guard, TimerId $timerId)
    {
        $this->guard   = $guard;
        $this->timerId = $timerId;
    }

    /**
     * @param Stat $subject
     * @param string $timerId
     * @param float|null $time
     * @param int|null $realMemory
     * @param int|null $emallocMemory
     * @return null
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeStart(Stat $subject, $timerId, $time = null, $realMemory = null, $emallocMemory = null)
    {
        $jobTimerId = $this->resolve($timerId);
        if ($jobTimerId !== null) {
            Profiler::start($jobTimerId);
        }

        return null;
    }

    /**
     * @param Stat $subject
     * @param string $timerId
     * @param float|null $time
     * @param int|null $realMemory
     * @param int|null $emallocMemory
     * @return null
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeStop(Stat $subject, $timerId, $time = null, $realMemory = null, $emallocMemory = null)
    {
        $jobTimerId = $this->resolve($timerId);
        if ($jobTimerId !== null) {
            Profiler::stop($jobTimerId);
        }

        return null;
    }

    /**
     * The CRON: id for one of cron's timers, or null for everything else.
     *
     * Two things make this safe to sit on.
     *
     * The prefix test is load-bearing, not cosmetic. This module's own Standard driver keeps a Stat
     * too, so the plugin fires for every timer the profiler records - including the CRON: rows it
     * emits here. Those do not start with "job ", which is what stops the mirroring recursing.
     *
     * And it has to be cheap, for the same reason: a plain string comparison, first, before anything
     * reads config or touches an object graph, because it runs once per timer for the life of the
     * request rather than once per cron job.
     *
     * @param mixed $timerId
     * @return string|null
     */
    private function resolve($timerId): ?string
    {
        if (!is_string($timerId) || strncmp($timerId, self::CRON_TIMER_PREFIX, 4) !== 0) {
            return null;
        }

        if (!$this->guard->isActive(Settings::AREA_CLI)) {
            return null;
        }

        $jobCode = trim(substr($timerId, 4));

        return $this->timerId->build(self::PREFIX, $jobCode);
    }
}
