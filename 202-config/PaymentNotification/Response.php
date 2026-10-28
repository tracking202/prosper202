<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification;

/**
 * The one mapping from an outcome to the HTTP answer. Whatever recorded
 * nothing it was asked to record answers a status other than 2xx, so a
 * provider that retries failures retries it and its delivery log shows it;
 * whatever needs nothing done (a test, an ignored event, a retry already on
 * file) answers 200, so it is not sent again.
 */
final class Response
{
    private function __construct()
    {
    }

    public static function status(OutcomeKind $kind): int
    {
        return match ($kind) {
            OutcomeKind::RECORDED, OutcomeKind::REVERSED, OutcomeKind::DUPLICATE,
            OutcomeKind::TEST, OutcomeKind::IGNORED, OutcomeKind::NO_CLICK => 200,
            OutcomeKind::MALFORMED => 400,
            OutcomeKind::UNVERIFIED => 403,
            OutcomeKind::UNKNOWN_CLICK, OutcomeKind::NOTHING_TO_REVERSE => 404,
            OutcomeKind::METHOD_NOT_ALLOWED => 405,
            OutcomeKind::CONFLICT => 409,
            OutcomeKind::UNSUPPORTED_BODY => 415,
            OutcomeKind::CURRENCY_MISMATCH => 422,
            OutcomeKind::FAILED => 500,
            OutcomeKind::UPGRADE_PENDING => 503,
        };
    }

    /** @return array{error: bool, code: int, outcome: string, msg: string, conversion_id?: int} */
    public static function body(Outcome $outcome): array
    {
        $code = self::status($outcome->kind);
        $body = [
            'error' => $code >= 300,
            'code' => $code,
            'outcome' => $outcome->kind->value,
            'msg' => $outcome->message,
        ];
        if ($outcome->conversionId !== null) {
            $body['conversion_id'] = $outcome->conversionId;
        }

        return $body;
    }

    public static function send(Outcome $outcome): void
    {
        $code = self::status($outcome->kind);
        http_response_code($code);
        if ($outcome->kind === OutcomeKind::METHOD_NOT_ALLOWED) {
            header('Allow: POST');
        }
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        $json = json_encode(self::body($outcome), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        echo $json !== false ? $json : '{"error":true,"code":' . $code . '}';
    }
}
