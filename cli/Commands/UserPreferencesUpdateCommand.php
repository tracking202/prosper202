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
            ->setHelp('The answer shows each integration secret only as (stored) or (not stored), and {"stored": true} '
                . 'under --json.')
            ->addArgument('user_id', InputArgument::REQUIRED, 'User ID')
            ->addOption('user-tracking-domain', null, InputOption::VALUE_REQUIRED, 'Tracking domain')
            ->addOption('user-account-currency', null, InputOption::VALUE_REQUIRED, 'Currency (3-letter code)')
            ->addOption('user-slack-incoming-webhook', null, InputOption::VALUE_REQUIRED, 'Slack webhook URL')
            ->addOption('user-daily-email', null, InputOption::VALUE_REQUIRED, 'Daily email: on/off')
            ->addOption('ipqs-api-key', null, InputOption::VALUE_REQUIRED, 'IPQS fraud detection API key')
            ->addOption('user-pref-same-ip-fallback', null, InputOption::VALUE_REQUIRED, 'When a pixel names no click: 1 credits your latest click from the visitor\'s IP address in the last 30 days (a guess), 0 none');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $body = $this->collectOptions($input, ['user_tracking_domain', 'user_account_currency', 'user_slack_incoming_webhook', 'user_daily_email', 'ipqs_api_key', 'user_pref_same_ip_fallback']);
        if (empty($body)) {
            $output->writeln('<error>Provide at least one preference to update</error>');
            return Command::FAILURE;
        }
        $answer = $this->client()->put('users/' . $input->getArgument('user_id') . '/preferences', $body);
        $shown = $this->isJson($input) ? $answer : UserPreferencesGetCommand::withStoredStates($answer);
        $this->render($output, $shown, $input);
        return Command::SUCCESS;
    }
}
