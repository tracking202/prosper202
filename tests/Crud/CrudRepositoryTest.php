<?php

declare(strict_types=1);

namespace Tests\Crud;

use PHPUnit\Framework\TestCase;
use Prosper202\Crud\InMemoryCrudRepository;
use Prosper202\Crud\TableConfig;
use RuntimeException;

final class CrudRepositoryTest extends TestCase
{
    private function makeRepo(?TableConfig $config = null): InMemoryCrudRepository
    {
        return new InMemoryCrudRepository($config ?? TableConfig::affNetworks());
    }

    // --- TableConfig factory methods ---

    public function testTableConfigFactoryMethods(): void
    {
        $configs = [
            TableConfig::affNetworks(),
            TableConfig::ppcNetworks(),
            TableConfig::ppcAccounts(),
            TableConfig::campaigns(),
            TableConfig::landingPages(),
            TableConfig::textAds(),
        ];

        foreach ($configs as $config) {
            self::assertNotEmpty($config->table);
            self::assertNotEmpty($config->primaryKey);
            self::assertNotEmpty($config->fields);
            self::assertNotEmpty($config->selectColumns);
        }
    }

    // --- CRUD Operations ---

    public function testCreateReturnsSequentialIds(): void
    {
        $repo = $this->makeRepo();

        $id1 = $repo->create(1, ['aff_network_name' => 'Network A']);
        $id2 = $repo->create(1, ['aff_network_name' => 'Network B']);

        self::assertSame(1, $id1);
        self::assertSame(2, $id2);
    }

    public function testCreateStoresData(): void
    {
        $repo = $this->makeRepo();
        $id = $repo->create(1, ['aff_network_name' => 'Test Network', 'dni_network_id' => 42]);

        $row = $repo->findById($id, 1);

        self::assertNotNull($row);
        self::assertSame('Test Network', $row['aff_network_name']);
        self::assertSame(42, $row['dni_network_id']);
        self::assertSame(1, $row['user_id']);
        self::assertSame(0, $row['aff_network_deleted']);
    }

    public function testFindByIdReturnsNullForWrongUser(): void
    {
        $repo = $this->makeRepo();
        $id = $repo->create(1, ['aff_network_name' => 'Net']);

        self::assertNull($repo->findById($id, 2));
    }

    public function testFindByIdReturnsNullForDeleted(): void
    {
        $repo = $this->makeRepo();
        $id = $repo->create(1, ['aff_network_name' => 'Net']);
        $repo->softDelete($id, 1);

        self::assertNull($repo->findById($id, 1));
    }

    public function testListFiltersOnUserAndNotDeleted(): void
    {
        $repo = $this->makeRepo();
        $repo->create(1, ['aff_network_name' => 'A']);
        $repo->create(1, ['aff_network_name' => 'B']);
        $repo->create(2, ['aff_network_name' => 'C']);
        $id4 = $repo->create(1, ['aff_network_name' => 'D']);
        $repo->softDelete($id4, 1);

        $result = $repo->list(1, 0, 10);

        self::assertSame(2, $result['total']);
        self::assertCount(2, $result['rows']);
    }

    public function testListRespectsPagination(): void
    {
        $repo = $this->makeRepo();
        $repo->create(1, ['aff_network_name' => 'A']);
        $repo->create(1, ['aff_network_name' => 'B']);
        $repo->create(1, ['aff_network_name' => 'C']);

        $result = $repo->list(1, 1, 1);

        self::assertSame(3, $result['total']);
        self::assertCount(1, $result['rows']);
    }

    public function testUpdateModifiesFields(): void
    {
        $repo = $this->makeRepo();
        $id = $repo->create(1, ['aff_network_name' => 'Original']);

        $repo->update($id, 1, ['aff_network_name' => 'Updated']);

        $row = $repo->findById($id, 1);
        self::assertSame('Updated', $row['aff_network_name']);
    }

    public function testUpdateThrowsForNoFields(): void
    {
        $repo = $this->makeRepo();
        $id = $repo->create(1, ['aff_network_name' => 'Net']);

        $this->expectException(RuntimeException::class);
        $repo->update($id, 1, []);
    }

    // --- Hard Delete (no deletedColumn) ---

    public function testHardDeleteWhenNoDeletedColumn(): void
    {
        $config = new TableConfig(
            table: 'test_table',
            primaryKey: 'id',
            userIdColumn: 'user_id',
            deletedColumn: null,
            fields: ['name' => 's'],
            selectColumns: ['id', 'user_id', 'name'],
        );
        $repo = new InMemoryCrudRepository($config);
        $id = $repo->create(1, ['name' => 'Test']);

        $repo->softDelete($id, 1);

        self::assertNull($repo->findById($id, 1));
        self::assertEmpty($repo->rows);
    }

    // --- Different table configs ---

    public function testWorksWithCampaignsConfig(): void
    {
        $repo = new InMemoryCrudRepository(TableConfig::campaigns());
        $id = $repo->create(1, [
            'aff_campaign_name' => 'Campaign 1',
            'aff_campaign_url' => 'https://offer.example.com',
            'aff_campaign_payout' => 1.5,
        ]);

        $row = $repo->findById($id, 1);
        self::assertSame('Campaign 1', $row['aff_campaign_name']);
        self::assertSame('https://offer.example.com', $row['aff_campaign_url']);
        self::assertSame(1.5, $row['aff_campaign_payout']);
    }
}
