<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class LtvCompaniesCommand extends BaseCommand
{
    protected static $defaultName = 'ltv:companies';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Company (ABM account) entities with live contact/revenue rollups')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Rows per page (max 500)')
            ->addOption('offset', 'o', InputOption::VALUE_REQUIRED, 'Pagination offset');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $params = [];
        foreach (['limit', 'offset'] as $option) {
            $value = $input->getOption($option);
            if ($value !== null && $value !== '') {
                $params[$option] = (string) $value;
            }
        }

        $result = $this->client()->get('ltv/companies', $params);
        $this->render($output, $result, $input);
        return Command::SUCCESS;
    }
}
