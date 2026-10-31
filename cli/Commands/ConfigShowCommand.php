<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use P202Cli\Config;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ConfigShowCommand extends Command
{
    protected static $defaultName = 'config:show';

    protected function configure(): void
    {
        $this->setDescription('Show current configuration');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = new Config();

        // The settings this CLI uses, not the file: the file is shared with
        // the Go CLI and holds every profile's key. Printed whole, entry by
        // entry, it showed `profiles: Array` with a PHP warning; printed any
        // deeper it would show those keys unmasked. A key of 8 characters or
        // fewer was printed in full; it is starred now, as the Go CLI does.
        $url = $config->getUrl();
        $key = $config->getApiKey();
        if (strlen($key) > 8) {
            $key = substr($key, 0, 4) . '...' . substr($key, -4);
        } elseif ($key !== '') {
            $key = str_repeat('*', strlen($key));
        }

        $output->writeln("<info>Config file:</info> " . $config->configPath());
        $profile = $config->profileName();
        if ($profile !== null) {
            $output->writeln("<info>profile:</info> $profile (shared with the Go CLI)");
        }
        $output->writeln('<info>url:</info> ' . ($url === '' ? '(not set)' : $url));
        $output->writeln('<info>api_key:</info> ' . ($key === '' ? '(not set)' : $key));

        if ($url === '' && $key === '') {
            $output->writeln('<comment>No configuration set. Run config:set-url and config:set-key first.</comment>');
        }

        return Command::SUCCESS;
    }
}
