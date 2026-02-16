<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UserGetCommand extends BaseCommand
{
    protected static $defaultName = 'user:get';

    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Get user details with roles')
            ->addArgument('id', InputArgument::REQUIRED, 'User ID');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $this->render($output, $this->client()->get('users/' . $input->getArgument('id')), $input);
        return Command::SUCCESS;
    }
}
