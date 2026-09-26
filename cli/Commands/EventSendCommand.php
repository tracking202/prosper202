<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * event:send — POST /events (plan §2.2): report web events on a click; the
 * click's campaign goals decide what they are worth. The Go CLI's
 * `p202 event send`, with the same flags in this CLI's spelling.
 *
 * Values are read strictly and sent typed: a click id of "1e3" or a revenue
 * of "1e3" is refused here, never cast into another number (CLAUDE.md #18),
 * and a --props that is not a JSON object is refused, never dropped (#4).
 */
class EventSendCommand extends BaseCommand
{
    protected static $defaultName = 'event:send';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Report a web event (or a file of events) on a click, evaluated by its campaign goals')
            ->addOption('click_id', null, InputOption::VALUE_REQUIRED, 'Click ID (required)')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Event name, e.g. purchase')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Your id for the event: resending it is recorded once')
            ->addOption('occurred_at', null, InputOption::VALUE_REQUIRED, 'When it happened, unix seconds (default: now, by the server\'s clock)')
            ->addOption('revenue', null, InputOption::VALUE_REQUIRED, 'What it was worth')
            ->addOption('transaction_id', null, InputOption::VALUE_REQUIRED, 'The network\'s id for it')
            ->addOption('props', null, InputOption::VALUE_REQUIRED, 'Properties as a JSON object, e.g. {"plan":"pro"}')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'A JSON file of events (a list, or {"events": [...]}) instead of one from the options');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        try {
            $body = self::body($input->getOptions());
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
        $result = $this->client()->post('events', $body);
        $this->render($output, $result, $input);
        return Command::SUCCESS;
    }

    /**
     * The request body from the command's options.
     *
     * @param array<string, mixed> $o
     * @return array{click_id: int, events: list<mixed>}
     * @throws \InvalidArgumentException naming the option
     */
    public static function body(array $o): array
    {
        $click = (string) ($o['click_id'] ?? '');
        if (preg_match('/^[1-9]\d{0,18}$/D', $click) !== 1 || (string) (int) $click !== $click) {
            throw new \InvalidArgumentException('--click_id is required: a click id from click:list (a whole number greater than 0)');
        }
        $file = $o['file'] ?? null;
        if ($file !== null) {
            foreach (['name', 'id', 'occurred_at', 'revenue', 'transaction_id', 'props'] as $opt) {
                if (($o[$opt] ?? null) !== null) {
                    throw new \InvalidArgumentException('--file and --' . $opt . ' are exclusive: put every field in the file\'s events');
                }
            }
            $raw = @file_get_contents((string) $file);
            if ($raw === false) {
                throw new \InvalidArgumentException('--file ' . $file . ' cannot be read');
            }
            // json_decode() refuses anything after the first value (two
            // lists concatenated are a syntax error, never the first list
            // alone); say why, so a malformed file is not reported as a
            // file of the wrong shape.
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new \InvalidArgumentException('--file ' . $file . ' is not one JSON value (' . $e->getMessage() . '): put every event in one list, or one {"events": [...]}');
            }
            if (is_array($decoded) && !array_is_list($decoded)) {
                if (array_keys($decoded) !== ['events']) {
                    throw new \InvalidArgumentException('--file holds only events: a list, or {"events": [...]}; pass the click with --click_id');
                }
                $decoded = $decoded['events'];
            }
            if (!is_array($decoded) || !array_is_list($decoded) || $decoded === []) {
                throw new \InvalidArgumentException('--file must hold a JSON list of events, or {"events": [...]}');
            }

            return ['click_id' => (int) $click, 'events' => $decoded];
        }

        $name = (string) ($o['name'] ?? '');
        if (preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.:\-]{0,63}$/D', $name) !== 1) {
            throw new \InvalidArgumentException('--name is required: an event name of 1-64 letters, digits and _ . : -');
        }
        $id = (string) ($o['id'] ?? '');
        if (preg_match('/^[\x21-\x3F\x41-\x7E][\x21-\x7E]{0,127}$/D', $id) !== 1) {
            throw new \InvalidArgumentException('--id is required: your id for the event (1-128 printable characters, no spaces, not starting with @); resending it is recorded once');
        }
        $event = ['event_id' => $id, 'name' => $name];
        if (($o['occurred_at'] ?? null) !== null) {
            $at = (string) $o['occurred_at'];
            if (preg_match('/^(0|[1-9]\d{0,9})$/D', $at) !== 1 || (int) $at > 4294967295) {
                throw new \InvalidArgumentException('--occurred_at must be a unix time in seconds');
            }
            $event['occurred_at'] = (int) $at;
        }
        if (($o['revenue'] ?? null) !== null) {
            $rev = (string) $o['revenue'];
            if (preg_match('/^-?(?:0|[1-9]\d{0,11})(?:\.\d{1,5})?$/D', $rev) !== 1) {
                throw new \InvalidArgumentException('--revenue must be a decimal number with at most 5 decimal places');
            }
            $event['revenue'] = str_contains($rev, '.') ? (float) $rev : (int) $rev;
        }
        if (($o['transaction_id'] ?? null) !== null) {
            if (trim((string) $o['transaction_id']) === '') {
                throw new \InvalidArgumentException('--transaction_id is empty: omit it or give the network\'s id');
            }
            $event['transaction_id'] = (string) $o['transaction_id'];
        }
        if (($o['props'] ?? null) !== null) {
            $props = json_decode((string) $o['props'], true);
            if (!is_array($props) || ($props !== [] && array_is_list($props))) {
                throw new \InvalidArgumentException('--props must be a JSON object, e.g. {"plan":"pro","seats":3}');
            }
            $event['properties'] = $props === [] ? new \stdClass() : $props;
        }

        return ['click_id' => (int) $click, 'events' => [$event]];
    }
}
