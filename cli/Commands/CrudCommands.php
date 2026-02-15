<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use P202Cli\ApiClient;
use P202Cli\ApiException;
use P202Cli\Config;
use P202Cli\Formatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Factory for generating standard CRUD commands (list, get, create, update, delete)
 * from an entity definition.
 *
 * Each generated command extends BaseCommand for shared error handling.
 */
class CrudCommands
{
    /** @return Command[] */
    public static function generate(
        string $entity,
        string $endpoint,
        array $fields,
        array $required = [],
        array $listParams = []
    ): array {
        return [
            self::buildListCommand($entity, $endpoint, $listParams),
            self::buildGetCommand($entity, $endpoint),
            self::buildCreateCommand($entity, $endpoint, $fields, $required),
            self::buildUpdateCommand($entity, $endpoint, $fields),
            self::buildDeleteCommand($entity, $endpoint),
        ];
    }

    private static function buildListCommand(string $entity, string $endpoint, array $listParams): Command
    {
        $cmd = new class($entity, $endpoint, $listParams) extends BaseCommand {
            private string $entity;
            private string $endpoint;
            private array $listParams;

            public function __construct(string $entity, string $endpoint, array $listParams)
            {
                $this->entity = $entity;
                $this->endpoint = $endpoint;
                $this->listParams = $listParams;
                parent::__construct("{$entity}:list");
            }

            protected function configure(): void
            {
                parent::configure();
                $this->setDescription("List all {$this->entity}s")
                    ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Max results', '50')
                    ->addOption('offset', 'o', InputOption::VALUE_REQUIRED, 'Offset', '0');

                foreach ($this->listParams as $param => $desc) {
                    $this->addOption($param, null, InputOption::VALUE_REQUIRED, $desc);
                }
            }

            protected function handle(InputInterface $input, OutputInterface $output): int
            {
                $params = ['limit' => $input->getOption('limit'), 'offset' => $input->getOption('offset')];
                foreach ($this->listParams as $param => $desc) {
                    $val = $input->getOption($param);
                    if ($val !== null) {
                        $params[$param] = $val;
                    }
                }
                $this->render($output, $this->client()->get($this->endpoint, $params), $input);
                return Command::SUCCESS;
            }
        };
        return $cmd;
    }

    private static function buildGetCommand(string $entity, string $endpoint): Command
    {
        return new class($entity, $endpoint) extends BaseCommand {
            private string $entity;
            private string $endpoint;

            public function __construct(string $entity, string $endpoint)
            {
                $this->entity = $entity;
                $this->endpoint = $endpoint;
                parent::__construct("{$entity}:get");
            }

            protected function configure(): void
            {
                parent::configure();
                $this->setDescription("Get a single {$this->entity} by ID")
                    ->addArgument('id', InputArgument::REQUIRED, 'The record ID');
            }

            protected function handle(InputInterface $input, OutputInterface $output): int
            {
                $this->render($output, $this->client()->get($this->endpoint . '/' . $input->getArgument('id')), $input);
                return Command::SUCCESS;
            }
        };
    }

    private static function buildCreateCommand(string $entity, string $endpoint, array $fields, array $required): Command
    {
        return new class($entity, $endpoint, $fields, $required) extends BaseCommand {
            private string $entity;
            private string $endpoint;
            private array $fields;
            private array $required;

            public function __construct(string $entity, string $endpoint, array $fields, array $required)
            {
                $this->entity = $entity;
                $this->endpoint = $endpoint;
                $this->fields = $fields;
                $this->required = $required;
                parent::__construct("{$entity}:create");
            }

            protected function configure(): void
            {
                parent::configure();
                $this->setDescription("Create a new {$this->entity}");
                foreach ($this->fields as $field => $desc) {
                    $req = in_array($field, $this->required) ? ' (required)' : '';
                    $this->addOption($field, null, InputOption::VALUE_REQUIRED, $desc . $req);
                }
            }

            protected function handle(InputInterface $input, OutputInterface $output): int
            {
                $body = [];
                foreach ($this->fields as $field => $desc) {
                    $val = $input->getOption($field);
                    if ($val !== null) {
                        $body[$field] = $val;
                    }
                }

                foreach ($this->required as $r) {
                    if (!array_key_exists($r, $body)) {
                        $output->writeln("<error>Missing required option: --$r</error>");
                        return Command::FAILURE;
                    }
                    if (is_string($body[$r]) && trim($body[$r]) === '') {
                        $output->writeln("<error>Missing required option: --$r</error>");
                        return Command::FAILURE;
                    }
                }

                $this->render($output, $this->client()->post($this->endpoint, $body), $input);
                return Command::SUCCESS;
            }
        };
    }

    private static function buildUpdateCommand(string $entity, string $endpoint, array $fields): Command
    {
        return new class($entity, $endpoint, $fields) extends BaseCommand {
            private string $entity;
            private string $endpoint;
            private array $fields;

            public function __construct(string $entity, string $endpoint, array $fields)
            {
                $this->entity = $entity;
                $this->endpoint = $endpoint;
                $this->fields = $fields;
                parent::__construct("{$entity}:update");
            }

            protected function configure(): void
            {
                parent::configure();
                $this->setDescription("Update an existing {$this->entity}")
                    ->addArgument('id', InputArgument::REQUIRED, 'The record ID');
                foreach ($this->fields as $field => $desc) {
                    $this->addOption($field, null, InputOption::VALUE_REQUIRED, $desc);
                }
            }

            protected function handle(InputInterface $input, OutputInterface $output): int
            {
                $body = [];
                foreach ($this->fields as $field => $desc) {
                    $val = $input->getOption($field);
                    if ($val !== null) {
                        $body[$field] = $val;
                    }
                }

                if (empty($body)) {
                    $output->writeln('<error>Provide at least one field to update.</error>');
                    return Command::FAILURE;
                }

                $this->render($output, $this->client()->put($this->endpoint . '/' . $input->getArgument('id'), $body), $input);
                return Command::SUCCESS;
            }
        };
    }

    private static function buildDeleteCommand(string $entity, string $endpoint): Command
    {
        return new class($entity, $endpoint) extends BaseCommand {
            private string $entity;
            private string $endpoint;

            public function __construct(string $entity, string $endpoint)
            {
                $this->entity = $entity;
                $this->endpoint = $endpoint;
                parent::__construct("{$entity}:delete");
            }

            protected function configure(): void
            {
                parent::configure();
                $this->setDescription("Delete a {$this->entity}")
                    ->addArgument('id', InputArgument::REQUIRED, 'The record ID')
                    ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation prompt');
            }

            protected function handle(InputInterface $input, OutputInterface $output): int
            {
                $id = $input->getArgument('id');

                if (!$input->getOption('force')) {
                    $helper = $this->getHelper('question');
                    $question = new ConfirmationQuestion(
                        "Are you sure you want to delete {$this->entity} #{$id}? [y/N] ",
                        false
                    );
                    if (!$helper->ask($input, $output, $question)) {
                        $output->writeln('<comment>Cancelled.</comment>');
                        return Command::SUCCESS;
                    }
                }

                $this->client()->delete($this->endpoint . '/' . $id);
                $output->writeln("<info>Deleted {$this->entity} #{$id}.</info>");
                return Command::SUCCESS;
            }
        };
    }
}
