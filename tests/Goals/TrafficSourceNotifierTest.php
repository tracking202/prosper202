<?php

declare(strict_types=1);

namespace Tests\Goals;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Goals\GoalSubject;
use Prosper202\Goals\TrafficSourceNotifier;
use Tests\Support\FakeMysqliConnection;

/** A goal's browser pixels: a Meta Purchase (type 6) asks about the notice's own click. */
final class TrafficSourceNotifierTest extends TestCase
{
    public function testAMetaPurchaseAsksWhetherTheClicksVisitorMayBeTracked(): void
    {
        $render = static function (?\Closure $mayTrack): string {
            $db = new FakeMysqliConnection();
            $db->whenQueryContainsReturnRows('pa.ppc_account_id', [['click_id' => 42, 'ppc_account_id' => 3, 'click_cpc' => '0']]);
            $db->whenQueryContainsReturnRows('FROM 202_goals', [['name' => 'Sale']]);
            $db->whenQueryContainsReturnRows('FROM 202_ppc_account_pixels WHERE ppc_account_id = ?', [
                ['pixel_id' => 7, 'pixel_code' => '123456789012345', 'pixel_type_id' => 6],
            ]);
            $notifier = new TrafficSourceNotifier(new Connection($db), true, $mayTrack);
            $notifier->notify(5, new GoalSubject(GoalSubject::CLICK, 42, 0, null, [], 42), [
                ['goal_id' => 1, 'n' => 1, 'outcome_id' => 9, 'kind' => 'reached', 'amount' => '5', 'transaction_id' => 'T'],
            ]);

            return $notifier->markup();
        };

        $asked = [];
        self::assertSame('', $render(static function (int $click) use (&$asked): bool {
            $asked[] = $click;

            return false;
        }));
        self::assertSame([42], $asked);
        self::assertSame('', $render(null), 'nothing answered: held back');
        self::assertStringContainsString('ev=Purchase', $render(static fn (int $click): bool => true));
    }

    /** A payable outcome's Purchase is named as the destinations name its conversion; one that wrote none keeps its key. */
    public function testAPayableOutcomeTakesItsConversionsEventId(): void
    {
        $render = static function (array $notice, array $replaced = []): string {
            $db = new FakeMysqliConnection();
            $db->whenQueryContainsReturnRows('pa.ppc_account_id', [['click_id' => 42, 'ppc_account_id' => 3, 'click_cpc' => '0']]);
            $db->whenQueryContainsReturnRows('FROM 202_goals', [['name' => 'Sale']]);
            $db->whenQueryContainsReturnRows('FROM 202_ppc_account_pixels WHERE ppc_account_id = ?', [
                ['pixel_id' => 7, 'pixel_code' => '123456789012345', 'pixel_type_id' => 6],
            ]);
            $db->whenQueryContainsReturnRows('superseded_by = ?', $replaced);
            $notifier = new TrafficSourceNotifier(new Connection($db), true, static fn (int $click): bool => true);
            $notifier->notify(5, new GoalSubject(GoalSubject::CLICK, 42, 0, null, [], 42), [
                $notice + ['goal_id' => 1, 'n' => 1, 'outcome_id' => 9, 'kind' => 'reached', 'amount' => '5', 'dedupe_key' => 'goal:1:9'],
            ]);

            return $notifier->markup();
        };

        self::assertStringContainsString('eid=P202-77', $render(['conversion_id' => 77, 'payable' => true, 'transaction_id' => null]));
        self::assertStringContainsString('eid=ORD-1', $render(['conversion_id' => 77, 'payable' => true, 'transaction_id' => 'ORD-1']));
        self::assertStringContainsString('eid=ORD-0', $render(['conversion_id' => 77, 'payable' => true, 'transaction_id' => 'ORD-1'], [['conv_id' => 70, 'transaction_id' => 'ORD-0']]), 'a replacing outcome goes by the first');
        self::assertStringContainsString('eid=goal%3A1%3A9', $render(['conversion_id' => null, 'payable' => false, 'transaction_id' => null]), 'no conversion: its own key');
    }

    /** The conversion endpoints and the web-event path hand the check down. */
    public function testTheBrowserPathsPassTheVisitorCheck(): void
    {
        $root = dirname(__DIR__, 2);
        // gpb.php reads the merged query and form body ($request); upx.php the query.
        foreach (['tracking202/static/upx.php' => '\$_GET', 'tracking202/static/gpb.php' => '\$request'] as $file => $source) {
            self::assertMatchesRegularExpression(
                '/p202FireTrafficSourcePixels\([^;]*?static fn \(\): bool => p202ConversionMayTrack\(\$db, [^;]*?' . $source . '\)\s*\)/s',
                (string) file_get_contents("$root/$file"),
                $file
            );
        }
        self::assertMatchesRegularExpression(
            '/new \\\\Prosper202\\\\Goals\\\\TrafficSourceNotifier\([^;]*?p202ConversionMayTrack\(\$db, \$click, \$get\)/s',
            (string) file_get_contents("$root/202-config/static-endpoint-helpers.php")
        );
    }
}
