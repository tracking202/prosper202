<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ConversionGetCommand extends BaseCommand
{
    protected static $defaultName = 'conversion:get';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Get conversion details')
            ->addArgument('id', InputArgument::REQUIRED, 'Conversion ID');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->client()->get('conversions/' . $input->getArgument('id'));
        $this->render($output, $result, $input);
        return Command::SUCCESS;
    }
}
