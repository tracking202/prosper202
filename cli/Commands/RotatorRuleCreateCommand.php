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
            ->addOption('rule-name', null, InputOption::VALUE_REQUIRED, 'Rule name (required)')
            ->addOption('splittest', null, InputOption::VALUE_REQUIRED, 'Enable split test (0|1)', '0')
            ->addOption(
                'criteria-json',
                null,
                InputOption::VALUE_REQUIRED,
                'Criteria as JSON array (required; the value ALL matches every visitor): '
                    . '[{"type":"country","statement":"is","value":"United States(US)"}]'
            )
            ->addOption(
                'redirects-json',
                null,
                InputOption::VALUE_REQUIRED,
                'Redirects as JSON array (required): [{"redirect_url":"...","weight":"50","name":"Variant A"}]'
            );
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $ruleName = $input->getOption('rule-name');
        if (!$ruleName) {
            $output->writeln('<error>--rule-name is required</error>');
            return Command::FAILURE;
        }

        $body = [
            'rule_name' => $ruleName,
            'splittest' => (int)$input->getOption('splittest'),
        ];

        // decodeJsonOption rejects malformed JSON AND scalar values — a scalar
        // like --criteria-json='"country is US"' would previously be sent to
        // the server, silently dropped, and the rule created with no criteria.
        // A rule with none matches every visitor; the server refuses one, and
        // one from before 1.9.79 stored it, so none is sent.
        $criteria = $this->decodeJsonOption($input, 'criteria-json');
        if ($criteria === null || $criteria === []) {
            $output->writeln('<error>--criteria-json is required: a rule needs at least one criterion. '
                . 'To send every visitor to this rule, say so: '
                . '--criteria-json=\'[{"type":"country","statement":"is","value":"ALL"}]\'</error>');
            return Command::FAILURE;
        }
        $body['criteria'] = $criteria;
        $redirects = $this->decodeJsonOption($input, 'redirects-json');
        if ($redirects === null || $redirects === []) {
            $output->writeln('<error>--redirects-json is required: a rule needs at least one destination</error>');
            return Command::FAILURE;
        }
        $body['redirects'] = $redirects;

        $result = $this->client()->post('rotators/' . $input->getArgument('rotator_id') . '/rules', $body);
        $this->render($output, $result, $input);
        return Command::SUCCESS;
    }
}
