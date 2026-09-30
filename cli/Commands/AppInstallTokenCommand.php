<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * GET /apps/{id}/install-token?click_id=N: the install token the redirect
 * writes into a store link for one click, and the Google Play link that
 * carries it. A read. The Go CLI's `p202 app install token`.
 */
class AppInstallTokenCommand extends BaseCommand
{
    protected static $defaultName = 'app:install:token';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Show the install token and Google Play store link for one click')
            ->addArgument('registration_id', InputArgument::REQUIRED, 'The Android registration')
            ->addOption('click', null, InputOption::VALUE_REQUIRED, 'The click to sign (click:list shows them)');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $registrationId = (string) $input->getArgument('registration_id');
        $click = $input->getOption('click');
        if (preg_match('/^[1-9][0-9]*$/D', $registrationId) !== 1) {
            throw new \RuntimeException('The registration id must be a positive whole number, got "' . $registrationId . '".');
        }
        if (!is_string($click) || preg_match('/^[1-9][0-9]*$/D', $click) !== 1) {
            throw new \RuntimeException('--click is required: a positive click id (click:list shows them).');
        }

        $this->render($output, $this->client()->get('apps/' . $registrationId . '/install-token', ['click_id' => $click]), $input);
        return Command::SUCCESS;
    }
}
