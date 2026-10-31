<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Api\V3\Controllers\ReportsController;
use P202Cli\ServerLists;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ReportBreakdownCommand extends BaseCommand
{
    protected static $defaultName = 'report:breakdown';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Get performance breakdown by dimension')
            ->addOption('breakdown', 'b', InputOption::VALUE_REQUIRED, 'Dimension: ' . ServerLists::list(ServerLists::breakdownDimensions()), 'campaign')
            ->addOption('period', 'p', InputOption::VALUE_REQUIRED, 'Period: ' . ServerLists::list(ServerLists::periods()))
            ->addOption('time-from', null, InputOption::VALUE_REQUIRED, 'Start timestamp')
            ->addOption('time-to', null, InputOption::VALUE_REQUIRED, 'End timestamp')
            ->addOption('sort', 's', InputOption::VALUE_REQUIRED, 'Sort by: ' . ServerLists::list(ServerLists::BREAKDOWN_SORTS), 'total_clicks')
            ->addOption('sort-dir', null, InputOption::VALUE_REQUIRED, 'Sort direction: ASC or DESC', 'DESC')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Max results', '50')
            ->addOption('offset', 'o', InputOption::VALUE_REQUIRED, 'Offset', '0')
            ->addOption('aff-campaign-id', null, InputOption::VALUE_REQUIRED, 'Filter by campaign ID')
            ->addOption('ppc-account-id', null, InputOption::VALUE_REQUIRED, 'Filter by PPC account ID')
            ->addOption('aff-network-id', null, InputOption::VALUE_REQUIRED, 'Filter by affiliate network ID')
            ->addOption('ppc-network-id', null, InputOption::VALUE_REQUIRED, 'Filter by PPC network ID')
            ->addOption('landing-page-id', null, InputOption::VALUE_REQUIRED, 'Filter by landing page ID')
            ->addOption('country-id', null, InputOption::VALUE_REQUIRED, 'Filter by country ID');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $params = $this->collectOptions($input, ReportSummaryCommand::FILTER_PARAMS);
        $params['breakdown'] = $input->getOption('breakdown');
        $params['sort'] = $input->getOption('sort');
        $params['sort_dir'] = $input->getOption('sort-dir');
        $params['limit'] = $input->getOption('limit');
        $params['offset'] = $input->getOption('offset');

        $result = $this->client()->get('reports/breakdown', $params);
        $this->render($output, $result, $input);
        // --json carries `truncated` itself; a table says it on stderr.
        $note = $this->isJson($input) ? null : self::moreRowsNote($result);
        if ($note !== null) {
            ($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output)->writeln($note);
        }
        return Command::SUCCESS;
    }

    /**
     * The note under a page with rows after it (`truncated`), naming the
     * --offset that reads them; null for the last page.
     *
     * @param array<mixed> $result
     */
    public static function moreRowsNote(array $result): ?string
    {
        if (($result['truncated'] ?? null) !== true) {
            return null;
        }
        $rows = is_array($result['data'] ?? null) ? count($result['data']) : 0;
        $offset = is_int($result['offset'] ?? null) ? $result['offset'] : 0;
        $limit = is_int($result['limit'] ?? null) ? $result['limit'] : $rows;
        $note = sprintf(
            'Note: more rows remain after rows %d-%d; --offset %d reads the next page',
            $offset + 1,
            $offset + $rows,
            $offset + $rows
        );
        if ($limit < ReportsController::BREAKDOWN_MAX_LIMIT) {
            $note .= sprintf(' (--limit takes up to %d)', ReportsController::BREAKDOWN_MAX_LIMIT);
        }

        return $note . '.';
    }
}
