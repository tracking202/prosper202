<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * GET /apps/{id}/store-link: the link builder — the store link a campaign
 * should send this app's clicks to (Android: with [[p202_install_token]] in
 * the Play referrer; iOS: the App Store link and the SKAN/AAK setup), and
 * with --campaign_id whether that campaign does. --apply makes it so with
 * PUT /campaigns/{id}. The Go CLI's `p202 app link`.
 */
class AppLinkCommand extends BaseCommand
{
    protected static $defaultName = 'app:link';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Show the store link for an app\'s campaigns, whether a campaign uses it, and apply it')
            ->addArgument('registration_id', InputArgument::REQUIRED, 'The app\'s registration id')
            ->addOption('campaign_id', null, InputOption::VALUE_REQUIRED, 'Say whether this campaign is ready')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Set the campaign\'s offer URL (and, for Android, its app link)');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $id = (string) $input->getArgument('registration_id');
        if (preg_match('/^[1-9][0-9]*$/D', $id) !== 1) {
            throw new \RuntimeException('The registration id must be a positive whole number, got "' . $id . '".');
        }
        $campaign = $input->getOption('campaign_id');
        $campaign = $campaign === null ? '' : (string) $campaign;
        if ($campaign !== '' && preg_match('/^[1-9][0-9]*$/D', $campaign) !== 1) {
            throw new \RuntimeException('--campaign_id must be a positive whole number, got "' . $campaign . '".');
        }
        $apply = (bool) $input->getOption('apply');
        if ($apply && $campaign === '') {
            throw new \RuntimeException('--apply needs --campaign_id: it changes that campaign\'s offer URL (and, for Android, links it to the app).');
        }

        $answer = $this->client()->get('apps/' . $id . '/store-link', $campaign === '' ? [] : ['campaign_id' => $campaign]);
        $change = (array) ($answer['data']['campaign']['apply'] ?? []);
        if (!$apply || $change === [] || ($answer['data']['campaign']['ready'] ?? false) === true) {
            $this->render($output, $answer, $input);

            return Command::SUCCESS;
        }
        $this->render($output, $this->client()->put('campaigns/' . $campaign, $change), $input);

        return Command::SUCCESS;
    }
}
