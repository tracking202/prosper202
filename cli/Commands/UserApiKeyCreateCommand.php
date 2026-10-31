<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class UserApiKeyCreateCommand extends BaseCommand
{
    protected static $defaultName = 'user:apikey:create';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Generate a new API key for a user')
            ->addArgument('user_id', InputArgument::REQUIRED, 'User ID')
            ->addOption(
                'scope',
                null,
                InputOption::VALUE_REQUIRED,
                'Scope for the new key: *, read, write, stage, or comma-separated '
                . '<area>:read/<area>:write/<area>:stage tokens (read,stage is the '
                . 'propose-only agent shape). Omit for a full-access key.'
            );
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        // Without --scope this command could only mint full-access keys, and
        // an operator whose own key is scoped could not mint one at all: the
        // server requires a scoped key to state an explicit scope for
        // anything it creates, and there was no flag to supply it.
        $body = [];
        $scope = $input->getOption('scope');
        if ($scope !== null) {
            $scope = trim((string)$scope);
            // An explicitly empty --scope is malformed input, not "omitted":
            // the server reads an absent scope as full access, so dropping a
            // blank value would silently mint the broadest key there is.
            // Matches `p202 user apikey create` in the Go CLI.
            if ($scope === '') {
                $output->writeln('<error>--scope was given an empty value.</error>');
                $output->writeln(
                    'Name the scope (read, write, stage, or <area>:read/<area>:write/<area>:stage, '
                    . 'comma-separated), or omit --scope entirely to mint a full-access key on purpose.'
                );
                return Command::INVALID;
            }
            $body['scope'] = $scope;
        }

        $this->render(
            $output,
            $this->client()->post('users/' . $input->getArgument('user_id') . '/api-keys', $body),
            $input
        );
        return Command::SUCCESS;
    }
}
