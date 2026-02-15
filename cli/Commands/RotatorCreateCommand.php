<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RotatorCreateCommand extends BaseCommand
{
    protected static $defaultName = 'rotator:create';

    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Create a new rotator')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Rotator name (required)')
            ->addOption('default_url', null, InputOption::VALUE_REQUIRED, 'Default redirect URL')
            ->addOption('default_campaign', null, InputOption::VALUE_REQUIRED, 'Default campaign ID')
            ->addOption('default_lp', null, InputOption::VALUE_REQUIRED, 'Default landing page ID');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getOption('name');
        if (!$name) {
            $output->writeln('<error>--name is required</error>');
            return Command::FAILURE;
        }

        $body = ['name' => $name];
        foreach (['default_url', 'default_campaign', 'default_lp'] as $f) {
            $val = $input->getOption($f);
            if ($val !== null) {
                $body[$f] = $val;
            }
        }

        $result = $this->client()->post('rotators', $body);
        $this->render($output, $result, $input);
        return Command::SUCCESS;
    }
}
