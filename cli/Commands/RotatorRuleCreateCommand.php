<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RotatorRuleCreateCommand extends BaseCommand
{
    protected static $defaultName = 'rotator:rule:create';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Add a rule to a rotator')
            ->addArgument('rotator_id', InputArgument::REQUIRED, 'Rotator ID')
            ->addOption('rule_name', null, InputOption::VALUE_REQUIRED, 'Rule name (required)')
            ->addOption('splittest', null, InputOption::VALUE_REQUIRED, 'Enable split test (0|1)', '0')
            ->addOption('criteria_json', null, InputOption::VALUE_REQUIRED, 'Criteria as JSON array: [{"type":"country","statement":"is","value":"US"}]')
            ->addOption('redirects_json', null, InputOption::VALUE_REQUIRED, 'Redirects as JSON array: [{"redirect_url":"...","weight":"50","name":"Variant A"}]');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $ruleName = $input->getOption('rule_name');
        if (!$ruleName) {
            $output->writeln('<error>--rule_name is required</error>');
            return Command::FAILURE;
        }

        $body = [
            'rule_name' => $ruleName,
            'splittest' => (int)$input->getOption('splittest'),
        ];

        if ($input->getOption('criteria_json')) {
            $body['criteria'] = json_decode((string) $input->getOption('criteria_json'), true);
            if ($body['criteria'] === null) {
                $output->writeln('<error>Invalid JSON in --criteria_json</error>');
                return Command::FAILURE;
            }
        }
        if ($input->getOption('redirects_json')) {
            $body['redirects'] = json_decode((string) $input->getOption('redirects_json'), true);
            if ($body['redirects'] === null) {
                $output->writeln('<error>Invalid JSON in --redirects_json</error>');
                return Command::FAILURE;
            }
        }

        $result = $this->client()->post('rotators/' . $input->getArgument('rotator_id') . '/rules', $body);
        $this->render($output, $result, $input);
        return Command::SUCCESS;
    }
}
