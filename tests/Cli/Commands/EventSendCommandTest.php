<?php

declare(strict_types=1);

namespace Tests\Cli\Commands;

use P202Cli\Commands\EventSendCommand;
use Tests\TestCase;

/**
 * event:send builds POST /events' body strictly: typed values, and a bad
 * option refused by name rather than cast into another value.
 */
class EventSendCommandTest extends TestCase
{
    public function testCommandNameAndOptions(): void
    {
        $command = new EventSendCommand();
        $this->assertSame('event:send', $command->getName());
        foreach (['click_id', 'name', 'id', 'occurred_at', 'revenue', 'transaction_id', 'props', 'file', 'json'] as $option) {
            $this->assertTrue($command->getDefinition()->hasOption($option), "missing --$option");
        }
    }

    public function testOneEventIsSentTyped(): void
    {
        $body = EventSendCommand::body([
            'click_id' => '123', 'name' => 'purchase', 'id' => 'ORD-1', 'occurred_at' => '1700000000',
            'revenue' => '49.9', 'transaction_id' => 'T 1', 'props' => '{"plan":"pro","seats":3}',
        ]);
        $this->assertSame([
            'click_id' => 123,
            'events' => [[
                'event_id' => 'ORD-1', 'name' => 'purchase', 'occurred_at' => 1700000000, 'revenue' => 49.9,
                'transaction_id' => 'T 1', 'properties' => ['plan' => 'pro', 'seats' => 3],
            ]],
        ], $body);
        $this->assertSame(5, EventSendCommand::body(['click_id' => '1', 'name' => 's', 'id' => 'e', 'revenue' => '5'])['events'][0]['revenue']);
    }

    public function testAFileOfEvents(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'ev');
        file_put_contents($file, '{"events":[{"event_id":"a","name":"s"}]}');
        $this->assertSame(['click_id' => 9, 'events' => [['event_id' => 'a', 'name' => 's']]], EventSendCommand::body(['click_id' => '9', 'file' => $file]));
        file_put_contents($file, '{"click_id":1,"events":[{"event_id":"a","name":"s"}]}');
        $this->expectException(\InvalidArgumentException::class);
        try {
            EventSendCommand::body(['click_id' => '9', 'file' => $file]);
        } finally {
            unlink($file);
        }
    }

    /** @dataProvider bad */
    public function testBadOptionsAreRefusedByName(array $options, string $named): void
    {
        try {
            EventSendCommand::body($options + ['click_id' => '1', 'name' => 's', 'id' => 'e']);
            $this->fail('refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString($named, $e->getMessage());
        }
    }

    public static function bad(): iterable
    {
        yield 'click id with an exponent' => [['click_id' => '1e3'], '--click_id'];
        yield 'click id with a leading zero' => [['click_id' => '07'], '--click_id'];
        yield 'a name with a space' => [['name' => 'Sale Complete'], '--name'];
        yield 'a reserved id' => [['id' => '@install'], '--id'];
        yield 'no id' => [['id' => ''], '--id'];
        yield 'an exponent revenue' => [['revenue' => '1e3'], '--revenue'];
        yield 'a time with a sign' => [['occurred_at' => '+5'], '--occurred_at'];
        yield 'props that are a list' => [['props' => '[1]'], '--props'];
        yield 'props that are not JSON' => [['props' => '{bad'], '--props'];
        yield 'a file with a flag' => [['file' => '/dev/null', 'revenue' => '1'], 'exclusive'];
    }
}
