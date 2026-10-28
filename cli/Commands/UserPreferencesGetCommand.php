<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UserPreferencesGetCommand extends BaseCommand
{
    protected static $defaultName = 'user:prefs:get';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Get user preferences')
            ->setHelp('An integration secret is never shown, only whether one is stored: (stored) or (not stored), and '
                . '{"stored": true} under --json.')
            ->addArgument('user_id', InputArgument::REQUIRED, 'User ID');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $answer = $this->client()->get('users/' . $input->getArgument('user_id') . '/preferences');
        $this->render($output, $this->isJson($input) ? $answer : self::withStoredStates($answer), $input);
        return Command::SUCCESS;
    }

    /**
     * The answer with each secret's `{"stored": <bool>}`, which the server
     * serves in place of the secret, put as (stored) or (not stored).
     *
     * @param array<array-key, mixed> $answer
     * @return array<array-key, mixed>
     */
    public static function withStoredStates(array $answer): array
    {
        if (!isset($answer['data']) || !is_array($answer['data'])) {
            return $answer;
        }
        foreach ($answer['data'] as $key => $value) {
            if (is_array($value) && array_keys($value) === ['stored'] && is_bool($value['stored'])) {
                $answer['data'][$key] = $value['stored'] ? '(stored)' : '(not stored)';
            }
        }

        return $answer;
    }
}
