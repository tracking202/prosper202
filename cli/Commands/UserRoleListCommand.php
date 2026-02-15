<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UserRoleListCommand extends BaseCommand
{
    protected static $defaultName = 'user:role:list';

    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('List all available roles');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $this->render($output, $this->client()->get('users/roles'), $input);
        return Command::SUCCESS;
    }
}
