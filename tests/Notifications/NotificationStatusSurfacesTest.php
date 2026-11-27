<?php

declare(strict_types=1);

namespace Tests\Notifications;

use Api\V3\Controllers\AppNotificationsController;
use PHPUnit\Framework\TestCase;
use Prosper202\Notifications\NotificationStatus;

/**
 * Every surface that lists a notification's statuses lists
 * NotificationStatus's, in its order: the API's filter, the OpenAPI
 * document, the Go CLI's flag and the Postbacks sent tab. Textual, as
 * ExportSurfacesAgreeTest.
 */
final class NotificationStatusSurfacesTest extends TestCase
{
    private static function file(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($source, $path);

        return $source;
    }

    public function testTheApisFilterListIsTheEnums(): void
    {
        self::assertSame(NotificationStatus::values(), AppNotificationsController::STATUSES);
    }

    public function testTheGoCliStatusesAreTheEnums(): void
    {
        self::assertSame(1, preg_match('/^var appNotificationStatuses = \[\]string\{([^}]*)\}/m', self::file('go-cli/cmd/app_links.go'), $m));
        preg_match_all('/"([^"]+)"/', $m[1], $values);
        self::assertSame(NotificationStatus::values(), $values[1]);
    }

    public function testTheOpenApiDocumentListsTheEnums(): void
    {
        $spec = self::file('docs/openapi.yaml');
        $expected = '[' . implode(', ', NotificationStatus::values()) . ']';

        self::assertSame(1, preg_match('/^  \/apps\/notifications:\n(.*?)^  \/\S/ms', $spec, $path));
        self::assertSame(1, preg_match('/\{name: status, in: query, schema: \{type: string, enum: (\[[^\]]*\])\}\}/', $path[1], $filter));
        self::assertSame($expected, $filter[1], 'the status filter');
        self::assertSame(1, preg_match('/^ +summary:\n +type: object\n +properties:\n((?: +\w+: \{type: integer\}\n)+)/m', $path[1], $summary));
        preg_match_all('/^ +(\w+): \{type: integer\}$/m', $summary[1], $counted);
        self::assertSame(NotificationStatus::values(), $counted[1], 'meta.summary');

        self::assertSame(1, preg_match('/^    AppNotification:\n(.*?)^    [A-Z]\w*:\n/ms', $spec, $schema));
        self::assertSame(1, preg_match('/^        status:\n          type: string\n          enum: (\[[^\]]*\])$/m', $schema[1], $field));
        self::assertSame($expected, $field[1], 'AppNotification.status');
    }

    public function testThePostbacksSentTabNamesEveryStatus(): void
    {
        self::assertSame(1, preg_match('/^\$statusSub = \[\n(.*?)^\];/ms', self::file('tracking202/analyze/templates/mobile_apps/_notifications.php'), $m));
        preg_match_all("/^    '(\w+)' => /m", $m[1], $keys);
        self::assertSame(NotificationStatus::values(), $keys[1]);
    }
}
