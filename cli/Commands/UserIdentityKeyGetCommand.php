<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The key a user's own server signs customer ids with:
 * cust_sig = hex(HMAC-SHA256(linking_key, "<cust_type>:<cust>")).
 */
class UserIdentityKeyGetCommand extends BaseCommand
{
    protected static $defaultName = 'user:identity-key:get';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Show the identity linking key your server signs customer ids with (minted on first use)')
            ->addArgument('user_id', InputArgument::REQUIRED, 'User ID');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $this->render($output, $this->client()->get('users/' . $input->getArgument('user_id') . '/identity-key'), $input);
        return Command::SUCCESS;
    }
}
