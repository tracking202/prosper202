<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * GET /apps/{id}/installs/{install_uuid}: one Android install. The Go CLI's
 * `p202 app install get`.
 */
class AppInstallGetCommand extends BaseCommand
{
    protected static $defaultName = 'app:install:get';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Get one Android install: its referrer, timestamps, match state, reason and trust')
            ->addArgument('registration_id', InputArgument::REQUIRED, 'The Android registration')
            ->addArgument('install_uuid', InputArgument::REQUIRED, 'The install_uuid the SDK reported (lower-case UUID)');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $registrationId = (string) $input->getArgument('registration_id');
        $uuid = (string) $input->getArgument('install_uuid');
        if (preg_match('/^[1-9][0-9]*$/D', $registrationId) !== 1) {
            throw new \RuntimeException('The registration id must be a positive whole number, got "' . $registrationId . '".');
        }
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $uuid) !== 1) {
            throw new \RuntimeException('The install id must be the install_uuid the SDK reported (a lower-case UUID); app:install:list shows them.');
        }

        $this->render($output, $this->client()->get('apps/' . $registrationId . '/installs/' . $uuid), $input);
        return Command::SUCCESS;
    }
}
