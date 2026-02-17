<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RotatorGetCommand extends BaseCommand
{
    protected static $defaultName = 'rotator:get';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Get rotator with all rules, criteria, and redirects')
            ->addArgument('id', InputArgument::REQUIRED, 'Rotator ID');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->client()->get('rotators/' . $input->getArgument('id'));
        $this->render($output, $result, $input);
        return Command::SUCCESS;
    }
}
