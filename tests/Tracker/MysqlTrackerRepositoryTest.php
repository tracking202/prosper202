<?php

declare(strict_types=1);

namespace Tests\Tracker;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Tracker\MysqlTrackerRepository;
use Tests\Support\FakeMysqliConnection;

final class MysqlTrackerRepositoryTest extends TestCase
{
    public function testFindByPublicIdBindsStringAndExecutesOnce(): void
    {
        $write = new FakeMysqliConnection();
        $read = new FakeMysqliConnection();
        $conn = new Connection($write, $read);
        $repo = new MysqlTrackerRepository($conn);

        $repo->findByPublicId('public-123');

        self::assertCount(0, $write->statements, 'findByPublicId should use read connection.');
        self::assertCount(1, $read->statements);
        $stmt = $read->statements[0];

        self::assertSame('s', $stmt->boundTypes);
        self::assertSame(['public-123'], $stmt->boundValues);
        self::assertSame(1, $stmt->executeCount);
    }
}
