<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RotatorListCommand extends BaseCommand
{
    protected static $defaultName = 'rotator:list';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('List rotators')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Max results', '50')
            ->addOption('offset', 'o', InputOption::VALUE_REQUIRED, 'Offset', '0');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->client()->get('rotators', [
            'limit' => $input->getOption('limit'),
            'offset' => $input->getOption('offset'),
        ]);
        $this->render($output, $result, $input);
        return Command::SUCCESS;
    }
}
