<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UserApiKeyCreateCommand extends BaseCommand
{
    protected static $defaultName = 'user:apikey:create';

    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Generate a new API key for a user')
            ->addArgument('user_id', InputArgument::REQUIRED, 'User ID');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $this->render($output, $this->client()->post('users/' . $input->getArgument('user_id') . '/api-keys'), $input);
        return Command::SUCCESS;
    }
}
