<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UserPreferencesUpdateCommand extends BaseCommand
{
    protected static $defaultName = 'user:prefs:update';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Update user preferences')
            ->addArgument('user_id', InputArgument::REQUIRED, 'User ID')
            ->addOption('user-tracking-domain', null, InputOption::VALUE_REQUIRED, 'Tracking domain')
            ->addOption('user-account-currency', null, InputOption::VALUE_REQUIRED, 'Currency (3-letter code)')
            ->addOption('user-slack-incoming-webhook', null, InputOption::VALUE_REQUIRED, 'Slack webhook URL')
            ->addOption('user-daily-email', null, InputOption::VALUE_REQUIRED, 'Daily email: on/off')
            ->addOption('ipqs-api-key', null, InputOption::VALUE_REQUIRED, 'IPQS fraud detection API key');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $body = $this->collectOptions($input, ['user_tracking_domain', 'user_account_currency', 'user_slack_incoming_webhook', 'user_daily_email', 'ipqs_api_key']);
        if (empty($body)) {
            $output->writeln('<error>Provide at least one preference to update</error>');
            return Command::FAILURE;
        }
        $this->render($output, $this->client()->put('users/' . $input->getArgument('user_id') . '/preferences', $body), $input);
        return Command::SUCCESS;
    }
}
