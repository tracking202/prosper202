<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use P202Cli\ApiClient;
use P202Cli\Config;
use P202Cli\Formatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Factory for generating standard CRUD commands (list, get, create, update, delete)
 * from an entity definition.
 */
class CrudCommands
{
    /**
     * @param string $entity     CLI name, e.g. "campaign"
     * @param string $endpoint   API path, e.g. "campaigns"
     * @param array  $fields     Field definitions: ['name' => 'description', ...]
     * @param array  $required   Required fields for create: ['name', 'url']
     * @param array  $listParams Extra list filter options: ['aff_network_id' => 'Filter by network ID']
     * @return Command[]
     */
    public static function generate(
        string $entity,
        string $endpoint,
        array $fields,
        array $required = [],
        array $listParams = []
    ): array {
        $commands = [];

        // --- list ---
        $commands[] = self::buildListCommand($entity, $endpoint, $listParams);
        // --- get ---
        $commands[] = self::buildGetCommand($entity, $endpoint);
        // --- create ---
        $commands[] = self::buildCreateCommand($entity, $endpoint, $fields, $required);
        // --- update ---
        $commands[] = self::buildUpdateCommand($entity, $endpoint, $fields);
        // --- delete ---
        $commands[] = self::buildDeleteCommand($entity, $endpoint);

        return $commands;
    }

    private static function buildListCommand(string $entity, string $endpoint, array $listParams): Command
    {
        $cmd = new class($entity, $endpoint, $listParams) extends Command {
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
                $this->setDescription("List all {$this->entity}s")
                    ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Max results', '50')
                    ->addOption('offset', 'o', InputOption::VALUE_REQUIRED, 'Offset', '0')
                    ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');

                foreach ($this->listParams as $param => $desc) {
                    $this->addOption($param, null, InputOption::VALUE_REQUIRED, $desc);
                }
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $client = ApiClient::fromConfig(new Config());
                $params = ['limit' => $input->getOption('limit'), 'offset' => $input->getOption('offset')];

                foreach ($this->listParams as $param => $desc) {
                    $val = $input->getOption($param);
                    if ($val !== null) {
                        $params[$param] = $val;
                    }
                }

                $result = $client->get($this->endpoint, $params);
                Formatter::output($output, $result, (bool)$input->getOption('json'));
                return Command::SUCCESS;
            }
        };
        return $cmd;
    }

    private static function buildGetCommand(string $entity, string $endpoint): Command
    {
        return new class($entity, $endpoint) extends Command {
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
                $this->setDescription("Get a single {$this->entity} by ID")
                    ->addArgument('id', InputArgument::REQUIRED, 'The record ID')
                    ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $client = ApiClient::fromConfig(new Config());
                $result = $client->get($this->endpoint . '/' . $input->getArgument('id'));
                Formatter::output($output, $result, (bool)$input->getOption('json'));
                return Command::SUCCESS;
            }
        };
    }

    private static function buildCreateCommand(string $entity, string $endpoint, array $fields, array $required): Command
    {
        return new class($entity, $endpoint, $fields, $required) extends Command {
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
                $this->setDescription("Create a new {$this->entity}")
                    ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');

                foreach ($this->fields as $field => $desc) {
                    $req = in_array($field, $this->required) ? InputOption::VALUE_REQUIRED : InputOption::VALUE_REQUIRED;
                    $this->addOption($field, null, $req, $desc . (in_array($field, $this->required) ? ' (required)' : ''));
                }
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $body = [];
                foreach ($this->fields as $field => $desc) {
                    $val = $input->getOption($field);
                    if ($val !== null) {
                        $body[$field] = $val;
                    }
                }

                foreach ($this->required as $r) {
                    if (!isset($body[$r])) {
                        $output->writeln("<error>Missing required option: --$r</error>");
                        return Command::FAILURE;
                    }
                }

                $client = ApiClient::fromConfig(new Config());
                $result = $client->post($this->endpoint, $body);
                Formatter::output($output, $result, (bool)$input->getOption('json'));
                return Command::SUCCESS;
            }
        };
    }

    private static function buildUpdateCommand(string $entity, string $endpoint, array $fields): Command
    {
        return new class($entity, $endpoint, $fields) extends Command {
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
                $this->setDescription("Update an existing {$this->entity}")
                    ->addArgument('id', InputArgument::REQUIRED, 'The record ID')
                    ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');

                foreach ($this->fields as $field => $desc) {
                    $this->addOption($field, null, InputOption::VALUE_REQUIRED, $desc);
                }
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
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

                $client = ApiClient::fromConfig(new Config());
                $result = $client->put($this->endpoint . '/' . $input->getArgument('id'), $body);
                Formatter::output($output, $result, (bool)$input->getOption('json'));
                return Command::SUCCESS;
            }
        };
    }

    private static function buildDeleteCommand(string $entity, string $endpoint): Command
    {
        return new class($entity, $endpoint) extends Command {
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
                $this->setDescription("Delete a {$this->entity}")
                    ->addArgument('id', InputArgument::REQUIRED, 'The record ID')
                    ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $client = ApiClient::fromConfig(new Config());
                $result = $client->delete($this->endpoint . '/' . $input->getArgument('id'));
                Formatter::output($output, $result, (bool)$input->getOption('json'));
                return Command::SUCCESS;
            }
        };
    }
}
