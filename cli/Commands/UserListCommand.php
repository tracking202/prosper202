<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UserListCommand extends BaseCommand
{
    protected static $defaultName = 'user:list';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('List all users');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $this->render($output, $this->client()->get('users'), $input);
        return Command::SUCCESS;
    }
}
