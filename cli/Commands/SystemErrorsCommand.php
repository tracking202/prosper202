<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SystemErrorsCommand extends BaseCommand
{
    protected static $defaultName = 'system:errors';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Show recent MySQL errors')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Max results', '20');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $this->render($output, $this->client()->get('system/errors', ['limit' => $input->getOption('limit')]), $input);
        return Command::SUCCESS;
    }
}
