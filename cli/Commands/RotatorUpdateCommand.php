<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RotatorUpdateCommand extends BaseCommand
{
    protected static $defaultName = 'rotator:update';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Update a rotator')
            ->addArgument('id', InputArgument::REQUIRED, 'Rotator ID')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Rotator name')
            ->addOption('default_url', null, InputOption::VALUE_REQUIRED, 'Default URL')
            ->addOption('default_campaign', null, InputOption::VALUE_REQUIRED, 'Default campaign ID')
            ->addOption('default_lp', null, InputOption::VALUE_REQUIRED, 'Default landing page ID');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $body = [];
        foreach (['name', 'default_url', 'default_campaign', 'default_lp'] as $f) {
            $val = $input->getOption($f);
            if ($val !== null) {
                $body[$f] = $val;
            }
        }
        if (empty($body)) {
            $output->writeln('<error>Provide at least one field to update</error>');
            return Command::FAILURE;
        }

        $result = $this->client()->put('rotators/' . $input->getArgument('id'), $body);
        $this->render($output, $result, $input);
        return Command::SUCCESS;
    }
}
