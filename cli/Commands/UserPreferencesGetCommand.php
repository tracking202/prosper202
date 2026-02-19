<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UserPreferencesGetCommand extends BaseCommand
{
    protected static $defaultName = 'user:prefs:get';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Get user preferences')
            ->addArgument('user_id', InputArgument::REQUIRED, 'User ID');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $this->render($output, $this->client()->get('users/' . $input->getArgument('user_id') . '/preferences'), $input);
        return Command::SUCCESS;
    }
}
