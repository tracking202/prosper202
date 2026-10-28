<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AttributionJourneyCommand extends BaseCommand
{
    protected static $defaultName = 'attribution:journey';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription("One conversion's journey: its touches, the signals that linked them, and every model's credit")
            ->addArgument('conv_id', InputArgument::REQUIRED, 'Conversion ID');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $convId = (string) $input->getArgument('conv_id');
        if (preg_match('/^[1-9][0-9]*$/D', $convId) !== 1) {
            $output->writeln('<error>conv_id must be a positive whole number (see conversion:list)</error>');
            return Command::FAILURE;
        }
        $result = $this->client()->get('attribution/conversions/' . $convId . '/journey');
        $this->render($output, $result, $input);
        return Command::SUCCESS;
    }
}
