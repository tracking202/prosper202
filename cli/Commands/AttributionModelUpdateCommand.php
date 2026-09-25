<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Prosper202\Attribution\ModelType;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AttributionModelUpdateCommand extends BaseCommand
{
    protected static $defaultName = 'attribution:model:update';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Update an attribution model')
            ->addArgument('id', InputArgument::REQUIRED, 'Model ID')
            ->addOption('model_name', null, InputOption::VALUE_REQUIRED, 'Model name')
            ->addOption('model_type', null, InputOption::VALUE_REQUIRED, 'Type: ' . implode(', ', ModelType::values()))
            ->addOption('weighting_config', null, InputOption::VALUE_REQUIRED, 'Weighting config as a JSON object')
            ->addOption('lookback_days', null, InputOption::VALUE_REQUIRED, 'Lookback in days, 1-365')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'active or inactive')
            ->addOption('default', null, InputOption::VALUE_NONE, 'Make this the account default model');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $body = [];
        $name = $input->getOption('model_name');
        if ($name !== null) {
            $body['model_name'] = $name;
        }
        $type = $input->getOption('model_type');
        if ($type !== null) {
            $body['model_type'] = $type;
        }
        $error = self::collectDefinition($input, $body);
        if ($error !== null) {
            $output->writeln('<error>' . $error . '</error>');
            return Command::FAILURE;
        }
        if ($input->getOption('default')) {
            $body['is_default'] = true;
        }

        if (empty($body)) {
            $output->writeln('<error>Provide at least one field to update</error>');
            return Command::FAILURE;
        }

        $result = $this->client()->put('attribution/models/' . $input->getArgument('id'), $body);
        $this->render($output, $result, $input);
        return Command::SUCCESS;
    }

    /**
     * Read the definition options shared by create and update into the
     * request body, as the types the API takes: the config as a decoded JSON
     * object, the lookback as an integer. A value that is not what the flag
     * says is refused here with the reason rather than sent as a string the
     * server would refuse less helpfully.
     *
     * @param array<string, mixed> $body
     * @return string|null the error, or null when every option was valid
     */
    public static function collectDefinition(InputInterface $input, array &$body): ?string
    {
        $weightingConfig = $input->getOption('weighting_config');
        if ($weightingConfig !== null) {
            $decodedConfig = json_decode((string) $weightingConfig, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return sprintf('Invalid --weighting_config JSON: %s', json_last_error_msg());
            }
            if (!is_array($decodedConfig)) {
                return 'Invalid --weighting_config: pass a JSON object, e.g. {"half_life_hours":24}';
            }
            $body['weighting_config'] = (object) $decodedConfig;
        }
        $lookback = $input->getOption('lookback_days');
        if ($lookback !== null) {
            if (preg_match('/^[1-9][0-9]{0,2}$/D', (string) $lookback) !== 1) {
                return 'Invalid --lookback_days: a whole number of days from 1 to 365';
            }
            $body['lookback_days'] = (int) $lookback;
        }
        $status = $input->getOption('status');
        if ($status !== null) {
            if (!in_array($status, ['active', 'inactive'], true)) {
                return 'Invalid --status: active or inactive';
            }
            $body['status'] = $status;
        }

        return null;
    }
}
