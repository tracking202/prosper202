<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use P202Cli\OptionName;
use P202Cli\ServerLists;
use Prosper202\Attribution\AttributionReports;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AttributionExportCreateCommand extends BaseCommand
{
    protected static $defaultName = 'attribution:export:create';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Queue an attribution export (CSV of a breakdown), now or at --run-at, optionally to a signed https webhook')
            ->addOption('group-by', 'g', InputOption::VALUE_REQUIRED, 'Dimension: ' . implode(', ', AttributionReports::dimensions()), 'campaign')
            ->addOption('model-id', 'm', InputOption::VALUE_REQUIRED, 'Model (default: the account default)')
            ->addOption('compare-model-id', null, InputOption::VALUE_REQUIRED, 'A second model, side by side')
            ->addOption('period', 'p', InputOption::VALUE_REQUIRED, ServerLists::list(ServerLists::periods()) . ' (default: the last 30 days)')
            ->addOption('time-from', null, InputOption::VALUE_REQUIRED, 'Unix start time')
            ->addOption('time-to', null, InputOption::VALUE_REQUIRED, 'Unix end time')
            ->addOption('run-at', null, InputOption::VALUE_REQUIRED, 'When to run it, unix time (default: the next export run)')
            ->addOption('webhook-url', null, InputOption::VALUE_REQUIRED, 'Also POST the CSV here: https, public address, no redirects')
            ->addOption('webhook-secret', null, InputOption::VALUE_REQUIRED, 'HMAC secret for the signature, 16-255 characters (default: generated and returned once)');
    }

    /** A positive id from an argument, or null. */
    public static function exportId(mixed $value): ?int
    {
        return is_string($value) && preg_match('/^[1-9][0-9]{0,18}$/D', $value) === 1 ? (int) $value : null;
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $body = ['group_by' => (string) $input->getOption('group-by')];
        // Ids and times travel as JSON numbers: the API reads them as sent
        // and refuses a string, so a value that is not a number is refused
        // here, by option, before anything is sent.
        foreach (['model_id', 'compare_model_id', 'time_from', 'time_to', 'run_at'] as $opt) {
            $v = $input->getOption(OptionName::of($opt));
            if ($v === null || $v === '') {
                continue;
            }
            if (!is_string($v) || preg_match('/^[0-9]{1,10}$/D', $v) !== 1) {
                $output->writeln(sprintf('<error>%s must be a whole number%s</error>', OptionName::flag($opt), in_array($opt, ['model_id', 'compare_model_id'], true) ? ' (a model id; see attribution:model:list)' : ' (unix seconds)'));
                return Command::FAILURE;
            }
            $body[$opt] = (int) $v;
        }
        foreach (['period', 'webhook_url', 'webhook_secret'] as $opt) {
            $v = $input->getOption(OptionName::of($opt));
            if (is_string($v) && $v !== '') {
                $body[$opt] = $v;
            }
        }
        $result = $this->client()->post('attribution/exports', $body);
        $this->render($output, $result, $input);
        if (isset($result['data']['webhook_secret']) && !$this->isJson($input)) {
            $output->writeln('<comment>The webhook secret is shown this once; keep it with the receiver to check X-P202-Signature.</comment>');
        }
        return Command::SUCCESS;
    }
}
