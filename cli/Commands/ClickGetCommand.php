<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ClickGetCommand extends BaseCommand
{
    protected static $defaultName = 'click:get';

    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Get full details of a single click')
            ->addArgument('id', InputArgument::REQUIRED, 'Click ID');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->client()->get('clicks/' . $input->getArgument('id'));
        $this->render($output, $result, $input);
        return Command::SUCCESS;
    }
}
