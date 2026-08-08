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

namespace MagePsycho\Profiler\Console\Command;

use Magento\Framework\App\State;
use MagePsycho\Profiler\Model\Config;
use MagePsycho\Profiler\Model\Profiler\Output\Tabular;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Report how the profiler is currently wired up.
 */
class StatusCommand extends Command
{
    public const COMMAND_NAME = 'magepsycho:profiler:status';

    /**
     * @var Config
     */
    private $config;

    /**
     * @var State
     */
    private $appState;

    /**
     * @param Config $config
     * @param State $appState
     * @param string|null $name
     */
    public function __construct(Config $config, State $appState, ?string $name = null)
    {
        $this->config   = $config;
        $this->appState = $appState;
        parent::__construct($name);
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this->setName(self::COMMAND_NAME)
            ->setDescription('Show the current profiler activation state and tabular output settings.');

        parent::configure();
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        //phpcs:disable Magento2.Functions.DiscouragedFunction
        $flagFile = BP . '/' . EnableCommand::PROFILER_FLAG_FILE;
        $flag     = is_file($flagFile) ? trim((string)file_get_contents($flagFile)) : '<not set>';

        $logPath = $this->config->getLogPath() !== '' ? $this->config->getLogPath() : Tabular::DEFAULT_FILEPATH;
        $logFile = BP . '/' . ltrim($logPath, '/');

        try {
            $mode = $this->appState->getMode();
        } catch (\Throwable $e) {
            $mode = 'unknown';
        }

        //phpcs:disable Magento2.Functions.DiscouragedFunction
        $rows = [
            ['Flag file (var/profiler.flag)', $flag],
            ['MAGE_PROFILER (env)', (string)(getenv('MAGE_PROFILER') ?: '<not set>')],
            ['MAGE_PROFILER_SECRET (env)', getenv('MAGE_PROFILER_SECRET') ? '<set>' : '<not set>'],
            ['Deploy mode', $mode],
            ['Cookie activation', $mode === State::MODE_DEVELOPER
                ? 'allowed (developer mode)'
                : (getenv('MAGE_PROFILER_SECRET') ? 'allowed with secret' : 'blocked (no secret set)')],
            ['Output enabled (admin)', $this->config->isEnabled() ? 'yes' : 'no'],
            ['Log file', $logFile],
            ['Log size', is_file($logFile) ? number_format((float)filesize($logFile) / 1024, 2) . ' KB' : '<none>'],
            ['Min time (ms)', (string)$this->config->getMinTimeMs()],
            ['Filter pattern', $this->config->getFilterPattern() !== '' ? $this->config->getFilterPattern() : '<none>'],
            ['CLI STDERR output', $this->config->isCliStderrEnabled() ? 'yes' : 'no'],
        ];
        //phpcs:enable Magento2.Functions.DiscouragedFunction

        $table = new Table($output);
        $table->setHeaders(['Setting', 'Value'])->setRows($rows)->render();

        return Command::SUCCESS;
    }
}
