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

use Magento\Framework\Filesystem\Io\File;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Disable the profiler by removing var/profiler.flag.
 */
class DisableCommand extends Command
{
    public const COMMAND_NAME = 'magepsycho:profiler:disable';

    /**
     * @var File
     */
    private $file;

    /**
     * @param File $file
     * @param string|null $name
     */
    public function __construct(File $file, ?string $name = null)
    {
        $this->file = $file;
        parent::__construct($name);
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this->setName(self::COMMAND_NAME)
            ->setDescription('Disable the profiler (removes var/profiler.flag).');

        parent::configure();
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $flagFile = BP . '/' . EnableCommand::PROFILER_FLAG_FILE;

        if (!$this->file->fileExists($flagFile)) {
            $output->writeln('<info>Profiler is already disabled.</info>');

            return Command::SUCCESS;
        }

        $this->file->rm($flagFile);
        $output->writeln('<info>Profiler disabled.</info>');
        $output->writeln(
            '<comment>Note: MAGE_PROFILER env var / cookie activation is independent of this flag.</comment>'
        );

        return Command::SUCCESS;
    }
}
