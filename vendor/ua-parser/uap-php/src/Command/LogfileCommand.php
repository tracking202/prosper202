<?php
declare(strict_types=1);
/**
 * ua-parser
 *
 * Copyright (c) 2011-2012 Dave Olsen, http://dmolsen.com
 *
 * Released under the MIT license
 */
namespace UAParser\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use UAParser\Exception\InvalidArgumentException;
use UAParser\Exception\ReaderException;
use UAParser\Parser;
use UAParser\Result\Client;
use UAParser\Util\Logfile\AbstractReader;

class LogfileCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('ua-parser:log')
            ->setDescription('Parses the supplied webserver log file.')
            ->addArgument(
                'output',
                InputArgument::REQUIRED,
                'Path to output log file'
            )
            ->addOption(
                'log-file',
                'f',
                InputOption::VALUE_REQUIRED,
                'Path to a webserver log file'
            )
            ->addOption(
                'log-dir',
                'd',
                InputOption::VALUE_REQUIRED,
                'Path to webserver log directory'
            )
            ->addOption(
                'include',
                'i',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
                'Include glob expressions for log files in the log directory',
                ['*.log', '*.log*.gz', '*.log*.bz2']
            )
            ->addOption(
                'exclude',
                'e',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
                'Exclude glob expressions for log files in the log directory',
                ['*error*']
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$input->getOption('log-file') && !$input->getOption('log-dir')) {
            throw InvalidArgumentException::oneOfCommandArguments('log-file', 'log-dir');
        }

        $parser = Parser::create();
        $undefinedClients = [];
        /** @var $file SplFileInfo */
        foreach ($this->getFiles($input) as $file) {

            $path = $this->getPath($file);
            $lines = file($path);

            if (empty($lines)) {
                $output->writeln(sprintf('Skipping empty file "%s"', $file->getPathname()));
                $output->writeln('');
                continue;
            }

            $firstLine = reset($lines);

            $reader = AbstractReader::factory($firstLine);
            if (!$reader) {
                $output->writeln(sprintf('Could not find reader for file "%s"', $file->getPathname()));
                $output->writeln('');
                continue;
            }

            $output->writeln('');
            $output->writeln(sprintf('Analyzing "%s"', $file->getPathname()));

            $count = 1;
            $totalCount = count($lines);
            foreach ($lines as $line) {

                try {
                    $userAgentString = $reader->read($line);
                } catch (ReaderException) {
                    $count = $this->outputProgress($output, 'E', $count, $totalCount);
                    continue;
                }

                $client = $parser->parse($userAgentString);

                $result = $this->getResult($client);
                if ($result !== '.') {
                    $undefinedClients[] = json_encode(
                        [$client->toString(), $userAgentString],
                        JSON_UNESCAPED_SLASHES
                    );
                }

                $count = $this->outputProgress($output, $result, $count, $totalCount);
            }
            $this->outputProgress($output, '', $count - 1, $totalCount, true);
            $output->writeln('');
        }

        $undefinedClients = $this->filter($undefinedClients);

        $fs = new Filesystem();
        $fs->dumpFile($input->getArgument('output'), implode(PHP_EOL, $undefinedClients));
    }

    private function outputProgress(OutputInterface $output, $result, $count, $totalCount, $end = false)
    {
        if (($count % 70) === 0 || $end) {
            $formatString = '%s  %' . strlen((string) $totalCount) . 'd / %-' . strlen((string) $totalCount) . 'd (%3d%%)';
            $result = $end ? str_repeat(' ', 70 - ($count % 70)) : $result;
            $output->writeln(sprintf($formatString, $result, $count, $totalCount, $count / $totalCount * 100));
        } else {
            $output->write($result);
        }

        return $count + 1;
    }

    private function getResult(Client $client)
    {
        if ($client->device->family === 'Spider') {
            return '.';
        } elseif ($client->ua->family === 'Other') {
            return 'U';
        } elseif ($client->os->family === 'Other') {
            return 'O';
        } elseif ($client->device->family === 'Generic Smartphone') {
            return 'S';
        } elseif ($client->device->family === 'Generic Feature Phone') {
            return 'F';
        }

        return '.';
    }

    private function getFiles(InputInterface $input)
    {
        $finder = Finder::create();

        if ($input->getOption('log-file')) {
            $file = $input->getOption('log-file');
            $finder->append(Finder::create()->in(dirname((string) $file))->name(basename((string) $file)));
        }

        if ($input->getOption('log-dir')) {
            $dirFinder = Finder::create()
                ->in($input->getOption('log-dir'));
            array_map($dirFinder->name(...), $input->getOption('include'));
            array_map($dirFinder->notName(...), $input->getOption('exclude'));

            $finder->append($dirFinder);
        }

        return $finder;
    }

    private function filter(array $lines)
    {
        return array_values(array_unique($lines));
    }

    private function getPath(SplFileInfo $file)
    {
        $path = match ($file->getExtension()) {
            'gz' => 'compress.zlib://' . $file->getPathname(),
            'bz2' => 'compress.bzip2://' . $file->getPathname(),
            default => $file->getPathname(),
        };

        return $path;
    }
}
