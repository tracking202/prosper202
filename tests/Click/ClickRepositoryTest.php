<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRecord;
use Prosper202\Click\InMemoryClickRepository;
use Prosper202\Click\NullClickRepository;

final class ClickRepositoryTest extends TestCase
{
    public function testInMemoryReturnsSequentialIds(): void
    {
        $repo = new InMemoryClickRepository();

        $click1 = new ClickRecord();
        $click1->userId = 1;

        $click2 = new ClickRecord();
        $click2->userId = 2;

        $id1 = $repo->recordClick($click1);
        $id2 = $repo->recordClick($click2);

        self::assertSame(1, $id1);
        self::assertSame(2, $id2);
    }

    public function testInMemoryStoresClickData(): void
    {
        $repo = new InMemoryClickRepository();

        $click = new ClickRecord();
        $click->userId = 42;
        $click->affCampaignId = 100;
        $click->keywordId = 5;
        $click->c1Id = 10;
        $click->gclid = 'abc123';
        $click->clickIdPublic = '1991';
        $click->clickRefererSiteUrlId = 77;

        $id = $repo->recordClick($click);

        self::assertArrayHasKey($id, $repo->clicks);
        $stored = $repo->clicks[$id];

        self::assertSame(42, $stored->userId);
        self::assertSame(100, $stored->affCampaignId);
        self::assertSame(5, $stored->keywordId);
        self::assertSame(10, $stored->c1Id);
        self::assertSame('abc123', $stored->gclid);
        self::assertSame('1991', $stored->clickIdPublic);
        self::assertSame(77, $stored->clickRefererSiteUrlId);
    }

    public function testInMemoryClonesClickRecord(): void
    {
        $repo = new InMemoryClickRepository();

        $click = new ClickRecord();
        $click->userId = 1;

        $id = $repo->recordClick($click);

        $click->userId = 999;

        self::assertSame(1, $repo->clicks[$id]->userId, 'Stored click should be independent of original');
    }

    public function testNullReturnsZero(): void
    {
        $repo = new NullClickRepository();

        $id = $repo->recordClick(new ClickRecord());

        self::assertSame(0, $id);
    }

    public function testClickRecordDefaults(): void
    {
        $click = new ClickRecord();

        self::assertSame(0, $click->userId);
        self::assertSame(0, $click->affCampaignId);
        self::assertSame('0', $click->clickCpc);
        self::assertSame('0', $click->clickPayout);
        self::assertSame(0, $click->clickFiltered);
        self::assertSame(0, $click->clickBot);
        self::assertSame(0, $click->clickAlp);
        self::assertSame('', $click->clickTime);
        self::assertSame('', $click->gclid);
        self::assertSame(1, $click->clickIn);
        self::assertSame(0, $click->clickOut);
    }
}
