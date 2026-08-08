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
use MagePsycho\Profiler\Model\Profiler\Output\Tabular;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Enable the profiler, defaulting to the tabular output type.
 *
 * Writes the very same var/profiler.flag file as core `dev:profiler:enable`, so the two commands are
 * interchangeable - this one just knows about `tabular` and prints the usage hints for it.
 */
class EnableCommand extends Command
{
    public const COMMAND_NAME       = 'magepsycho:profiler:enable';
    public const PROFILER_FLAG_FILE = 'var/profiler.flag';
    public const TYPE_DEFAULT       = 'tabular';

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
            ->setDescription('Enable the profiler (defaults to the MagePsycho tabular output type).')
            ->addArgument(
                'type',
                InputArgument::OPTIONAL,
                'Output type: tabular, html or csvfile',
                self::TYPE_DEFAULT
            );

        parent::configure();
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $type = (string)$input->getArgument('type');

        $this->file->write(BP . '/' . self::PROFILER_FLAG_FILE, $type);
        $output->writeln(sprintf('<info>Profiler enabled with %s output.</info>', $type));

        if ($type === self::TYPE_DEFAULT) {
            $output->writeln(sprintf('<info>Log file: %s%s</info>', BP, Tabular::DEFAULT_FILEPATH));
            $output->writeln('');
            $output->writeln('<comment>Per-request activation without the flag file:</comment>');
            $output->writeln('  CLI : MAGE_PROFILER=tabular bin/magento <command>');
            $output->writeln('  API : send a "MAGE_PROFILER=tabular" cookie');
            $output->writeln('        (non-developer mode also needs MAGE_PROFILER=tabular:$MAGE_PROFILER_SECRET)');
            $output->writeln('');
            $output->writeln('<comment>Optional env overrides:</comment>');
            $output->writeln('  MAGE_PROFILER_LOG, MAGE_PROFILER_MIN_MS, MAGE_PROFILER_FILTER');
            $output->writeln('  MAGE_PROFILER_CLI_STDERR');
        }

        return Command::SUCCESS;
    }
}
