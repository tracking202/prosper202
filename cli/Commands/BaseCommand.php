<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use P202Cli\ApiClient;
use P202Cli\ApiException;
use P202Cli\Config;
use P202Cli\Formatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Base command that provides shared infrastructure:
 *
 * - Lazy-loaded ApiClient (constructed once, reused)
 * - --json output flag registered automatically
 * - Structured error handling that shows user-friendly messages
 * - Helper methods to reduce boilerplate in subclasses
 */
abstract class BaseCommand extends Command
{
    private ?ApiClient $client = null;

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function client(): ApiClient
    {
        if ($this->client === null) {
            $this->client = ApiClient::fromConfig(new Config());
        }
        return $this->client;
    }

    protected function isJson(InputInterface $input): bool
    {
        return (bool)$input->getOption('json');
    }

    protected function render(OutputInterface $output, array $data, InputInterface $input): void
    {
        Formatter::output($output, $data, $this->isJson($input));
    }

    /**
     * Override Symfony's execute to wrap in error handling.
     * Subclasses implement handle() instead of execute().
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            return $this->handle($input, $output);
        } catch (ApiException $e) {
            $output->writeln(sprintf('<error>API error (%d): %s</error>', $e->getCode(), $e->getMessage()));
            if ($e->responseData && !empty($e->responseData['field_errors'])) {
                foreach ($e->responseData['field_errors'] as $field => $msg) {
                    $output->writeln(sprintf('  <comment>%s</comment>: %s', $field, $msg));
                }
            }
            return Command::FAILURE;
        } catch (\RuntimeException $e) {
            $output->writeln(sprintf('<error>Error: %s</error>', $e->getMessage()));
            return Command::FAILURE;
        }
    }

    abstract protected function handle(InputInterface $input, OutputInterface $output): int;
}
