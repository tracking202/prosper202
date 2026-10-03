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
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

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
     * Shared confirm-or-force gate for destructive commands.
     *
     * Validates client configuration BEFORE prompting, so an unconfigured
     * user is never asked to confirm a deletion the tool cannot perform.
     * $action is the verb phrase, e.g. "delete campaign #3".
     */
    protected function confirmDestructive(InputInterface $input, OutputInterface $output, string $action): bool
    {
        $this->client();

        if ($input->hasOption('force') && $input->getOption('force')) {
            return true;
        }

        $helper = $this->getHelper('question');
        $question = new ConfirmationQuestion("Are you sure you want to {$action}? [y/N] ", false);
        if (!$helper->ask($input, $output, $question)) {
            $output->writeln('<comment>Cancelled.</comment>');
            return false;
        }
        return true;
    }

    /**
     * Collect the named options that were explicitly provided (non-null).
     */
    protected function collectOptions(InputInterface $input, array $names): array
    {
        $params = [];
        foreach ($names as $name) {
            if ($input->hasOption($name)) {
                $value = $input->getOption($name);
                if ($value !== null) {
                    $params[$name] = $value;
                }
            }
        }
        return $params;
    }

    /**
     * Decode a JSON option strictly. Returns null when the option was not
     * provided; malformed JSON or a scalar (which the server would silently
     * drop) is an explicit error, never silently discarded.
     */
    protected function decodeJsonOption(InputInterface $input, string $name): ?array
    {
        $raw = $input->getOption($name);
        if ($raw === null) {
            return null;
        }

        $decoded = json_decode((string)$raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Invalid JSON in --{$name}: " . json_last_error_msg());
        }
        if (!is_array($decoded)) {
            throw new \RuntimeException("--{$name} must be a JSON array or object");
        }
        return $decoded;
    }

    /**
     * Prompt for a secret without echoing it (keeps credentials out of shell
     * history and ps output). Returns null if nothing was entered.
     */
    protected function promptHiddenSecret(InputInterface $input, OutputInterface $output, string $prompt): ?string
    {
        $helper = $this->getHelper('question');
        $question = new Question($prompt);
        $question->setHidden(true);
        $question->setHiddenFallback(false);
        $value = $helper->ask($input, $output, $question);
        return is_string($value) && $value !== '' ? $value : null;
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
