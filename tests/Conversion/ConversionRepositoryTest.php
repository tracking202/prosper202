<?php

declare(strict_types=1);

namespace Tests\Conversion;

use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\InMemoryConversionRepository;
use RuntimeException;

final class ConversionRepositoryTest extends TestCase
{
    private function makeRepo(): InMemoryConversionRepository
    {
        $repo = new InMemoryConversionRepository();
        $repo->clicks = [
            1 => ['click_id' => 1, 'user_id' => 1, 'aff_campaign_id' => 10, 'click_payout' => 2.5, 'click_time' => 1709280000, 'click_lead' => 0],
            2 => ['click_id' => 2, 'user_id' => 2, 'aff_campaign_id' => 20, 'click_payout' => 3.0, 'click_time' => 1709290000, 'click_lead' => 0],
        ];

        return $repo;
    }

    public function testCreateConversionReturnsId(): void
    {
        $repo = $this->makeRepo();

        $id = $repo->create(1, ['click_id' => 1]);

        self::assertSame(1, $id);
    }

    public function testCreateSetsClickLead(): void
    {
        $repo = $this->makeRepo();

        $repo->create(1, ['click_id' => 1]);

        self::assertSame(1, $repo->clicks[1]['click_lead']);
    }

    public function testCreateUsesClickPayout(): void
    {
        $repo = $this->makeRepo();

        $repo->create(1, ['click_id' => 1]);

        $conv = $repo->findById(1, 1);
        self::assertEqualsWithDelta(2.5, $conv['click_payout'], 0.01);
    }

    public function testCreateWithCustomPayout(): void
    {
        $repo = $this->makeRepo();

        $repo->create(1, ['click_id' => 1, 'payout' => 5.0]);

        $conv = $repo->findById(1, 1);
        self::assertEqualsWithDelta(5.0, $conv['click_payout'], 0.01);
        self::assertEqualsWithDelta(5.0, $repo->clicks[1]['click_payout'], 0.01);
    }

    public function testCreateThrowsForMissingClick(): void
    {
        $repo = $this->makeRepo();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Click not found');
        $repo->create(1, ['click_id' => 999]);
    }

    public function testCreateThrowsForWrongUserClick(): void
    {
        $repo = $this->makeRepo();

        $this->expectException(RuntimeException::class);
        $repo->create(1, ['click_id' => 2]); // click 2 belongs to user 2
    }

    public function testCreateThrowsForZeroClickId(): void
    {
        $repo = $this->makeRepo();

        $this->expectException(RuntimeException::class);
        $repo->create(1, ['click_id' => 0]);
    }

    public function testFindByIdReturnsConversion(): void
    {
        $repo = $this->makeRepo();
        $id = $repo->create(1, ['click_id' => 1, 'transaction_id' => 'TX123']);

        $conv = $repo->findById($id, 1);

        self::assertNotNull($conv);
        self::assertSame('TX123', $conv['transaction_id']);
        self::assertSame(10, $conv['campaign_id']);
    }

    public function testFindByIdReturnsNullForWrongUser(): void
    {
        $repo = $this->makeRepo();
        $id = $repo->create(1, ['click_id' => 1]);

        self::assertNull($repo->findById($id, 2));
    }

    public function testSoftDeleteHidesConversion(): void
    {
        $repo = $this->makeRepo();
        $id = $repo->create(1, ['click_id' => 1]);

        $repo->softDelete($id, 1);

        self::assertNull($repo->findById($id, 1));
    }

    public function testListFiltersAndPaginates(): void
    {
        $repo = $this->makeRepo();
        $repo->create(1, ['click_id' => 1, 'conv_time' => 1000]);
        $repo->clicks[3] = ['click_id' => 3, 'user_id' => 1, 'aff_campaign_id' => 10, 'click_payout' => 1.0, 'click_time' => 1709300000, 'click_lead' => 0];
        $repo->create(1, ['click_id' => 3, 'conv_time' => 2000]);

        $result = $repo->list(1, [], 0, 10);

        self::assertSame(2, $result['total']);
        self::assertCount(2, $result['rows']);
        // Ordered by conv_time DESC
        self::assertGreaterThanOrEqual($result['rows'][1]['conv_time'], $result['rows'][0]['conv_time']);
    }

    public function testListFiltersByCampaign(): void
    {
        $repo = $this->makeRepo();
        $repo->create(1, ['click_id' => 1]); // campaign 10

        $result = $repo->list(1, ['campaign_id' => 10], 0, 10);
        self::assertSame(1, $result['total']);

        $result = $repo->list(1, ['campaign_id' => 999], 0, 10);
        self::assertSame(0, $result['total']);
    }

    public function testSoftDeleteRequiresCorrectUser(): void
    {
        $repo = $this->makeRepo();
        $id = $repo->create(1, ['click_id' => 1]);

        $repo->softDelete($id, 2); // wrong user

        self::assertNotNull($repo->findById($id, 1), 'Should not be deleted by wrong user');
    }
}
