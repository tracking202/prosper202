<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\ConflictException;
use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\LostIdempotencyRaceException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;
use Api\V3\Support\AccountTimezone;
use Api\V3\Support\LtvBody;
use Api\V3\Support\PayloadKeys;
use Api\V3\Support\QueryInt;
use Api\V3\Support\RequestFlag;
use Api\V3\Support\StatementHelpers;
use Api\V3\Support\TimeBound;
use Prosper202\Database\Connection;
use Prosper202\Ltv\LtvQuery;
use Prosper202\Ltv\MysqlCompanyRepository;
use Prosper202\Ltv\MysqlCustomerCrmRepository;
use Prosper202\Ltv\MysqlCustomerFieldRepository;
use Prosper202\Ltv\CompanyConflictException;
use Prosper202\Ltv\LtvConflictException;
use Prosper202\Ltv\LtvInputException;
use Prosper202\Ltv\MysqlCustomerRepository;
use Prosper202\Ltv\MysqlIntegrationRepository;
use Prosper202\Ltv\RecordNotFoundException;
use Prosper202\Ltv\MysqlLtvRepository;
use Prosper202\Ltv\MysqlSubscriptionRepository;
use Prosper202\Ltv\MysqlWebhookRepository;
use Prosper202\Ltv\RevenueReplay;
use Prosper202\Ltv\SubscriptionNotFoundException;
use Prosper202\Validation\OutboundUrlException;

/**
 * /ltv endpoints: realized + predictive LTV reads, customer CRM management,
 * and the inbound integration surface for ESP / membership / billing systems
 * (revenue events, subscriptions, products, aliases).
 *
 * Scope enforcement (ltv:read / ltv:write) happens in the route middleware in
 * api/v3/index.php; this controller assumes an authorized caller.
 */
class LtvController
{
    use StatementHelpers;
    use AccountTimezone;

    private Connection $conn;
    private MysqlCustomerRepository $customers;
    private MysqlCustomerFieldRepository $fields;
    private MysqlCustomerCrmRepository $crm;
    private MysqlLtvRepository $ltv;
    private MysqlSubscriptionRepository $subscriptions;
    private MysqlWebhookRepository $webhooks;

    public function __construct(private readonly \mysqli $db, private readonly int $userId)
    {
        $this->conn = new Connection($db);
        $this->customers = new MysqlCustomerRepository($this->conn);
        $this->fields = new MysqlCustomerFieldRepository($this->conn);
        $this->crm = new MysqlCustomerCrmRepository($this->conn, $this->customers, $this->fields);
        $this->ltv = new MysqlLtvRepository($this->conn);
        $this->subscriptions = new MysqlSubscriptionRepository($this->conn, $this->customers);
        $this->webhooks = new MysqlWebhookRepository($this->conn);
    }

    // ── Reads ────────────────────────────────────────────────────────

    public function summary(array $params): array
    {
        return $this->wrap(fn (): array => ['data' => $this->ltv->summary($this->query($params))]);
    }

    public function customers(array $params): array
    {
        return $this->wrap(function () use ($params): array {
            $limit = QueryInt::param($params, 'limit', 50, 1, 500, 'rows per page');
            $offset = QueryInt::param($params, 'offset', 0, 0, PHP_INT_MAX, 'rows to skip');
            $chosen = self::choices($params, [
                'sort' => MysqlLtvRepository::CUSTOMER_SORTS,
                'dir' => MysqlLtvRepository::SORT_DIRECTIONS,
                'segment' => MysqlLtvRepository::customerSegments(),
            ]);
            $sort = $chosen['sort'] ?? 'total_revenue';
            $dir = $chosen['dir'] ?? 'DESC';
            $segment = $chosen['segment'];
            $result = $this->ltv->customers(
                $this->query($params),
                $sort,
                $dir,
                $limit,
                $offset,
                isset($params['q']) ? (string) $params['q'] : null,
                $segment
            );

            return [
                'data' => $result['rows'],
                'pagination' => ['total' => $result['total'], 'limit' => $limit, 'offset' => $offset],
            ];
        });
    }

    /**
     * LTV maturation by acquisition cohort (months since first seen).
     */
    public function cohorts(array $params): array
    {
        return $this->wrap(function () use ($params): array {
            $months = QueryInt::param($params, 'months', 6, 1, 24, 'acquisition months, newest first');
            // Months are the account's calendar months, as the reports' days are.
            $zone = $this->accountTimezone();

            return [
                'data' => $this->ltv->cohorts($this->userId, $months, null, $zone),
                'months' => $months,
                'timezone' => $zone,
            ];
        });
    }

    public function customerDetail(int $customerId): array
    {
        $customer = $this->wrap(fn (): ?array => $this->crm->get($this->userId, $customerId));
        if ($customer === null) {
            throw new NotFoundException('Customer not found');
        }

        return ['data' => $customer];
    }

    public function breakdown(array $params): array
    {
        return $this->wrap(function () use ($params): array {
            // `breakdown` is the older name of `by`; whichever is sent is
            // the one a refusal names.
            $byKey = array_key_exists('by', $params) ? 'by' : 'breakdown';
            $by = self::choices($params, [$byKey => MysqlLtvRepository::breakdowns()])[$byKey] ?? 'campaign';
            $limit = QueryInt::param($params, 'limit', 50, 1, 500, 'rows per page');
            $offset = QueryInt::param($params, 'offset', 0, 0, PHP_INT_MAX, 'rows to skip');

            return [
                'data' => $this->ltv->breakdown($this->query($params), $by, $limit, $offset),
                'breakdown' => $by,
            ];
        });
    }

    public function mrr(): array
    {
        return $this->wrap(fn (): array => ['data' => $this->ltv->mrr($this->userId)]);
    }

    public function predict(array $params): array
    {
        return $this->wrap(function () use ($params): array {
            $by = self::choices($params, ['by' => MysqlLtvRepository::breakdowns()])['by'];

            return ['data' => $this->ltv->predict($this->query($params), $by)];
        });
    }

    public function products(array $params): array
    {
        return $this->wrap(function () use ($params): array {
            $limit = QueryInt::param($params, 'limit', 50, 1, 500, 'rows per page');
            $offset = QueryInt::param($params, 'offset', 0, 0, PHP_INT_MAX, 'rows to skip');
            $stmt = $this->conn->prepareRead(
                'SELECT product_id, external_product_id, sku, name, price, currency, created_at, updated_at
                 FROM 202_products WHERE user_id = ?
                 ORDER BY product_id DESC LIMIT ? OFFSET ?'
            );
            $this->conn->bind($stmt, 'iii', [$this->userId, $limit, $offset]);

            return ['data' => $this->conn->fetchAll($stmt)];
        });
    }

    public function customerEngagement(int $customerId, array $params): array
    {
        $this->requireCustomer($customerId);

        return $this->wrap(function () use ($customerId, $params): array {
            $days = QueryInt::param($params, 'days', 90, 1, 365, 'days of engagement');
            $engagement = new \Prosper202\Ltv\MysqlEngagementRepository($this->conn);

            return [
                'data' => [
                    'browsing' => $engagement->customerEngagement($this->userId, $customerId, $days),
                    'events' => $engagement->customerEvents($this->userId, $customerId, $days),
                ],
                'window_days' => $days,
            ];
        });
    }

    /**
     * The keys that name the customer a write belongs to
     * (resolveCustomerFromPayload(), MysqlSubscriptionRepository::resolveCustomer()).
     */
    private const IDENTITY_KEYS = ['customer_id', 'customer_ref', 'customer_ref_type', 'customer_crm'];

    /**
     * A customer record's own fields (MysqlCustomerCrmRepository::upsert():
     * its CRM columns, email, aliases and custom fields).
     */
    private const CUSTOMER_RECORD_KEYS = [
        'first_name', 'last_name', 'phone', 'company', 'address_line1', 'address_line2', 'city', 'region',
        'postal_code', 'country', 'email', 'aliases', 'custom_fields',
    ];

    /**
     * Manually instrument an ABM engagement event from a server-side
     * integration ("demo_requested", "pricing_viewed", ...).
     */
    public function recordEngagementEvent(array $payload): array
    {
        PayloadKeys::refuseUnknown($payload, ['event', 'event_name', 'value', 'occurred_at', ...self::IDENTITY_KEYS], 'an engagement event');
        PayloadKeys::refuse(
            PayloadKeys::objectErrors($payload, 'customer_crm', LtvBody::crmKeys(), 'customer_crm', LtvBody::crm(...))
            + LtvBody::identity($payload)
        );
        $eventName = trim((string) ($payload['event'] ?? $payload['event_name'] ?? ''));
        if ($eventName === '') {
            throw new ValidationException('event is required', ['event' => 'The event name to record']);
        }

        return $this->wrap(function () use ($payload, $eventName): array {
            $now = time();

            // Validate BEFORE resolving: a rejected payload must not create
            // identity rows.
            $eventValue = null;
            if (array_key_exists('value', $payload) && $payload['value'] !== null) {
                if (!is_numeric($payload['value'])) {
                    throw new ValidationException('value must be numeric', ['value' => 'Seconds, percentage, or other metric']);
                }
                $eventValue = (float) $payload['value'];
            }

            // One transaction: a failed event insert rolls the (possibly
            // fresh) customer/alias back with it.
            [$eventId, $customerId] = $this->conn->transaction(function () use ($payload, $eventName, $eventValue, $now): array {
                $customerId = $this->resolveCustomerFromPayload($payload, $now, true);
                $engagement = new \Prosper202\Ltv\MysqlEngagementRepository($this->conn);
                $eventId = $engagement->recordEvent(
                    $this->userId,
                    $customerId,
                    $eventName,
                    'api',
                    null,
                    isset($payload['occurred_at']) ? QueryInt::param($payload, 'occurred_at', 0, 0, 4294967295, 'a unix time') : null,
                    $eventValue
                );

                return [$eventId, $customerId];
            });

            return [
                '_status' => 201,
                'data' => ['engagement_id' => $eventId, 'customer_id' => $customerId],
            ];
        });
    }

    public function customerNextOffer(int $customerId): array
    {
        $this->requireCustomer($customerId);

        return $this->wrap(function () use ($customerId): array {
            $recommendations = new \Prosper202\Ltv\MysqlRecommendationRepository($this->conn);

            return ['data' => $recommendations->nextOffer($this->userId, $customerId)];
        });
    }

    /**
     * Log that a next-offer recommendation was DELIVERED to the customer by
     * an API consumer (email send, external CRM, ...). The LP personalization
     * surface records itself at seal time; this endpoint is for senders the
     * tracker cannot see. Feeds the fatigue rule and future adaptive policies.
     */
    public function recordNextOfferImpression(int $customerId, array $payload): array
    {
        \Api\V3\Support\PayloadKeys::refuseUnknown($payload, ['campaign_id'], 'a next-offer impression');
        $this->requireCustomer($customerId);

        return $this->wrap(function () use ($customerId, $payload): array {
            $recommendations = new \Prosper202\Ltv\MysqlRecommendationRepository($this->conn);

            $campaignId = QueryInt::param($payload, 'campaign_id', 0, 1, PHP_INT_MAX, 'the offer shown; leave it out to show the recommended one');
            $basis = 'api';
            if ($campaignId <= 0) {
                $offer = $recommendations->nextOffer($this->userId, $customerId);
                if ($offer === null) {
                    throw new ValidationException(
                        'No current recommendation to record; pass campaign_id for the offer you delivered',
                        ['campaign_id' => 'Required when no recommendation is available']
                    );
                }
                $campaignId = (int) $offer['campaign_id'];
                $basis = (string) ($offer['why']['basis'] ?? 'api');
            }

            $recommendations->recordImpression($this->userId, $customerId, $campaignId, 'api', $basis);

            return [
                '_status' => 201,
                'data' => ['customer_id' => $customerId, 'campaign_id' => $campaignId, 'surface' => 'api'],
            ];
        });
    }

    public function abm(array $params): array
    {
        return $this->wrap(function () use ($params): array {
            $days = QueryInt::param($params, 'days', 90, 1, 365, 'days of engagement');
            $limit = QueryInt::param($params, 'limit', 50, 1, 500, 'rows per page');
            $offset = QueryInt::param($params, 'offset', 0, 0, PHP_INT_MAX, 'rows to skip');
            $engagement = new \Prosper202\Ltv\MysqlEngagementRepository($this->conn);

            return [
                'data' => $engagement->abmBreakdown($this->userId, $days, $limit, $offset),
                'window_days' => $days,
            ];
        });
    }

    public function abmCompany(array $params): array
    {
        $company = trim((string) ($params['name'] ?? ''));
        if ($company === '') {
            throw new ValidationException('name is required', ['name' => 'The company to drill into']);
        }

        return $this->wrap(function () use ($company, $params): array {
            $days = QueryInt::param($params, 'days', 90, 1, 365, 'days of engagement');
            $engagement = new \Prosper202\Ltv\MysqlEngagementRepository($this->conn);

            return [
                'data' => $engagement->abmCompanyDetail($this->userId, $company, $days),
                'company' => $company,
                'window_days' => $days,
            ];
        });
    }

    public function fieldsList(): array
    {
        return $this->wrap(fn (): array => [
            'data' => array_map(self::presentField(...), $this->fields->list($this->userId)),
        ]);
    }

    /**
     * A field definition as the API shows it: options decoded from their
     * stored JSON. Stored options that do not decode are flagged rather than
     * shown as "no options" (CLAUDE.md #4), as integrations flag their config.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function presentField(array $row): array
    {
        if (isset($row['options']) && is_string($row['options'])) {
            $decoded = json_decode($row['options'], true);
            if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                error_log(
                    'LTV field #' . (int) ($row['field_id'] ?? 0)
                    . ' has undecodable options JSON: ' . json_last_error_msg()
                );
                $row['options'] = null;
                $row['options_invalid'] = true;
            } else {
                $row['options'] = $decoded;
            }
        }

        return $row;
    }

    // ── Customer CRM writes ──────────────────────────────────────────

    public function upsertCustomer(array $payload): array
    {
        PayloadKeys::refuseUnknown($payload, ['customer_id', 'customer_ref', 'customer_ref_type', ...self::CUSTOMER_RECORD_KEYS], 'a customer');
        // The repository casts customer_id: "12abc" named customer 12.
        QueryInt::param($payload, 'customer_id', 0, 1, PHP_INT_MAX, 'an LTV customer id (see `p202 ltv customers`)');
        // The record's CRM fields are read as customer_crm's are (a country
        // of "United States" was a 500 in strict mode, "Un" without it), and
        // each alias as POST /ltv/customers/{id}/aliases reads one: a
        // misspelled `tpye` made the alias a custom one.
        PayloadKeys::refuse(LtvBody::crm($payload)
            + LtvBody::identity($payload)
            + PayloadKeys::listErrors($payload, 'aliases', LtvBody::ALIAS_KEYS, 'an alias', LtvBody::alias(...)));
        $customerId = $this->wrap(fn (): int => $this->crm->upsert($this->userId, $payload));
        $this->enqueueEvent('customer.updated', ['customer_id' => $customerId]);

        return $this->customerDetail($customerId);
    }

    public function patchCustomer(int $customerId, array $payload): array
    {
        // The path names the customer. customer_ref and its type were
        // removed here and customer_id overwritten, so a PATCH naming another
        // customer answered 200 having changed this one.
        $byPath = 'the path names the customer: send its record fields only (another reference is added with POST /ltv/customers/{id}/aliases)';
        PayloadKeys::refuseUnknown($payload, self::CUSTOMER_RECORD_KEYS, 'a customer update', [
            'customer_id' => $byPath, 'customer_ref' => $byPath, 'customer_ref_type' => $byPath,
        ]);
        PayloadKeys::refuse(LtvBody::crm($payload)
            + PayloadKeys::listErrors($payload, 'aliases', LtvBody::ALIAS_KEYS, 'an alias', LtvBody::alias(...)));
        $this->requireCustomer($customerId);
        $payload['customer_id'] = $customerId;
        unset($payload['customer_ref'], $payload['customer_ref_type']);

        $resolved = $this->wrap(fn (): int => $this->crm->upsert($this->userId, $payload));
        $this->enqueueEvent('customer.updated', ['customer_id' => $resolved]);

        return $this->customerDetail($resolved);
    }

    public function mergeCustomer(int $targetId, array $payload): array
    {
        \Api\V3\Support\PayloadKeys::refuseUnknown($payload, ['source_customer_id'], 'a customer merge');
        $sourceId = QueryInt::param($payload, 'source_customer_id', 0, 1, PHP_INT_MAX, 'the customer to merge INTO this one');
        if ($sourceId <= 0) {
            throw new ValidationException(
                'source_customer_id is required',
                ['source_customer_id' => 'The customer to merge INTO this one']
            );
        }

        $this->wrap(function () use ($sourceId, $targetId): void {
            $this->crm->merge($this->userId, $sourceId, $targetId);
        });
        $this->enqueueEvent('customer.updated', ['customer_id' => $targetId, 'merged_from' => $sourceId]);

        return $this->customerDetail($targetId);
    }

    public function deleteCustomer(int $customerId): void
    {
        $this->requireCustomer($customerId);
        $this->wrap(function () use ($customerId): void {
            $this->crm->erase($this->userId, $customerId);
        });
        $this->enqueueEvent('customer.updated', ['customer_id' => $customerId, 'erased' => true]);
    }

    public function addAlias(int $customerId, array $payload): array
    {
        PayloadKeys::refuseUnknown($payload, LtvBody::ALIAS_KEYS, 'a customer alias');
        // The body is one alias, read as each of a customer's `aliases` is:
        // a value of {"a": 1} was stored as the alias "Array".
        PayloadKeys::refuse(LtvBody::alias($payload));
        $this->requireCustomer($customerId);
        $value = trim((string) $payload['value']);

        $owner = $this->wrap(fn (): int => $this->conn->transaction(
            fn (): int => $this->customers->addAlias(
                $this->userId,
                $customerId,
                (string) ($payload['type'] ?? 'custom'),
                $value,
                time()
            )
        ));

        if ($owner !== $customerId) {
            throw new ValidationException(
                'Alias already belongs to customer ' . $owner
                . '; use POST /ltv/customers/' . $customerId . '/merge to combine records',
                ['value' => 'Already mapped to another customer']
            );
        }

        return $this->customerDetail($customerId);
    }

    // ── Inbound integration writes ───────────────────────────────────

    /**
     * Record a clickless revenue event (ESP order, membership charge,
     * Shopify order pushed server-side). source='api'; idempotent on the
     * caller-supplied idempotency_key: the same request again answers the
     * event it recorded, and a different request under that key is refused
     * (RevenueReplay says what is compared).
     */
    public function recordRevenue(array $payload): array
    {
        PayloadKeys::refuseUnknown($payload, [
            'event_type', 'amount', 'currency', 'occurred_at', 'items', 'idempotency_key', 'external_ref', 'transaction_id',
            ...self::IDENTITY_KEYS,
        ], 'a revenue event');
        // The line items and customer_crm, as strictly as the body: `qty`
        // stored one unit, and a price of "abc" stored 0 (CLAUDE.md #4).
        PayloadKeys::refuse(
            PayloadKeys::objectErrors($payload, 'customer_crm', LtvBody::crmKeys(), 'customer_crm', LtvBody::crm(...))
            + PayloadKeys::listErrors($payload, 'items', LtvBody::LINE_ITEM_KEYS, 'a line item', LtvBody::lineItem(...))
            + LtvBody::identity($payload)
            + LtvBody::revenueReferences($payload)
        );
        // Read before anything is written or replayed: a replay compares the
        // customer it names with the one its key recorded.
        $customerIdSent = QueryInt::param(
            $payload,
            'customer_id',
            0,
            1,
            PHP_INT_MAX,
            'an LTV customer id (see `p202 ltv customers`)'
        );

        return $this->wrap(function () use ($payload, $customerIdSent): array {
            $eventType = strtolower(trim((string) ($payload['event_type'] ?? 'purchase')));
            if (!in_array($eventType, ['purchase', 'one_time', 'refund', 'chargeback', 'adjustment'], true)) {
                throw new ValidationException(
                    'event_type must be purchase, one_time, refund, chargeback or adjustment (renewals go through /ltv/subscriptions)',
                    ['event_type' => 'Invalid value']
                );
            }
            if (!isset($payload['amount']) || !is_numeric($payload['amount'])) {
                throw new ValidationException('amount is required and must be numeric', ['amount' => 'Required']);
            }
            $amount = (float) $payload['amount'];
            if (in_array($eventType, ['refund', 'chargeback'], true) && $amount > 0) {
                $amount = -$amount; // refunds are stored negative
            }
            // A negative purchase/one_time would inflate order_count while
            // draining revenue — negative money must be a refund/chargeback/
            // adjustment.
            MysqlCustomerRepository::assertAmountSignMatchesType($eventType, $amount);

            $accountCurrency = $this->customers->accountCurrency($this->userId);
            $currency = strtoupper(trim((string) ($payload['currency'] ?? $accountCurrency)));
            if ($currency !== $accountCurrency) {
                throw new ValidationException(
                    "currency {$currency} does not match the account currency {$accountCurrency}; multi-currency is not supported",
                    ['currency' => 'Must match the account currency']
                );
            }

            $now = time();
            $occurredAt = QueryInt::param($payload, 'occurred_at', $now, 0, 4294967295, 'a unix time; leave it out for now');
            $items = $payload['items'] ?? [];

            $idempotencyKey = isset($payload['idempotency_key']) && trim((string) $payload['idempotency_key']) !== ''
                ? trim((string) $payload['idempotency_key'])
                : null;
            if ($idempotencyKey !== null) {
                // Reserved internal namespaces (void:/backfill:/sub:) must not
                // be squattable — a caller-claimed 'void:conv:N' would make a
                // later soft-delete compensation read as a replay and skip.
                MysqlCustomerRepository::assertExternalIdempotencyKey($idempotencyKey);
            }

            // What this request states, as the event would store it, for a
            // replay to be compared with (RevenueReplay): a key located the
            // event and nothing compared the request to it, so a key reused
            // for a different sale answered 200 `duplicate: true` and the
            // sale was dropped (CLAUDE.md #15).
            $stated = [
                'event_type' => $eventType,
                'amount' => $amount,
                'external_ref' => isset($payload['external_ref']) ? (string) $payload['external_ref'] : null,
                'transaction_id' => isset($payload['transaction_id']) ? (string) $payload['transaction_id'] : null,
                'customer' => $customerIdSent > 0
                    ? ['id' => $customerIdSent]
                    : [
                        'ref' => trim((string) ($payload['customer_ref'] ?? '')),
                        'type' => isset($payload['customer_ref_type']) ? (string) $payload['customer_ref_type'] : null,
                    ],
                'items' => $items,
            ];
            if (isset($payload['currency'])) {
                $stated['currency'] = $currency;
            }
            if (isset($payload['occurred_at'])) {
                $stated['occurred_at'] = $occurredAt;
            }
            if ($customerIdSent <= 0 && $stated['customer']['ref'] === '') {
                // Named no one: refused as a new event would be, replay or not.
                throw new ValidationException(
                    'customer_id or customer_ref is required',
                    ['customer_ref' => 'Identify the customer this revenue belongs to']
                );
            }

            try {
                $result = $this->conn->transaction(function () use (
                    $eventType,
                    $amount,
                    $currency,
                    $occurredAt,
                    $payload,
                    $items,
                    $now,
                    $idempotencyKey,
                    $stated
                ): array {
                    // Idempotent replay FIRST: a replay must not resolve or
                    // create a customer for a write that will never happen.
                    // It is a replay only when it is the same request.
                    if ($idempotencyKey !== null) {
                        $existing = $this->customers->findEventByIdempotencyKey($this->userId, $idempotencyKey);
                        if ($existing !== null) {
                            $this->refuseADifferentRequest($idempotencyKey, $existing, $stated);
                            return ['eventId' => $existing['event_id'], 'inserted' => false, 'customerId' => $existing['customer_id']];
                        }
                    }

                    // Identity creation happens INSIDE this transaction: if a
                    // later step rejects the payload (e.g. a malformed line
                    // item), the new customer/alias rolls back with it instead
                    // of surviving as an orphan zero-revenue record.
                    $customerId = $this->resolveCustomerFromPayload($payload, $now, true);
                    $event = $this->customers->insertRevenueEvent($this->userId, $customerId, [
                        'event_type' => $eventType,
                        'amount' => $amount,
                        'currency' => $currency,
                        'occurred_at' => $occurredAt,
                        'source' => 'api',
                        'external_ref' => isset($payload['external_ref']) ? (string) $payload['external_ref'] : null,
                        'transaction_id' => isset($payload['transaction_id']) ? (string) $payload['transaction_id'] : null,
                        'idempotency_key' => isset($payload['idempotency_key']) && trim((string) $payload['idempotency_key']) !== ''
                            ? trim((string) $payload['idempotency_key'])
                            : null,
                    ], $now);

                    if ($event['inserted']) {
                        $this->customers->applyEventToRollups($this->userId, $customerId, $eventType, $amount, $occurredAt, $now);
                        if ($items !== []) {
                            $this->customers->insertLineItems($this->userId, $event['eventId'], $items, $currency, $now, $amount);
                        }
                    } elseif ($idempotencyKey !== null) {
                        // Lost a concurrent race on the key: abort so the
                        // identity this request resolved rolls back with the
                        // transaction — committing it would leave an orphan
                        // zero-revenue customer/alias the caller never asked
                        // for.
                        throw new LostIdempotencyRaceException();
                    }

                    return $event + ['customerId' => $customerId];
                });
            } catch (LostIdempotencyRaceException) {
                // The rolled-back transaction's snapshot may predate the
                // winner's commit; this fresh autocommit read sees it.
                $existing = $this->customers->findEventByIdempotencyKey($this->userId, (string) $idempotencyKey);
                if ($existing === null) {
                    throw new ConflictException(
                        'A concurrent request with the same idempotency_key is in progress; retry to fetch its result',
                        ['idempotency_key' => 'Duplicate in flight']
                    );
                }
                // The winner was a different request: this one is refused,
                // not answered with the winner's event.
                $this->refuseADifferentRequest((string) $idempotencyKey, $existing, $stated);
                $result = ['eventId' => $existing['event_id'], 'inserted' => false, 'customerId' => $existing['customer_id']];
            }

            if ($result['inserted']) {
                $this->enqueueEvent('revenue.recorded', [
                    'event_id' => $result['eventId'],
                    'customer_id' => $result['customerId'],
                    'event_type' => $eventType,
                    'amount' => $amount,
                    'currency' => $currency,
                ]);
            }

            return [
                '_status' => $result['inserted'] ? 201 : 200,
                'data' => [
                    'event_id' => $result['eventId'],
                    'customer_id' => $result['customerId'],
                    'duplicate' => !$result['inserted'],
                ],
            ];
        });
    }

    /**
     * Refuse a request whose idempotency_key recorded a different one,
     * naming what differs. Nothing is written: the event stands as it was
     * recorded, and the caller either resends that body to replay it or
     * sends a new key for a different event.
     *
     * @param array{event_id: int, customer_id: int, event_type: string, amount: string, currency: string,
     *              occurred_at: int, external_ref: ?string, transaction_id: ?string} $existing
     * @param array<string, mixed> $stated
     */
    private function refuseADifferentRequest(string $key, array $existing, array $stated): void
    {
        $differences = (new RevenueReplay($this->customers))->differences($this->userId, $existing, $stated);
        if ($differences === []) {
            return;
        }
        $what = implode('; ', array_map(
            static fn (string $field, string $difference): string => $field . ' ' . $difference,
            array_keys($differences),
            $differences
        ));
        throw new ValidationException(
            'idempotency_key "' . $key . '" already recorded revenue event ' . $existing['event_id']
                . ' for a different request (' . $what . '). Nothing was recorded: resend the original request to'
                . ' replay that event, or send a new idempotency_key to record a different one.',
            ['idempotency_key' => 'Already used for revenue event ' . $existing['event_id'] . ' with a different '
                . implode(', ', array_keys($differences)) . '; send a new key for a different event']
        );
    }

    public function upsertSubscription(array $payload): array
    {
        // MysqlSubscriptionRepository::upsert() reads these, and the customer keys.
        PayloadKeys::refuseUnknown($payload, [
            'external_sub_id', 'amount', 'currency', 'plan_name', 'billing_interval', 'billing_interval_count', 'status',
            'grace_days', 'started_at', 'current_period_start', 'current_period_end', ...self::IDENTITY_KEYS,
        ], 'a subscription');
        // The repository casts customer_id ("12abc" named customer 12) and
        // took a customer_crm that was not an object as none; it casts the
        // amount, the times and the counts too (LtvBody::subscription()).
        QueryInt::param($payload, 'customer_id', 0, 1, PHP_INT_MAX, 'an LTV customer id (see `p202 ltv customers`)');
        PayloadKeys::refuse(
            LtvBody::subscription($payload)
            + PayloadKeys::objectErrors($payload, 'customer_crm', LtvBody::crmKeys(), 'customer_crm', LtvBody::crm(...))
            + LtvBody::identity($payload)
        );
        $result = $this->wrap(fn (): array => $this->subscriptions->upsert($this->userId, $payload));
        $this->enqueueEvent('subscription.changed', [
            'subscription_id' => $result['subscriptionId'],
            'customer_id' => $result['customerId'],
            'action' => 'upsert',
        ]);

        return ['data' => $result];
    }

    public function subscriptionEvent(string $externalSubId, array $payload): array
    {
        // MysqlSubscriptionRepository::recordEvent() reads these.
        \Api\V3\Support\PayloadKeys::refuseUnknown($payload, [
            'event_type', 'amount', 'currency', 'current_period_end', 'idempotency_key', 'occurred_at', 'transaction_id',
        ], 'a subscription event');
        $eventType = strtolower(trim((string) ($payload['event_type'] ?? '')));
        if (!in_array($eventType, ['renewal', 'cancel', 'refund'], true)) {
            throw new ValidationException(
                'event_type must be renewal, cancel or refund',
                ['event_type' => 'Invalid value']
            );
        }
        // The repository casts these: occurred_at "2026-10-07" dated the
        // renewal 2026 seconds into 1970, and an amount of "abc" renewed for 0.
        PayloadKeys::refuse(LtvBody::subscriptionEvent($payload));

        // wrap()'s mapping: a refusal names its field, a missing
        // subscription is a 404, anything else the server's failure. This
        // caught every RuntimeException as a 422 with no field.
        $result = $this->wrap(
            fn (): array => $this->subscriptions->recordEvent($this->userId, $externalSubId, $eventType, $payload)
        );

        // Idempotent replays (duplicate renewal/refund keys, repeat cancels)
        // change nothing — notifying downstream would duplicate side effects.
        if (!empty($result['changed'])) {
            $this->enqueueEvent('subscription.changed', [
                'subscription_id' => $result['subscriptionId'],
                'customer_id' => $result['customerId'],
                'action' => $eventType,
            ]);
        }

        return ['data' => $result];
    }

    public function upsertProduct(array $payload): array
    {
        // MysqlCustomerRepository::upsertProduct() reads these.
        PayloadKeys::refuseUnknown($payload, LtvBody::PRODUCT_KEYS, 'a product');
        // Read as a line item's product is: the repository cast the price, so
        // "abc" set it to 0, and cut a name or sku longer than its column.
        PayloadKeys::refuse(LtvBody::product($payload));

        return $this->wrap(function () use ($payload): array {
            $currency = $this->customers->accountCurrency($this->userId);
            $productId = $this->conn->transaction(
                fn (): int => $this->customers->upsertProduct($this->userId, $payload, $currency, time())
            );

            return ['data' => ['product_id' => $productId]];
        });
    }

    /** The product's catalog columns, as GET /ltv/products lists them. */
    private const PRODUCT_COLUMNS = 'product_id, external_product_id, sku, name, price, currency, created_at, updated_at';

    /** decimal(14,5): the largest price 202_products.price holds. */
    private const PRODUCT_PRICE_MAX = 999999999.99999;

    /**
     * Edit a catalog product's name, sku or list price (the LTV Products
     * tab's Save, tracking202/ajax/ltv_products.php). Only the fields sent
     * change. Its rules: a name is required and is not blank; sku and price
     * may be cleared (null or ""); a price is a number of 0 or more. Past
     * order line items keep the name they were sold under.
     *
     * Where the page truncates a long name or sku without a word, this
     * refuses it (CLAUDE.md #4), and every value is read before any cast.
     *
     * @param array<string, mixed> $payload {name?, sku?, price?}
     */
    public function updateProduct(int $productId, array $payload): array
    {
        \Api\V3\Support\PayloadKeys::refuseUnknown($payload, ['name', 'sku', 'price'], 'a product update', [
            'external_product_id' => 'is the product\'s key and does not change (accepted: name, sku, price)',
        ]);
        $errors = [];
        if ($payload === []) {
            throw new ValidationException('No fields to update', ['name' => 'send name, sku or price']);
        }
        $set = [];
        if (array_key_exists('name', $payload)) {
            $name = is_string($payload['name']) ? trim($payload['name']) : null;
            if ($name === null || $name === '') {
                $errors['name'] = 'must be a product name that is not blank';
            } elseif (mb_strlen($name) > 255) {
                $errors['name'] = 'must be at most 255 characters (got ' . mb_strlen($name) . ')';
            } else {
                $set['name'] = ['s', $name];
            }
        }
        if (array_key_exists('sku', $payload)) {
            $sku = $payload['sku'];
            if ($sku !== null && !is_string($sku)) {
                $errors['sku'] = 'must be a string, or null (or "") to clear it';
            } else {
                $sku = $sku === null ? '' : trim($sku);
                if (mb_strlen($sku) > 191) {
                    $errors['sku'] = 'must be at most 191 characters (got ' . mb_strlen($sku) . ')';
                } else {
                    $set['sku'] = ['s', $sku === '' ? null : $sku];
                }
            }
        }
        if (array_key_exists('price', $payload)) {
            $price = $payload['price'];
            if (is_string($price)) {
                $price = trim($price);
            }
            if ($price === null || $price === '') {
                $set['price'] = ['d', null];
            } elseif ((is_int($price) || is_float($price) || (is_string($price) && is_numeric($price)))
                && is_finite((float) $price) && (float) $price >= 0 && (float) $price <= self::PRODUCT_PRICE_MAX) {
                $set['price'] = ['d', (float) $price];
            } else {
                $errors['price'] = 'must be a number from 0 to ' . self::PRODUCT_PRICE_MAX . ', or null (or "") to clear it';
            }
        }
        if ($errors !== []) {
            throw new ValidationException('Invalid product update', $errors);
        }

        return $this->wrap(function () use ($productId, $set): array {
            $columns = [];
            $types = '';
            $values = [];
            foreach ($set as $column => [$type, $value]) {
                $columns[] = $column . ' = ?';
                $types .= $type;
                $values[] = $value;
            }
            $stmt = $this->conn->prepareWrite(
                'UPDATE 202_products SET ' . implode(', ', $columns) . ', updated_at = ? WHERE product_id = ? AND user_id = ?'
            );
            $this->conn->bind($stmt, $types . 'iii', [...$values, time(), $productId, $this->userId]);
            $this->conn->executeUpdate($stmt);

            // Zero affected rows can mean "unchanged", so the row is read
            // back either way: absent is the 404.
            $product = $this->findProduct($productId);
            if ($product === null) {
                throw new NotFoundException('Product not found');
            }

            return ['data' => $product];
        });
    }

    /**
     * Delete a catalog product, which the page allows only while no order
     * line item names it. The check and the delete are one statement, so a
     * line item recorded in between keeps the product.
     */
    public function deleteProduct(int $productId): void
    {
        $this->wrap(function () use ($productId): void {
            $stmt = $this->conn->prepareWrite(
                'DELETE p FROM 202_products p
                 WHERE p.product_id = ? AND p.user_id = ?
                   AND NOT EXISTS (SELECT 1 FROM 202_revenue_line_items li WHERE li.product_id = p.product_id AND li.user_id = p.user_id)'
            );
            $this->conn->bind($stmt, 'ii', [$productId, $this->userId]);
            if ($this->conn->executeUpdate($stmt) > 0) {
                return;
            }
            if ($this->findProduct($productId) === null) {
                throw new NotFoundException('Product not found');
            }
            // Present and not deleted: a line item names it (or named it
            // until a moment ago, when the count below finds none).
            $count = $this->productLineItems($productId);
            throw new ConflictException(
                $count > 0
                    ? self::productRefusal($count)
                    : 'The product was not deleted because an order line item named it; nothing names it now, so try again.',
                ['line_items' => $count]
            );
        });
    }

    /** @return array<string, mixed>|null */
    private function findProduct(int $productId): ?array
    {
        $stmt = $this->conn->prepareRead('SELECT ' . self::PRODUCT_COLUMNS . ' FROM 202_products WHERE product_id = ? AND user_id = ? LIMIT 1');
        $this->conn->bind($stmt, 'ii', [$productId, $this->userId]);

        return $this->conn->fetchOne($stmt);
    }

    private function productLineItems(int $productId): int
    {
        $stmt = $this->conn->prepareRead('SELECT COUNT(*) AS c FROM 202_revenue_line_items WHERE product_id = ? AND user_id = ?');
        $this->conn->bind($stmt, 'ii', [$productId, $this->userId]);

        return (int) ($this->conn->fetchOne($stmt)['c'] ?? 0);
    }

    /** The page's refusal while line items name the product; null when it may go. */
    private function productDeleteRefusal(int $productId): ?string
    {
        $count = $this->productLineItems($productId);

        return $count > 0 ? self::productRefusal($count) : null;
    }

    private static function productRefusal(int $lineItems): string
    {
        return 'This product appears on ' . $lineItems . ' order line item(s) and cannot be deleted.';
    }

    // ── Custom field definitions ─────────────────────────────────────

    public function createField(array $payload): array
    {
        // MysqlCustomerFieldRepository::create() reads these.
        PayloadKeys::refuseUnknown($payload, ['field_key', 'label', 'field_type', 'options', 'is_required', 'sort_order'], 'a custom field');
        // Options are a select field's choices. The repository dropped them
        // from any other field without a word; PATCH refuses them there.
        $type = $payload['field_type'] ?? 'text';
        $select = is_string($type) && strtolower(trim($type)) === 'select';
        $notSelect = 'apply only to a select field (field_type: select); this one is '
            . (is_string($type) ? $type : 'not a type');
        PayloadKeys::refuse(
            LtvBody::field($payload)
            + self::optionErrors($payload)
            + (isset($payload['options']) && !$select ? ['options' => $notSelect] : [])
        );
        $payload = self::fieldValues($payload);
        $fieldId = $this->wrap(fn (): int => $this->fields->create($this->userId, $payload));

        return ['data' => ['field_id' => $fieldId]];
    }

    public function updateField(int $fieldId, array $payload): array
    {
        // MysqlCustomerFieldRepository::update() writes these; a field's key
        // and type are fixed once it holds values.
        $fixed = 'is fixed once the field is created (accepted: label, options, is_required, sort_order)';
        PayloadKeys::refuseUnknown($payload, ['label', 'options', 'is_required', 'sort_order'], 'a custom field update', [
            'field_key' => $fixed, 'field_type' => $fixed,
        ]);
        PayloadKeys::refuse(LtvBody::field($payload) + self::optionErrors($payload));
        $payload = self::fieldValues($payload);
        $this->wrap(function () use ($fieldId, $payload): void {
            try {
                $this->fields->update($this->userId, $fieldId, $payload);
            } catch (RecordNotFoundException) {
                throw new NotFoundException('Field not found');
            }
        });

        return $this->fieldsList();
    }

    /**
     * A field definition's body as the repository reads it, once LtvBody::
     * field() has passed it: is_required as the flag it was read as (the
     * repository took any non-empty value, "false" included, as required),
     * and a label or is_required that is null or '', or a sort_order that is
     * null, left out rather than written as an empty label, false or 0
     * (field() refuses a sort_order of ''). A PATCH of nothing but those is
     * then the 422 that names what it takes.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function fieldValues(array $payload): array
    {
        foreach (['label' => [null, ''], 'is_required' => [null, ''], 'sort_order' => [null]] as $key => $none) {
            if (array_key_exists($key, $payload) && in_array($payload[$key], $none, true)) {
                unset($payload[$key]);
            }
        }
        if (array_key_exists('is_required', $payload)) {
            $payload['is_required'] = RequestFlag::read($payload['is_required']);
        }

        return $payload;
    }

    /**
     * A select field's options: a list of choices, each a string (or a
     * whole number, read as its digits) that is not blank. The repository
     * cast each with strval(), so {"gold": "Gold", "silver": ["Silver"]}
     * was stored as the choices "Gold" and "Array".
     *
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    private static function optionErrors(array $payload): array
    {
        return PayloadKeys::valueListErrors(
            $payload,
            'options',
            'choices, e.g. ["free", "pro"]',
            static fn (mixed $option): ?string => (is_string($option) || is_int($option))
                && trim((string) $option) !== '' ? null : 'must be a choice: a string that is not blank'
        );
    }

    public function deleteField(int $fieldId): void
    {
        $this->wrap(function () use ($fieldId): void {
            try {
                $this->fields->delete($this->userId, $fieldId);
            } catch (RecordNotFoundException) {
                throw new NotFoundException('Field not found');
            }
        });
    }

    // ── Webhooks & integrations ──────────────────────────────────────

    public function listWebhooks(): array
    {
        return $this->wrap(fn (): array => ['data' => $this->webhooks->list($this->userId)]);
    }

    public function createWebhook(array $payload): array
    {
        PayloadKeys::refuseUnknown($payload, ['url', 'webhook_url', 'events'], 'a webhook');
        // A list of event names. Anything else was read as no list, which
        // subscribes to every event: `"events": "revenue.recorded"` made a
        // hook for all five. And each name one this install sends: a name
        // checked only for its form (`revenue.recoded`) made a hook that
        // receives nothing, answered 201. '*' is every event, later ones
        // included (MysqlWebhookRepository::KNOWN_EVENTS).
        PayloadKeys::refuse(PayloadKeys::valueListErrors(
            $payload,
            'events',
            'event names, e.g. ["revenue.recorded"] (omit it, or send [], for every event)',
            static fn (mixed $event): ?string => match (true) {
                !is_string($event) => 'must be an event name (a string)',
                $event === '*' => null,
                default => MysqlWebhookRepository::unknownEventReason($event),
            }
        ));
        $url = trim((string) ($payload['url'] ?? $payload['webhook_url'] ?? ''));
        if ($url === '') {
            throw new ValidationException('url is required', ['url' => 'The https endpoint to deliver events to']);
        }
        $events = $payload['events'] ?? [];

        $result = $this->wrap(function () use ($url, $events, $payload): array {
            try {
                return $this->webhooks->create($this->userId, $url, $events);
            } catch (OutboundUrlException $e) {
                // The URL guard's refusal is the caller's to fix, named by
                // the field it was sent in; wrap() takes an exception it does
                // not know for the server's.
                $field = array_key_exists('url', $payload) ? 'url' : 'webhook_url';
                throw new ValidationException($e->getMessage(), [$field => $e->getMessage()]);
            }
        });

        // The secret is returned exactly once, at creation.
        return ['data' => ['webhook_id' => $result['webhookId'], 'secret' => $result['secret']]];
    }

    /**
     * One endpoint's delivery log, newest first (the LTV Settings tab's
     * Log, which shows the last 25): each event's status, attempts, next
     * retry, last HTTP status and the last attempt's response or error.
     * Never the endpoint's secret, and not the payload.
     *
     * @param array<string, mixed> $params limit (1-100, default 25), status (pending, delivered, failed)
     */
    public function webhookDeliveries(int $webhookId, array $params): array
    {
        $errors = [];
        foreach (array_keys($params) as $key) {
            if (!in_array((string) $key, ['limit', 'status'], true)) {
                $errors[(string) $key] = 'is not accepted here (accepted: limit, status)';
            }
        }
        $limit = 25;
        if (array_key_exists('limit', $params)) {
            $raw = $params['limit'];
            if (!is_string($raw) || preg_match('/^[1-9][0-9]{0,2}$/D', $raw) !== 1 || (int) $raw > 100) {
                $errors['limit'] = 'must be a whole number from 1 to 100';
            } else {
                $limit = (int) $raw;
            }
        }
        $status = null;
        if (array_key_exists('status', $params)) {
            if (!is_string($params['status']) || !in_array($params['status'], MysqlWebhookRepository::DELIVERY_STATUSES, true)) {
                $errors['status'] = 'must be one of: ' . implode(', ', MysqlWebhookRepository::DELIVERY_STATUSES);
            } else {
                $status = $params['status'];
            }
        }
        if ($errors !== []) {
            throw new ValidationException('Invalid parameter', $errors);
        }

        return $this->wrap(function () use ($webhookId, $limit, $status): array {
            // An unknown id is a 404, not an empty log: the two must not read
            // the same (CLAUDE.md #1).
            $webhook = $this->webhooks->get($this->userId, $webhookId);
            if ($webhook === null) {
                throw new NotFoundException('Webhook not found');
            }
            $rows = array_map(static fn (array $row): array => [
                'delivery_id' => (int) $row['delivery_id'],
                'event_name' => (string) $row['event_name'],
                'status' => (string) $row['status'],
                'attempts' => (int) $row['attempts'],
                'next_attempt_at' => (string) $row['status'] === 'pending' ? (int) $row['next_attempt_at'] : null,
                'last_status_code' => $row['last_status_code'] === null ? null : (int) $row['last_status_code'],
                'last_response_body' => $row['last_response_body'] === null ? null : (string) $row['last_response_body'],
                'created_at' => (int) $row['created_at'],
                'updated_at' => (int) $row['updated_at'],
            ], $this->webhooks->deliveries($this->userId, $webhookId, $limit, $status));

            return [
                'data' => $rows,
                'meta' => [
                    'webhook_id' => $webhookId,
                    'webhook_url' => (string) $webhook['webhook_url'],
                    'webhook_status' => (string) $webhook['status'],
                    'limit' => $limit,
                    'status' => $status,
                    'max_attempts' => MysqlWebhookRepository::MAX_ATTEMPTS,
                ],
            ];
        });
    }

    public function deleteWebhook(int $webhookId): void
    {
        $this->wrap(function () use ($webhookId): void {
            try {
                $this->webhooks->delete($this->userId, $webhookId);
            } catch (RecordNotFoundException) {
                throw new NotFoundException('Webhook not found');
            }
        });
    }

    // ── Companies (ABM accounts) ─────────────────────────────────────

    public function listCompanies(array $params): array
    {
        return $this->wrap(function () use ($params): array {
            $limit = QueryInt::param($params, 'limit', 50, 1, 500, 'rows per page');
            $offset = QueryInt::param($params, 'offset', 0, 0, PHP_INT_MAX, 'rows to skip');
            $result = (new MysqlCompanyRepository($this->conn))->listWithRollups($this->userId, $limit, $offset);

            return [
                'data' => $result['rows'],
                'pagination' => ['total' => $result['total'], 'limit' => $limit, 'offset' => $offset],
            ];
        });
    }

    public function createCompany(array $payload): array
    {
        \Api\V3\Support\PayloadKeys::refuseUnknown($payload, ['name', 'domain'], 'a company');
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            throw new ValidationException('name is required', ['name' => 'Required']);
        }

        return $this->wrap(function () use ($payload, $name): array {
            // create() owns the conflict check (typed, and race-proof via the
            // unique key on the single INSERT) and validates the domain
            // before inserting — a rejected domain never leaves a row behind.
            $domain = isset($payload['domain']) && trim((string) $payload['domain']) !== ''
                ? (string) $payload['domain']
                : null;

            try {
                $companyId = (new MysqlCompanyRepository($this->conn))->create($this->userId, $name, $domain);
            } catch (CompanyConflictException $e) {
                throw new ConflictException($e->getMessage());
            }

            return ['data' => ['company_id' => $companyId]];
        });
    }

    public function patchCompany(int $companyId, array $payload): array
    {
        \Api\V3\Support\PayloadKeys::refuseUnknown($payload, ['name', 'domain'], 'a company update');
        if (!array_key_exists('name', $payload) && !array_key_exists('domain', $payload)) {
            throw new ValidationException('Nothing to update — supply name and/or domain', [
                'name' => 'Send name, domain or both',
            ]);
        }

        $companies = new MysqlCompanyRepository($this->conn);
        if ($this->wrap(fn (): ?array => $companies->get($this->userId, $companyId)) === null) {
            throw new NotFoundException('Company not found');
        }

        return $this->wrap(function () use ($companies, $companyId, $payload): array {
            // Single atomic update: name and domain are validated together
            // up front, then applied in one transaction — no partial apply.
            $changes = [];
            if (array_key_exists('name', $payload)) {
                $changes['name'] = (string) $payload['name'];
            }
            if (array_key_exists('domain', $payload)) {
                $changes['domain'] = $payload['domain'] !== null ? (string) $payload['domain'] : null;
            }
            try {
                $companies->update($this->userId, $companyId, $changes);
            } catch (CompanyConflictException $e) {
                // A duplicate name/domain (preflight or the unique-key race) is
                // a 409 conflict, same as the create path — not the 422 that
                // wrap() would map a bare RuntimeException to.
                throw new ConflictException($e->getMessage());
            }

            return ['data' => $companies->get($this->userId, $companyId)];
        });
    }

    public function mergeCompany(int $companyId, array $payload): array
    {
        \Api\V3\Support\PayloadKeys::refuseUnknown($payload, ['source_company_id'], 'a company merge');
        $sourceId = QueryInt::param($payload, 'source_company_id', 0, 1, PHP_INT_MAX, 'the company to merge INTO this one');
        if ($sourceId <= 0) {
            throw new ValidationException('source_company_id is required', ['source_company_id' => 'Required']);
        }

        return $this->wrap(function () use ($companyId, $sourceId): array {
            $companies = new MysqlCompanyRepository($this->conn);
            $companies->merge($this->userId, $sourceId, $companyId);

            return ['data' => $companies->get($this->userId, $companyId)];
        });
    }

    public function deleteCompany(int $companyId): void
    {
        $companies = new MysqlCompanyRepository($this->conn);
        if ($this->wrap(fn (): ?array => $companies->get($this->userId, $companyId)) === null) {
            throw new NotFoundException('Company not found');
        }
        $this->wrap(function () use ($companies, $companyId): void {
            $companies->delete($this->userId, $companyId);
        });
    }

    // ── Subscriptions: account-wide read ─────────────────────────────

    public function listSubscriptions(array $params): array
    {
        return $this->wrap(function () use ($params): array {
            $limit = QueryInt::param($params, 'limit', 50, 1, 500, 'rows per page');
            $offset = QueryInt::param($params, 'offset', 0, 0, PHP_INT_MAX, 'rows to skip');
            $status = self::choices($params, ['status' => MysqlSubscriptionRepository::STATUSES])['status'];
            $result = $this->subscriptions->listForUser($this->userId, $status, $limit, $offset);

            return [
                'data' => $result['rows'],
                'pagination' => ['total' => $result['total'], 'limit' => $limit, 'offset' => $offset],
            ];
        });
    }

    public function deleteCustomerAlias(int $customerId, int $aliasId): void
    {
        $this->requireCustomer($customerId);
        $this->wrap(function () use ($customerId, $aliasId): void {
            try {
                $this->customers->deleteAlias($this->userId, $customerId, $aliasId);
            } catch (RecordNotFoundException) {
                // ONLY the typed zero-rows case is a 404, matching sibling
                // endpoints; a DB failure during the DELETE must surface as
                // an error, never as "already deleted".
                throw new NotFoundException('Alias not found on this customer');
            }
        });
    }

    public function listIntegrations(): array
    {
        return $this->wrap(fn (): array => [
            'data' => (new MysqlIntegrationRepository($this->conn))->list($this->userId),
        ]);
    }

    public function createIntegration(array $payload): array
    {
        PayloadKeys::refuseUnknown($payload, ['provider', 'name', 'config'], 'an integration');
        // config is free-form by design: the provider's own settings, stored
        // as sent and read by nothing here, so no key in it is refused. It is
        // an object, as documented: a list was stored as one.
        if (
            isset($payload['config'])
            && (!is_array($payload['config']) || ($payload['config'] !== [] && array_is_list($payload['config'])))
        ) {
            throw new ValidationException('config must be an object', [
                'config' => 'must be a JSON object of the provider\'s settings (any keys)',
            ]);
        }

        return $this->wrap(function () use ($payload): array {
            $integrationId = (new MysqlIntegrationRepository($this->conn))->create(
                $this->userId,
                (string) ($payload['provider'] ?? ''),
                (string) ($payload['name'] ?? ''),
                isset($payload['config']) && is_array($payload['config']) ? $payload['config'] : null
            );

            return ['data' => ['integration_id' => $integrationId]];
        });
    }

    public function deleteIntegration(int $integrationId): void
    {
        $this->wrap(function () use ($integrationId): void {
            try {
                (new MysqlIntegrationRepository($this->conn))->delete($this->userId, $integrationId);
            } catch (RecordNotFoundException) {
                // ONLY the typed zero-rows case is a 404; a DB failure must
                // surface as an error, never as "already deleted".
                throw new NotFoundException('Integration not found');
            }
        });
    }

    // ── Delete previews (`?dry_run=1`) ───────────────────────────────
    //
    // Read-only: what each DELETE above would remove, or why it would
    // refuse, without doing it. Each finds its record with the same scope
    // and the same not-found answer as its DELETE, so a preview that
    // answers 200 names the row the DELETE would act on. The DELETEs make no
    // authorization check of their own (the route group's ltv:write
    // middleware, which the dispatcher runs before a preview too, is their
    // only one), so neither do the previews.

    /**
     * Preview of deleteCustomer(): an erasure, not a delete. Personal data
     * goes, the customer row and its money stay.
     */
    public function deleteCustomerPreview(int $customerId): array
    {
        $this->requireCustomer($customerId);

        return $this->wrap(function () use ($customerId): array {
            $customer = $this->crm->get($this->userId, $customerId, 1);
            if ($customer === null) {
                throw new NotFoundException('Customer not found');
            }
            // The record is what erasure changes; the money it keeps is
            // counted in the cascade rather than listed.
            unset($customer['recent_events'], $customer['subscriptions']);

            return ['data' => [
                'dry_run' => true,
                'action' => 'erase',
                'resource' => 'ltv-customers',
                'mode' => 'anonymize',
                'record' => $customer,
                'effect' => 'Erases the customer\'s personal data in one transaction: first_name, last_name, email, '
                    . 'phone, company, address_line1, address_line2, city, region, postal_code and country are '
                    . 'cleared, the company link is removed, primary_ref becomes erased:' . $customerId
                    . ' and status anonymized. The identity-graph signal of each alias, where the graph holds one, '
                    . 'is deleted, so no later click joins this customer through it. Revenue events and '
                    . 'subscriptions are kept with their amounts, so LTV totals do not change. Not reversible.',
                'cascade' => $this->crm->erasurePreview($this->userId, $customerId),
            ]];
        });
    }

    /**
     * Preview of deleteCustomerAlias(): one identifier stops resolving to
     * the customer; the customer is untouched.
     */
    public function deleteCustomerAliasPreview(int $customerId, int $aliasId): array
    {
        $this->requireCustomer($customerId);
        $alias = $this->wrap(fn (): ?array => $this->customers->findAlias($this->userId, $customerId, $aliasId));
        if ($alias === null) {
            throw new NotFoundException('Alias not found on this customer');
        }

        return ['data' => [
            'dry_run' => true,
            'action' => 'delete',
            'resource' => 'customer-aliases',
            'mode' => 'hard',
            'record' => $alias,
            'effect' => 'Removes only the mapping: the customer, its revenue and its other aliases stay. A later event '
                . 'carrying this identifier no longer resolves to customer ' . $customerId . ' (it creates or matches '
                . 'another customer).',
            'cascade' => [],
        ]];
    }

    /**
     * Preview of deleteCompany(), including the refusal the delete would
     * answer while customers are attached (refused: the delete's own
     * message; null when it would go ahead).
     */
    public function deleteCompanyPreview(int $companyId): array
    {
        $companies = new MysqlCompanyRepository($this->conn);
        $company = $this->wrap(fn (): ?array => $companies->get($this->userId, $companyId));
        if ($company === null) {
            throw new NotFoundException('Company not found');
        }
        $refused = $this->wrap(
            fn (): ?string => $companies->deleteRefusal($this->userId, $companyId, (string) $company['name'])
        );

        return ['data' => [
            'dry_run' => true,
            'action' => 'delete',
            'resource' => 'ltv-companies',
            'mode' => 'hard',
            'record' => $company,
            'refused' => $refused,
            'cascade' => [],
        ]];
    }

    /**
     * Preview of deleteField(): the definition and every customer's value
     * for it.
     */
    public function deleteFieldPreview(int $fieldId): array
    {
        return $this->wrap(function () use ($fieldId): array {
            $field = $this->fields->get($this->userId, $fieldId);
            if ($field === null) {
                throw new NotFoundException('Field not found');
            }

            return ['data' => [
                'dry_run' => true,
                'action' => 'delete',
                'resource' => 'ltv-fields',
                'mode' => 'hard',
                'record' => self::presentField($field),
                'cascade' => [
                    [
                        'resource' => 'customer-field-values',
                        'count' => $this->fields->valueCount($this->userId, $fieldId),
                    ],
                ],
            ]];
        });
    }

    /**
     * Preview of deleteWebhook(): the endpoint (never its secret) and its
     * delivery queue, pending deliveries included — they are dropped, not
     * sent.
     */
    public function deleteWebhookPreview(int $webhookId): array
    {
        return $this->wrap(function () use ($webhookId): array {
            $webhook = $this->webhooks->get($this->userId, $webhookId);
            if ($webhook === null) {
                throw new NotFoundException('Webhook not found');
            }
            $byStatus = $this->webhooks->deliveryCounts($this->userId, $webhookId);

            return ['data' => [
                'dry_run' => true,
                'action' => 'delete',
                'resource' => 'ltv-webhooks',
                'mode' => 'hard',
                'record' => $webhook,
                'cascade' => [
                    [
                        'resource' => 'ltv-webhook-deliveries',
                        'count' => array_sum($byStatus),
                        'by_status' => (object) $byStatus,
                    ],
                ],
            ]];
        });
    }

    /**
     * Preview of deleteProduct(), including the refusal the delete would
     * answer while order line items name the product (refused: the delete's
     * own message; null when it would go ahead). Nothing cascades: line
     * items keep their own snapshot of the product.
     */
    public function deleteProductPreview(int $productId): array
    {
        return $this->wrap(function () use ($productId): array {
            $product = $this->findProduct($productId);
            if ($product === null) {
                throw new NotFoundException('Product not found');
            }

            return ['data' => [
                'dry_run' => true,
                'action' => 'delete',
                'resource' => 'ltv-products',
                'mode' => 'hard',
                'record' => $product,
                'refused' => $this->productDeleteRefusal($productId),
                'cascade' => [],
            ]];
        });
    }

    /**
     * Preview of deleteIntegration(): the record alone; nothing else
     * references it.
     */
    public function deleteIntegrationPreview(int $integrationId): array
    {
        return $this->wrap(function () use ($integrationId): array {
            $integration = (new MysqlIntegrationRepository($this->conn))->get($this->userId, $integrationId);
            if ($integration === null) {
                throw new NotFoundException('Integration not found');
            }

            return ['data' => [
                'dry_run' => true,
                'action' => 'delete',
                'resource' => 'ltv-integrations',
                'mode' => 'hard',
                'record' => $integration,
                'cascade' => [],
            ]];
        });
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * Query parameters that each take one of a fixed list: absent or '' is
     * null (the caller's default), one of the list is that value, and
     * anything else is a 422 naming the parameter and its list — every bad
     * one at once — the way ReportsController refuses a sort. `sort`/`dir`
     * on GET /ltv/customers used to fall back to total_revenue/DESC without
     * a word, so a misspelled order came back 200 ranked by something else;
     * `segment`, `by` and `status` were refused with a message but no field
     * to fix. `dir` is read in either case, as the reports read `sort_dir`.
     *
     * @param array<string, mixed> $params
     * @param array<string, list<string>> $lists parameter => its values
     * @return array<string, ?string> parameter => the value, or null
     */
    private static function choices(array $params, array $lists): array
    {
        $chosen = [];
        $errors = [];
        foreach ($lists as $name => $valid) {
            $value = $params[$name] ?? '';
            if ($value === '') {
                $chosen[$name] = null;
                continue;
            }
            if (is_string($value) && $name === 'dir') {
                $value = strtoupper($value);
            }
            if (!is_string($value) || !in_array($value, $valid, true)) {
                $errors[$name] = 'Valid values: ' . implode(', ', $valid);
                continue;
            }
            $chosen[$name] = $value;
        }
        if ($errors !== []) {
            throw new ValidationException('Invalid ' . implode(', ', array_keys($errors)), $errors);
        }

        return $chosen;
    }

    /**
     * Build the LtvQuery from request params: time window (time_from/time_to
     * in TimeBound's forms, or a period from TimeBound::PERIODS, which
     * replaces them) and up to 3 custom-field filters (cf.<field_key>=value,
     * cf.<field_key>.min= / .max= for number/date fields).
     */
    private function query(array $params): LtvQuery
    {
        // Read as the reports read them (TimeBound): a date-shaped value
        // cast with (int) was 2026 seconds, and the window covered all time.
        [$timeFrom, $timeTo] = TimeBound::window($params, fn (): string => $this->accountTimezone());

        // Absent and '' are no period, as ReportFilter reads them; every
        // other value is a period or a 422. This was !empty(), and empty('0')
        // is true, so period=0 named no period at all: the answer came back
        // 200 over the default window (all time), reading as the window asked
        // for, where period=1 was refused. 0 is not one of TimeBound::PERIODS
        // and means nothing here, so it is refused with the list.
        $period = $params['period'] ?? '';
        if ($period !== '') {
            // The reports' periods, computed where they are: today and
            // yesterday at the account's midnight, not the server's. A typo
            // like period=last7d is a 422, never all time.
            [$timeFrom, $timeTo] = TimeBound::period($period, fn (): string => $this->accountTimezone());
        }

        $filters = [];
        $cfParams = $this->customFieldFilterParams($params);
        if (count($cfParams) > LtvQuery::MAX_CUSTOM_FIELD_FILTERS) {
            $extra = array_slice(array_keys($cfParams), LtvQuery::MAX_CUSTOM_FIELD_FILTERS);
            $most = LtvQuery::MAX_CUSTOM_FIELD_FILTERS;
            throw new ValidationException(
                'At most ' . $most . ' custom-field filters are supported per query',
                array_fill_keys($extra, 'One filter too many: at most ' . $most . ' cf.* filters per query')
            );
        }
        foreach ($cfParams as $key => $value) {
            $parts = explode('.', $key);
            $fieldKey = $parts[1] ?? '';
            $bound = $parts[2] ?? '';
            $field = $this->fields->findByKey($this->userId, $fieldKey);
            if ($field === null) {
                throw new ValidationException(
                    'Unknown custom field "' . $fieldKey . '" in filter',
                    [$key => 'No such field']
                );
            }
            $type = (string) $field['field_type'];
            $column = match ($type) {
                'number', 'boolean' => 'value_number',
                'date' => 'value_date',
                default => 'value_text',
            };
            $op = match ($bound) {
                '' => '=',
                'min' => '>=',
                'max' => '<=',
                default => throw new ValidationException('Invalid filter bound "' . $bound . '"', [$key => 'Use .min or .max']),
            };
            if ($op !== '=' && $column === 'value_text') {
                throw new ValidationException('Range filters apply only to number/date fields', [$key => 'Not a range field']);
            }
            $filters[] = [
                'fieldId' => (int) $field['field_id'],
                'column' => $column,
                'op' => $op,
                'value' => $column === 'value_text' ? (string) $value : $this->coerceFilterValue($type, $key, $value),
            ];
        }

        // Every filter above was built here from a checked parameter, so
        // what LtvQuery refuses is this code's mistake, not the caller's: it
        // is not a 422 with no field to fix, it is a 500 (wrap()).
        return new LtvQuery($this->userId, $timeFrom, $timeTo, $filters);
    }

    /**
     * Collect the cf.* custom-field filters as dotted keys mapped to values.
     *
     * PHP rewrites '.' to '_' in $_GET keys, so over HTTP `cf.vip=true` arrives
     * as `cf_vip` and a `str_starts_with($key, 'cf.')` check would silently drop
     * every documented cf.* filter (returning UNFILTERED cohorts). The dot also
     * disambiguates the field key from the .min/.max bound (`cf.vip.min` — the
     * underscore form `cf_vip_min` is ambiguous against a field literally named
     * `vip_min`), so we recover the ORIGINAL keys from the raw query string
     * rather than trust the normalized $_GET. Dotted keys already present in
     * $params (CLI / internal callers that never went through $_GET) are honored
     * too, so both call paths behave identically.
     *
     * @param array<string, mixed> $params
     * @return array<string, string> dotted cf.* key => value
     */
    private function customFieldFilterParams(array $params): array
    {
        $cf = [];
        foreach ($params as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'cf.') && is_scalar($value)) {
                $cf[$key] = (string) $value;
            }
        }
        $raw = (string) ($_SERVER['QUERY_STRING'] ?? '');
        if ($raw !== '') {
            foreach (explode('&', $raw) as $pair) {
                if ($pair === '') {
                    continue;
                }
                $eq = strpos($pair, '=');
                $rawKey = $eq === false ? $pair : substr($pair, 0, $eq);
                $key = urldecode($rawKey);
                if (str_starts_with($key, 'cf.')) {
                    $cf[$key] = $eq === false ? '' : urldecode(substr($pair, $eq + 1));
                }
            }
        }

        return $cf;
    }

    /**
     * Coerce a cf.* filter value with the SAME rules custom-field writes use
     * (MysqlCustomerFieldRepository::coerce): a date filter string must become
     * the stored Unix timestamp and a boolean must become 1/0 — a blind float
     * cast would quietly compare '2026-01-01' as 2026 and 'true' as 0,
     * returning the wrong cohort.
     */
    private function coerceFilterValue(string $fieldType, string $paramKey, mixed $value): float
    {
        if ($fieldType === 'boolean') {
            $normalized = strtolower(trim((string) $value));
            if (!in_array($normalized, ['0', '1', 'true', 'false', 'yes', 'no'], true)) {
                throw new ValidationException(
                    'Boolean filter expects 0/1/true/false/yes/no',
                    [$paramKey => 'Invalid boolean value']
                );
            }
            return in_array($normalized, ['1', 'true', 'yes'], true) ? 1.0 : 0.0;
        }
        if ($fieldType === 'date') {
            if (is_numeric($value)) {
                return (float) $value;
            }
            $parsed = strtotime(trim((string) $value));
            if ($parsed === false) {
                throw new ValidationException(
                    'Date filter expects a unix timestamp or a parseable date string',
                    [$paramKey => 'Invalid date value']
                );
            }
            return (float) $parsed;
        }
        if (!is_numeric($value)) {
            throw new ValidationException(
                'Number filter expects a numeric value',
                [$paramKey => 'Invalid number value']
            );
        }

        return (float) $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveCustomerFromPayload(array $payload, int $now, bool $inTransaction = false): int
    {
        $explicitId = QueryInt::param($payload, 'customer_id', 0, 1, PHP_INT_MAX, 'an LTV customer id (see `p202 ltv customers`)');
        if ($explicitId > 0) {
            if (!$this->customers->customerBelongsToUser($explicitId, $this->userId)) {
                throw new NotFoundException('customer_id ' . $explicitId . ' not found for this account');
            }
            return $this->customers->followMergePointer($explicitId);
        }

        $ref = trim((string) ($payload['customer_ref'] ?? ''));
        if ($ref === '') {
            throw new ValidationException(
                'customer_id or customer_ref is required',
                ['customer_ref' => 'Identify the customer this revenue belongs to']
            );
        }
        $refType = isset($payload['customer_ref_type']) ? (string) $payload['customer_ref_type'] : 'custom';
        // An object of CRM fields: every caller refused anything else
        // (PayloadKeys::objectErrors()) before it got here. It was read as
        // none when it was not an array, so a string answered 201.
        $crm = $payload['customer_crm'] ?? [];

        $resolve = fn (): int => $this->customers->resolveOrCreateByAlias($this->userId, $refType, $ref, $crm, null, $now);

        // Callers already inside a transaction (revenue/engagement ingest)
        // pass true so identity creation rolls back with the rest of their
        // write — Connection::transaction does not nest.
        return $inTransaction ? $resolve() : $this->conn->transaction($resolve);
    }

    private function requireCustomer(int $customerId): void
    {
        if (!$this->customers->customerBelongsToUser($customerId, $this->userId)) {
            throw new NotFoundException('Customer not found');
        }
    }

    /**
     * Queue a webhook event. Enqueue failures are logged, never fatal — a
     * broken webhook must not fail the API write that triggered it.
     *
     * @param array<string, mixed> $payload
     */
    private function enqueueEvent(string $eventName, array $payload): void
    {
        try {
            $this->webhooks->enqueue($this->userId, $eventName, $payload);
        } catch (\Throwable $e) {
            error_log('ltv webhook enqueue failed (' . $eventName . '): ' . $e->getMessage());
        }
    }

    /**
     * Run a repository call, translating what it throws into the API's
     * answers: a refusal of what was sent (LtvInputException) is a 422
     * naming its field, a missing record a 404, a write a concurrent one got
     * to first a 409, and everything else the server's failure, a 500.
     *
     * Every RuntimeException used to be a 422 carrying only its message --
     * "amount must not be negative for purchase events", an unknown custom
     * field, a reused idempotency key -- so an agent had a sentence and no
     * field to fix, and a repository's own failure ("did not yield a
     * customer_id", an identity-graph error) read as the caller's mistake.
     * A refusal now says which field (LtvFieldErrorsTest holds every 422
     * this controller answers to one).
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function wrap(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (ValidationException | NotFoundException | ConflictException | DatabaseException $e) {
            throw $e;
        } catch (LtvInputException $e) {
            throw new ValidationException($e->getMessage(), $e->fieldErrors(), $e);
        } catch (RecordNotFoundException | SubscriptionNotFoundException $e) {
            throw new NotFoundException($e->getMessage(), $e);
        } catch (LtvConflictException | CompanyConflictException $e) {
            throw new ConflictException($e->getMessage(), [], $e);
        } catch (\Throwable $e) {
            // A database-LAYER failure (missing table on a code-before-migration
            // deploy, a failed statement) and anything else a repository
            // throws that is not a refusal: a 500, NOT a client-correctable
            // 422 — under MYSQLI_REPORT_STRICT a failed query surfaces as
            // Connection's QueryException (a RuntimeException subclass), and
            // raw MySQL detail must not reach the client.
            throw new DatabaseException('LTV operation failed: ' . $e->getMessage(), $e);
        }
    }
}
