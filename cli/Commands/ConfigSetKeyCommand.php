<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use P202Cli\Config;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ConfigSetKeyCommand extends BaseCommand
{
    protected static $defaultName = 'config:set-key';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Set the API key for authentication')
            ->addArgument(
                'key',
                InputArgument::OPTIONAL,
                'Your Prosper202 API key (omit to be prompted without echoing — keeps the key out of shell history)'
            );
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $key = $input->getArgument('key');
        if ($key === null || $key === '') {
            // Same treatment passwords get in user:create — an API key is a
            // bearer credential and should not have to pass through shell
            // history or ps output.
            $key = $this->promptHiddenSecret($input, $output, 'API key (hidden): ');
            if ($key === null) {
                $output->writeln('<error>API key is required</error>');
                return Command::FAILURE;
            }
        }

        $config = new Config();
        $config->set('api_key', $key);
        $config->save();
        $output->writeln('<info>API key saved.</info>');
        return Command::SUCCESS;
    }
}
