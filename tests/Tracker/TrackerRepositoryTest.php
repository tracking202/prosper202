<?php

declare(strict_types=1);

namespace Tests\Tracker;

use PHPUnit\Framework\TestCase;
use Prosper202\Tracker\InMemoryTrackerRepository;

final class TrackerRepositoryTest extends TestCase
{
    public function testFindByPublicIdReturnsNullWhenEmpty(): void
    {
        $repo = new InMemoryTrackerRepository();

        self::assertNull($repo->findByPublicId('nonexistent'));
    }

    public function testFindByPublicIdReturnsAddedTracker(): void
    {
        $repo = new InMemoryTrackerRepository();

        $row = [
            'user_id' => 1,
            'tracker_name' => 'Test Tracker',
            'aff_campaign_id' => 10,
            'ppc_account_id' => 5,
        ];
        $repo->addTracker('abc123', $row);

        $result = $repo->findByPublicId('abc123');

        self::assertNotNull($result);
        self::assertSame(1, $result['user_id']);
        self::assertSame('Test Tracker', $result['tracker_name']);
        self::assertSame(10, $result['aff_campaign_id']);
    }

    public function testFindByPublicIdIsCaseSensitive(): void
    {
        $repo = new InMemoryTrackerRepository();
        $repo->addTracker('ABC123', ['user_id' => 1]);

        self::assertNull($repo->findByPublicId('abc123'));
        self::assertNotNull($repo->findByPublicId('ABC123'));
    }

    public function testAddTrackerOverwritesPreviousEntry(): void
    {
        $repo = new InMemoryTrackerRepository();
        $repo->addTracker('id1', ['user_id' => 1, 'tracker_name' => 'First']);
        $repo->addTracker('id1', ['user_id' => 2, 'tracker_name' => 'Second']);

        $result = $repo->findByPublicId('id1');

        self::assertSame(2, $result['user_id']);
        self::assertSame('Second', $result['tracker_name']);
    }

    public function testMultipleTrackersAreIndependent(): void
    {
        $repo = new InMemoryTrackerRepository();
        $repo->addTracker('t1', ['user_id' => 1]);
        $repo->addTracker('t2', ['user_id' => 2]);

        self::assertSame(1, $repo->findByPublicId('t1')['user_id']);
        self::assertSame(2, $repo->findByPublicId('t2')['user_id']);
    }
}
