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

namespace MagePsycho\Profiler\Plugin\Console;

use MagePsycho\Profiler\Model\Instrumentation\Guard;
use MagePsycho\Profiler\Model\Instrumentation\Settings;
use MagePsycho\Profiler\Model\Instrumentation\Timer;
use MagePsycho\Profiler\Model\Instrumentation\TimerId;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Root timer for CLI runs: "CLI:indexer:reindex".
 *
 * Under php-fpm every timer nests below the `magento` timer that App\Bootstrap::run() opens, which is
 * what gives the tabular output a single tree and meaningful percentages. bin/magento has no equivalent,
 * so a CLI profile is otherwise a flat list of unrelated roots.
 *
 * Attached to Symfony's Command::run() rather than Magento\Framework\Console\Cli::doRun(): bin/magento
 * line 22 builds the Cli with a plain `new`, so it never becomes an interceptor no matter how public
 * doRun() is. Commands, by contrast, are resolved through the ObjectManager by
 * Magento\Framework\Console\CommandList, so they are intercepted - including third-party ones.
 *
 * Only the command name is recorded. Arguments carry paths, ids and occasionally credentials.
 */
class CliProfiler
{
    private const PREFIX = 'CLI';

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
     * @param Command $subject
     * @param callable $proceed
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return mixed
     */
    public function aroundRun(Command $subject, callable $proceed, InputInterface $input, OutputInterface $output)
    {
        $call = static function () use ($proceed, $input, $output) {
            return $proceed($input, $output);
        };

        /* enter() keeps a command that internally runs another command from nesting a second root. */
        if (!$this->guard->isActive(Settings::AREA_CLI) || !$this->guard->enter(Settings::AREA_CLI)) {
            return $call();
        }

        $name = (string)$subject->getName();
        if ($name === '') {
            $name = $this->timerId->shortClass(get_class($subject));
        }

        try {
            return $this->timer->measure($this->timerId->build(self::PREFIX, $name), $call);
        } finally {
            $this->guard->leave(Settings::AREA_CLI);
        }
    }
}
