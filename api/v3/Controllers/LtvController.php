<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\ConflictException;
use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;
use Prosper202\Database\Connection;
use Prosper202\Ltv\LtvQuery;
use Prosper202\Ltv\MysqlCompanyRepository;
use Prosper202\Ltv\MysqlCustomerCrmRepository;
use Prosper202\Ltv\MysqlCustomerFieldRepository;
use Prosper202\Ltv\CompanyConflictException;
use Prosper202\Ltv\MysqlCustomerRepository;
use Prosper202\Ltv\MysqlIntegrationRepository;
use Prosper202\Ltv\RecordNotFoundException;
use Prosper202\Ltv\MysqlLtvRepository;
use Prosper202\Ltv\MysqlSubscriptionRepository;
use Prosper202\Ltv\MysqlWebhookRepository;
use Prosper202\Ltv\SubscriptionNotFoundException;

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
            $limit = max(1, min(500, (int) ($params['limit'] ?? 50)));
            $offset = max(0, (int) ($params['offset'] ?? 0));
            $result = $this->ltv->customers(
                $this->query($params),
                (string) ($params['sort'] ?? 'total_revenue'),
                (string) ($params['dir'] ?? 'DESC'),
                $limit,
                $offset,
                isset($params['q']) ? (string) $params['q'] : null,
                isset($params['segment']) ? (string) $params['segment'] : null
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
            $months = max(1, min(24, (int) ($params['months'] ?? 6)));

            return ['data' => $this->ltv->cohorts($this->userId, $months), 'months' => $months];
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
            $by = (string) ($params['by'] ?? $params['breakdown'] ?? 'campaign');
            $limit = max(1, min(500, (int) ($params['limit'] ?? 50)));
            $offset = max(0, (int) ($params['offset'] ?? 0));

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
            $by = isset($params['by']) && trim((string) $params['by']) !== '' ? (string) $params['by'] : null;

            return ['data' => $this->ltv->predict($this->query($params), $by)];
        });
    }

    public function products(array $params): array
    {
        return $this->wrap(function () use ($params): array {
            $limit = max(1, min(500, (int) ($params['limit'] ?? 50)));
            $offset = max(0, (int) ($params['offset'] ?? 0));
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
            $days = max(1, min(365, (int) ($params['days'] ?? 90)));
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
     * Manually instrument an ABM engagement event from a server-side
     * integration ("demo_requested", "pricing_viewed", ...).
     */
    public function recordEngagementEvent(array $payload): array
    {
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
                    isset($payload['occurred_at']) ? (int) $payload['occurred_at'] : null,
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

    public function abm(array $params): array
    {
        return $this->wrap(function () use ($params): array {
            $days = max(1, min(365, (int) ($params['days'] ?? 90)));
            $limit = max(1, min(500, (int) ($params['limit'] ?? 50)));
            $offset = max(0, (int) ($params['offset'] ?? 0));
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
            $days = max(1, min(365, (int) ($params['days'] ?? 90)));
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
        return $this->wrap(function (): array {
            $rows = $this->fields->list($this->userId);
            foreach ($rows as &$row) {
                if (isset($row['options']) && is_string($row['options'])) {
                    $row['options'] = json_decode($row['options'], true);
                }
            }
            unset($row);

            return ['data' => $rows];
        });
    }

    // ── Customer CRM writes ──────────────────────────────────────────

    public function upsertCustomer(array $payload): array
    {
        $customerId = $this->wrap(fn (): int => $this->crm->upsert($this->userId, $payload));
        $this->enqueueEvent('customer.updated', ['customer_id' => $customerId]);

        return $this->customerDetail($customerId);
    }

    public function patchCustomer(int $customerId, array $payload): array
    {
        $this->requireCustomer($customerId);
        $payload['customer_id'] = $customerId;
        unset($payload['customer_ref'], $payload['customer_ref_type']);

        $resolved = $this->wrap(fn (): int => $this->crm->upsert($this->userId, $payload));
        $this->enqueueEvent('customer.updated', ['customer_id' => $resolved]);

        return $this->customerDetail($resolved);
    }

    public function mergeCustomer(int $targetId, array $payload): array
    {
        $sourceId = (int) ($payload['source_customer_id'] ?? 0);
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
        $this->requireCustomer($customerId);
        $value = trim((string) ($payload['value'] ?? ''));
        if ($value === '') {
            throw new ValidationException('value is required', ['value' => 'The external identifier to map']);
        }

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
     * caller-supplied idempotency_key.
     */
    public function recordRevenue(array $payload): array
    {
        return $this->wrap(function () use ($payload): array {
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
            $occurredAt = (int) ($payload['occurred_at'] ?? $now);
            $items = $payload['items'] ?? [];
            if (!is_array($items)) {
                throw new ValidationException('items must be an array', ['items' => 'Must be an array of line items']);
            }

            $idempotencyKey = isset($payload['idempotency_key']) && trim((string) $payload['idempotency_key']) !== ''
                ? trim((string) $payload['idempotency_key'])
                : null;

            $result = $this->conn->transaction(function () use ($eventType, $amount, $currency, $occurredAt, $payload, $items, $now, $idempotencyKey): array {
                // Idempotent replay FIRST: a replay carrying a different (or
                // brand-new) customer_ref must return the original event and
                // its owner, not resolve/create a customer for a write that
                // will never happen.
                if ($idempotencyKey !== null) {
                    $existing = $this->customers->findEventByIdempotencyKey($this->userId, $idempotencyKey);
                    if ($existing !== null) {
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
                    // Lost a concurrent race on the key: report the winner's
                    // owner, not the customer this request resolved.
                    $existing = $this->customers->findEventByIdempotencyKey($this->userId, $idempotencyKey);
                    if ($existing !== null) {
                        $customerId = $existing['customer_id'];
                    }
                }

                return $event + ['customerId' => $customerId];
            });

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

    public function upsertSubscription(array $payload): array
    {
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
        $eventType = strtolower(trim((string) ($payload['event_type'] ?? '')));
        if (!in_array($eventType, ['renewal', 'cancel', 'refund'], true)) {
            throw new ValidationException(
                'event_type must be renewal, cancel or refund',
                ['event_type' => 'Invalid value']
            );
        }

        try {
            $result = $this->subscriptions->recordEvent($this->userId, $externalSubId, $eventType, $payload);
        } catch (SubscriptionNotFoundException $e) {
            throw new NotFoundException($e->getMessage());
        } catch (\RuntimeException $e) {
            throw new ValidationException($e->getMessage());
        } catch (\Throwable $e) {
            throw new DatabaseException('Subscription event failed: ' . $e->getMessage(), $e);
        }

        $this->enqueueEvent('subscription.changed', [
            'subscription_id' => $result['subscriptionId'],
            'customer_id' => $result['customerId'],
            'action' => $eventType,
        ]);

        return ['data' => $result];
    }

    public function upsertProduct(array $payload): array
    {
        return $this->wrap(function () use ($payload): array {
            $currency = $this->customers->accountCurrency($this->userId);
            $productId = $this->conn->transaction(
                fn (): int => $this->customers->upsertProduct($this->userId, $payload, $currency, time())
            );

            return ['data' => ['product_id' => $productId]];
        });
    }

    // ── Custom field definitions ─────────────────────────────────────

    public function createField(array $payload): array
    {
        $fieldId = $this->wrap(fn (): int => $this->fields->create($this->userId, $payload));

        return ['data' => ['field_id' => $fieldId]];
    }

    public function updateField(int $fieldId, array $payload): array
    {
        $this->wrap(function () use ($fieldId, $payload): void {
            try {
                $this->fields->update($this->userId, $fieldId, $payload);
            } catch (RecordNotFoundException) {
                throw new NotFoundException('Field not found');
            }
        });

        return $this->fieldsList();
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
        $url = trim((string) ($payload['url'] ?? $payload['webhook_url'] ?? ''));
        if ($url === '') {
            throw new ValidationException('url is required', ['url' => 'The https endpoint to deliver events to']);
        }
        $events = isset($payload['events']) && is_array($payload['events']) ? $payload['events'] : [];

        $result = $this->wrap(fn (): array => $this->webhooks->create($this->userId, $url, $events));

        // The secret is returned exactly once, at creation.
        return ['data' => ['webhook_id' => $result['webhookId'], 'secret' => $result['secret']]];
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
            $limit = max(1, min(500, (int) ($params['limit'] ?? 50)));
            $offset = max(0, (int) ($params['offset'] ?? 0));
            $result = (new MysqlCompanyRepository($this->conn))->listWithRollups($this->userId, $limit, $offset);

            return [
                'data' => $result['rows'],
                'pagination' => ['total' => $result['total'], 'limit' => $limit, 'offset' => $offset],
            ];
        });
    }

    public function createCompany(array $payload): array
    {
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
        if (!array_key_exists('name', $payload) && !array_key_exists('domain', $payload)) {
            throw new ValidationException('Nothing to update — supply name and/or domain', []);
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
            $companies->update($this->userId, $companyId, $changes);

            return ['data' => $companies->get($this->userId, $companyId)];
        });
    }

    public function mergeCompany(int $companyId, array $payload): array
    {
        $sourceId = (int) ($payload['source_company_id'] ?? 0);
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
            $limit = max(1, min(500, (int) ($params['limit'] ?? 50)));
            $offset = max(0, (int) ($params['offset'] ?? 0));
            $status = isset($params['status']) && trim((string) $params['status']) !== ''
                ? trim((string) $params['status'])
                : null;
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
        if (isset($payload['config']) && !is_array($payload['config'])) {
            throw new ValidationException('config must be an object', ['config' => 'Must be an object']);
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

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * Build the LtvQuery from request params: time window (time_from/time_to
     * or period=today|yesterday|last7|last30|last90) and up to 3 custom-field
     * filters (cf.<field_key>=value, cf.<field_key>.min= / .max= for
     * number/date fields).
     */
    private function query(array $params): LtvQuery
    {
        $timeFrom = !empty($params['time_from']) ? (int) $params['time_from'] : null;
        $timeTo = !empty($params['time_to']) ? (int) $params['time_to'] : null;

        if (!empty($params['period'])) {
            $now = time();
            $todayStart = strtotime('today midnight');
            [$timeFrom, $timeTo] = match ((string) $params['period']) {
                'today'     => [$todayStart, $now],
                'yesterday' => [$todayStart - 86400, $todayStart - 1],
                'last7'     => [$now - (7 * 86400), $now],
                'last30'    => [$now - (30 * 86400), $now],
                'last90'    => [$now - (90 * 86400), $now],
                default     => [null, $now],
            };
        }

        $filters = [];
        foreach ($params as $key => $value) {
            if (!is_string($key) || !str_starts_with($key, 'cf.') || !is_scalar($value)) {
                continue;
            }
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

        try {
            return new LtvQuery($this->userId, $timeFrom, $timeTo, $filters);
        } catch (\RuntimeException $e) {
            throw new ValidationException($e->getMessage());
        }
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
        $explicitId = isset($payload['customer_id']) ? (int) $payload['customer_id'] : 0;
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
        $crm = isset($payload['customer_crm']) && is_array($payload['customer_crm']) ? $payload['customer_crm'] : [];

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
     * Run a repository call, translating RuntimeExceptions (validation-shaped
     * messages from the Ltv repos) to 422s and everything else to 500s.
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
        } catch (\RuntimeException $e) {
            throw new ValidationException($e->getMessage());
        } catch (\Throwable $e) {
            throw new DatabaseException('LTV operation failed: ' . $e->getMessage(), $e);
        }
    }
}
