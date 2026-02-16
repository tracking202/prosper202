<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AttributionModelUpdateCommand extends BaseCommand
{
    protected static $defaultName = 'attribution:model:update';

    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Update an attribution model')
            ->addArgument('id', InputArgument::REQUIRED, 'Model ID')
            ->addOption('model_name', null, InputOption::VALUE_REQUIRED, 'Model name')
            ->addOption('model_type', null, InputOption::VALUE_REQUIRED, 'Model type')
            ->addOption('weighting_config', null, InputOption::VALUE_REQUIRED, 'Weighting config JSON')
            ->addOption('is_active', null, InputOption::VALUE_REQUIRED, '1=active, 0=inactive')
            ->addOption('is_default', null, InputOption::VALUE_REQUIRED, '1=default, 0=not');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $body = [];
        foreach (['model_name', 'model_type', 'is_active', 'is_default'] as $f) {
            $val = $input->getOption($f);
            if ($val !== null) {
                $body[$f] = $val;
            }
        }
        $weightingConfig = $input->getOption('weighting_config');
        if ($weightingConfig !== null) {
            $decodedConfig = json_decode((string)$weightingConfig, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $output->writeln(
                    sprintf('<error>Invalid --weighting_config JSON: %s</error>', json_last_error_msg())
                );
                return Command::FAILURE;
            }
            $body['weighting_config'] = $decodedConfig;
        }

        if (empty($body)) {
            $output->writeln('<error>Provide at least one field to update</error>');
            return Command::FAILURE;
        }

        $result = $this->client()->put('attribution/models/' . $input->getArgument('id'), $body);
        $this->render($output, $result, $input);
        return Command::SUCCESS;
    }
}
