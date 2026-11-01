<?php

declare(strict_types=1);

namespace Tests\Ltv;

use PHPUnit\Framework\TestCase;

/**
 * The Customer LTV pages under tracking202/ajax/ read the ids their buttons
 * and the merge dialog post exactly, and refuse anything else in their own
 * words, having changed nothing. Companies' merge read `company_id` and
 * `source_company_id` with (int), so `12abc` merged company 12: one of the
 * account's own, and not the one named (CLAUDE.md #4); the customer page's
 * Erase, Merge and Unlink, and the products' Save and Delete, did the same.
 * Each page runs as the account in a process of its own
 * (tests/Setup/fixtures/page-runner.php), as the web server runs it, as
 * LtvSettingsPostedValuesIntegrationTest runs LTV Settings.
 *
 * Needs a 202-config.php (connect.php exits without one) and P202_TEST_DB_*.
 *
 * @group integration
 */
final class LtvPagesPostedIdsIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;
    use GrantsLtvRole;

    private const USER = 990962;
    private const KEPT_COMPANY = 97011;
    private const GONE_COMPANY = 97012;
    private const KEPT_CUSTOMER = 97021;
    private const GONE_CUSTOMER = 97022;
    private const ALIAS = 97031;
    private const PRODUCT = 97041;
    private const OTHER_PRODUCT = 97042;

    private const COMPANIES = 'tracking202/ajax/ltv_companies.php';
    private const PRODUCTS = 'tracking202/ajax/ltv_products.php';
    private const CUSTOMER = 'tracking202/ajax/ltv_customer.php';
    private const SEARCH = 'tracking202/ajax/ltv_merge_search.php';
    private const SUBSCRIPTIONS = 'tracking202/ajax/ltv_subscriptions.php';
    private const REPORT = 'tracking202/ajax/sort_ltv.php';
    private const COMPANY = 'tracking202/ajax/ltv_company.php';

    /** What an id posted by a row's button or the merge dialog must never be read as. */
    private const NOT_IDS = ['abc', '0', '-1', '', ' %d', '%d ', '%d.0', '0%d', '+%d', '%dabc', ['%d']];

    /** What the pager's offset must never be read as; none, '' and 0 are the first page. */
    private const NOT_OFFSETS = ['abc', '-50', ' 50', '50 ', '50.0', '050', '+50', '50abc', ['50']];

    private const NO_PAGE = 'Pick a page with Previous or Next.';
    private const NO_CUSTOMER = 'Pick the customer from the Customer LTV list.';

    private const CRM = [
        'first_name' => 'Ada', 'last_name' => 'Lovelace', 'phone' => '', 'company' => 'Acme',
        'address_line1' => '', 'address_line2' => '', 'city' => '', 'region' => '', 'postal_code' => '',
        'country' => '',
    ];

    private const LTV_TABLES = [
        '202_customer_aliases', '202_customer_field_values', '202_customer_fields', '202_revenue_line_items',
        '202_revenue_events', '202_subscriptions', '202_personalization_tokens', '202_engagement_events',
        '202_offer_recommendations', '202_identity_keys', '202_identity_signals', '202_identity_merges',
        '202_identity_visitors', '202_customers', '202_companies', '202_products',
    ];

    private static string $sessions = '';

    /** @var list<string>|null 202_version as this class found it, put back after */
    private static ?array $versions = null;

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        if (!is_file($root . '/202-config.php')) {
            self::markTestSkipped('No 202-config.php: connect.php would exit before any page ran.'
                . ' tests/run-integration-suites.sh writes one.');
        }
        if (!self::connectScratch()) {
            return;
        }
        require_once $root . '/202-config/version.php';
        $found = self::$db->query('SELECT version FROM 202_version');
        $rows = $found instanceof \mysqli_result ? $found->fetch_all(MYSQLI_ASSOC) : [];
        self::$versions = array_column($rows, 'version');
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => PROSPER202_VERSION]);
        self::cleanUp();
        self::user(self::USER);
        self::row('202_users_pref', ['user_id' => self::USER]);
        self::grantLtvRole(self::USER, self::USER); // LTV asks for its permissions
        self::q("UPDATE 202_users SET p202_customer_api_key = 'page-runner' WHERE user_id = " . self::USER);
        self::$sessions = sys_get_temp_dir() . '/p202-ltv-pages-' . getmypid();
        if (!is_dir(self::$sessions) && !mkdir(self::$sessions, 0700)) {
            throw new \RuntimeException('could not create ' . self::$sessions);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            if (self::$versions !== null) {
                self::q('DELETE FROM 202_version');
                foreach (self::$versions as $version) {
                    self::row('202_version', ['version' => $version]);
                }
            }
        }
        if (self::$sessions !== '' && is_dir(self::$sessions)) {
            array_map('unlink', glob(self::$sessions . '/sess_*') ?: []);
            rmdir(self::$sessions);
        }
    }

    protected function setUp(): void
    {
        self::requireScratch();
        $u = self::USER;
        foreach (self::LTV_TABLES as $table) {
            self::q("DELETE FROM $table WHERE user_id = $u");
        }
        $t = time();
        $companies = [self::KEPT_COMPANY => ['Acme', 'acme.example'], self::GONE_COMPANY => ['Beta', null]];
        foreach ($companies as $id => [$name, $domain]) {
            self::row('202_companies', [
                'company_id' => $id, 'user_id' => $u, 'name' => $name, 'normalized_name' => strtolower($name),
                'domain' => $domain, 'created_at' => $t, 'updated_at' => $t,
            ]);
        }
        foreach ([self::KEPT_CUSTOMER => 'Ada', self::GONE_CUSTOMER => 'Bob'] as $id => $first) {
            self::row('202_customers', [
                'customer_id' => $id, 'user_id' => $u, 'primary_ref' => 'ref-' . $id, 'first_name' => $first,
                'email' => strtolower($first) . '@example.com',
                'company' => $id === self::KEPT_CUSTOMER ? 'Acme' : null,
                'company_id' => $id === self::KEPT_CUSTOMER ? self::KEPT_COMPANY : null,
                'first_seen_time' => $t, 'last_activity_time' => $t, 'created_at' => $t, 'updated_at' => $t,
            ]);
        }
        self::q('INSERT INTO 202_customer_aliases SET alias_id = ' . self::ALIAS . ", user_id = $u, customer_id = "
            . self::KEPT_CUSTOMER . ", alias_type = 'custom', alias_value = 'crm-1',"
            . " alias_hash = UNHEX(SHA2('crm-1', 256)), created_at = $t");
        foreach ([self::PRODUCT => 'Widget', self::OTHER_PRODUCT => 'Gadget'] as $id => $name) {
            self::row('202_products', [
                'product_id' => $id, 'user_id' => $u, 'external_product_id' => 'sku-' . $id, 'name' => $name,
                'created_at' => $t, 'updated_at' => $t,
            ]);
        }
    }

    public function testACompanyMergeReadsBothIdsExactly(): void
    {
        $merge = ['action' => 'merge_company'];
        $untouched = fn (): bool => self::rows('202_companies', 'company_id IN ('
            . self::KEPT_COMPANY . ', ' . self::GONE_COMPANY . ')') === 2
            && self::rows('202_customers', 'customer_id = ' . self::KEPT_CUSTOMER
                . ' AND company_id = ' . self::KEPT_COMPANY) === 1;

        $this->assertEachNotIdIsRefused(
            self::COMPANIES,
            $merge + ['source_company_id' => (string) self::GONE_COMPANY],
            'company_id',
            self::KEPT_COMPANY,
            'Pick the company to keep from the list.',
            $untouched
        );
        $this->assertEachNotIdIsRefused(
            self::COMPANIES,
            $merge + ['company_id' => (string) self::KEPT_COMPANY],
            'source_company_id',
            self::GONE_COMPANY,
            'Pick the company to merge away from the list.',
            $untouched
        );

        $page = self::post(self::COMPANIES, $merge + [
            'company_id' => (string) self::KEPT_COMPANY, 'source_company_id' => (string) self::GONE_COMPANY,
        ]);
        self::assertStringContainsString('Companies merged.', $page);
        self::assertSame(0, self::rows('202_companies', 'company_id = ' . self::GONE_COMPANY), $page);
        self::assertSame(1, self::rows('202_companies', 'company_id = ' . self::KEPT_COMPANY), 'the one kept');
    }

    public function testACompanyIsEditedAndDeletedOnlyByItsExactId(): void
    {
        $beta = fn (): bool => self::rows(
            '202_companies',
            'company_id = ' . self::GONE_COMPANY . " AND name = 'Beta'"
        ) === 1;
        $this->assertEachNotIdIsRefused(
            self::COMPANIES,
            ['action' => 'update_company', 'company_name' => 'Renamed', 'company_domain' => ''],
            'company_id',
            self::GONE_COMPANY,
            'Pick the company to edit from the list.',
            $beta
        );
        $this->assertEachNotIdIsRefused(
            self::COMPANIES,
            ['action' => 'delete_company'],
            'company_id',
            self::GONE_COMPANY,
            'Pick the company to delete from the list.',
            $beta
        );

        $page = self::post(self::COMPANIES, ['edit' => self::GONE_COMPANY . 'abc']);
        self::assertStringContainsString('Pick the company to edit from the list.', $page);
        self::assertStringNotContainsString('id="ltv-company-row-', $page, 'no row is opened for an id nobody named');
        $page = self::post(self::COMPANIES, ['edit' => (string) self::GONE_COMPANY]);
        self::assertStringContainsString('id="ltv-company-row-' . self::GONE_COMPANY . '"', $page);
        self::assertStringNotContainsString('Pick the company', $page);

        $page = self::post(self::COMPANIES, [
            'action' => 'update_company', 'company_id' => (string) self::GONE_COMPANY,
            'company_name' => ['Renamed'], 'company_domain' => '',
        ]);
        self::assertStringContainsString('The form sent "company_name" as a list; it takes one value.', $page);
        self::assertTrue($beta(), 'a name sent as a list renamed nothing');

        self::assertStringContainsString('Company deleted.', self::post(self::COMPANIES, [
            'action' => 'delete_company', 'company_id' => (string) self::GONE_COMPANY,
        ]));
        self::assertSame(0, self::rows('202_companies', 'company_id = ' . self::GONE_COMPANY));
        self::assertSame(1, self::rows('202_companies', 'company_id = ' . self::KEPT_COMPANY), 'only the one named');
    }

    public function testACustomerMergeReadsBothIdsExactly(): void
    {
        $merge = ['action' => 'merge'];
        $untouched = fn (): bool => self::rows('202_customers', 'merged_into_customer_id IS NULL') === 2;

        $this->assertEachNotIdIsRefused(
            self::CUSTOMER,
            $merge + ['source_customer_id' => (string) self::GONE_CUSTOMER],
            'customer_id',
            self::KEPT_CUSTOMER,
            self::NO_CUSTOMER,
            $untouched
        );
        $this->assertEachNotIdIsRefused(
            self::CUSTOMER,
            $merge + ['customer_id' => (string) self::KEPT_CUSTOMER],
            'source_customer_id',
            self::GONE_CUSTOMER,
            'Pick the customer to merge away from the list.',
            $untouched
        );

        $page = self::post(self::CUSTOMER, $merge + [
            'customer_id' => (string) self::KEPT_CUSTOMER, 'source_customer_id' => (string) self::GONE_CUSTOMER,
        ]);
        self::assertStringContainsString('Customer updated.', $page);
        self::assertSame(1, self::rows(
            '202_customers',
            'customer_id = ' . self::GONE_CUSTOMER . ' AND merged_into_customer_id = ' . self::KEPT_CUSTOMER
        ), $page);
    }

    /**
     * Every action the customer page takes acts on the customer its
     * customer_id names, so each one refuses an id that is not exactly one.
     */
    public function testEveryCustomerActionReadsTheCustomerIdExactly(): void
    {
        $untouched = fn (): bool => self::rows('202_customers', 'customer_id = ' . self::KEPT_CUSTOMER
            . " AND first_name = 'Ada' AND email = 'ada@example.com'") === 1
            && self::rows('202_customer_aliases', 'customer_id = ' . self::KEPT_CUSTOMER) === 1
            && self::rows('202_customer_aliases', 'alias_id = ' . self::ALIAS) === 1;

        $actions = [
            ['action' => 'erase'],
            ['action' => 'add_alias', 'alias_type' => 'custom', 'alias_value' => 'crm-2'],
            ['action' => 'delete_alias', 'alias_id' => (string) self::ALIAS],
            ['action' => 'save', 'crm' => ['first_name' => 'Eve'] + self::CRM, 'email' => 'eve@example.com'],
        ];
        foreach ($actions as $post) {
            $this->assertEachNotIdIsRefused(
                self::CUSTOMER,
                $post,
                'customer_id',
                self::KEPT_CUSTOMER,
                self::NO_CUSTOMER,
                $untouched
            );
        }

        $page = self::get(['customer_id' => self::KEPT_CUSTOMER . 'abc']);
        self::assertStringContainsString(self::NO_CUSTOMER, $page, 'a link read the same way');
        self::assertStringNotContainsString('Ada', $page);
        self::assertStringContainsString('Ada', self::get(['customer_id' => (string) self::KEPT_CUSTOMER]));

        $page = self::post(self::CUSTOMER, ['action' => 'erasee', 'customer_id' => (string) self::KEPT_CUSTOMER]);
        self::assertStringContainsString('Unknown action.', $page);
        $page = self::post(self::CUSTOMER, ['action' => ['erase'], 'customer_id' => (string) self::KEPT_CUSTOMER]);
        self::assertStringContainsString('Unknown action.', $page);
        $page = self::post(self::CUSTOMER, ['mode' => ['edit'], 'customer_id' => (string) self::KEPT_CUSTOMER]);
        self::assertStringContainsString('The form sent "mode" as a list; it takes one value.', $page);
        self::assertTrue($untouched(), 'a refused action changed the customer');

        $page = self::post(self::CUSTOMER, ['action' => 'erase', 'customer_id' => (string) self::KEPT_CUSTOMER]);
        self::assertStringContainsString('Customer erased', $page);
        self::assertSame(1, self::rows('202_customers', 'customer_id = ' . self::KEPT_CUSTOMER
            . ' AND first_name IS NULL'));
        self::assertSame(1, self::rows('202_customers', 'customer_id = ' . self::GONE_CUSTOMER
            . " AND first_name = 'Bob'"));
    }

    public function testAnIdentityIsUnlinkedOnlyByItsExactIdAndLinkedOnlyFromText(): void
    {
        $linked = fn (): bool => self::rows('202_customer_aliases', 'alias_id = ' . self::ALIAS) === 1
            && self::rows('202_customer_aliases', '1') === 1;
        $this->assertEachNotIdIsRefused(
            self::CUSTOMER,
            ['action' => 'delete_alias', 'customer_id' => (string) self::KEPT_CUSTOMER],
            'alias_id',
            self::ALIAS,
            'Pick the identity to unlink from the list.',
            $linked
        );

        $page = self::post(self::CUSTOMER, [
            'action' => 'add_alias', 'customer_id' => (string) self::KEPT_CUSTOMER,
            'alias_type' => 'custom', 'alias_value' => ['crm-2'],
        ]);
        self::assertStringContainsString('The form sent "alias_value" as a list; it takes one value.', $page);
        self::assertTrue($linked(), 'no identity "Array" was linked');

        $page = self::post(self::CUSTOMER, [
            'action' => 'delete_alias', 'customer_id' => (string) self::KEPT_CUSTOMER,
            'alias_id' => (string) self::ALIAS,
        ]);
        self::assertStringContainsString('Customer updated.', $page);
        self::assertSame(0, self::rows('202_customer_aliases', '1'), $page);
    }

    /**
     * The edit form posts every field, and an emptied one clears what is
     * stored: a group sent as one value was read as every field emptied, a
     * field left out as that one emptied, and a list as "Array".
     */
    public function testTheCustomerFormIsSavedOnlyAsTheFormSendsIt(): void
    {
        $save = ['action' => 'save', 'customer_id' => (string) self::KEPT_CUSTOMER, 'email' => 'eve@example.com'];
        $without = self::CRM;
        unset($without['phone']);
        $refusals = [
            'The form sent "crm" as one value; it takes a group of fields.' => ['crm' => 'Eve'] + $save,
            'The form sent "crm[first_name]" as a list; it takes one value.'
                => ['crm' => ['first_name' => ['Eve']] + self::CRM] + $save,
            'The form left out "crm[first_name]"; nothing was saved.' => $save,
            'The form left out "crm[phone]"; nothing was saved.' => ['crm' => $without] + $save,
            'The form sent "crm[frist_name]", which is no customer field.'
                => ['crm' => self::CRM + ['frist_name' => 'Eve']] + $save,
            'The form left out "email"; nothing was saved.' => ['action' => 'save',
                'customer_id' => (string) self::KEPT_CUSTOMER, 'crm' => self::CRM],
            'The form sent "email" as a list; it takes one value.' => ['email' => ['eve@example.com']] + $save
                + ['crm' => self::CRM],
            'The form sent "cf" as one value; it takes a group of fields.' => ['cf' => 'x', 'crm' => self::CRM] + $save,
        ];
        foreach ($refusals as $sentence => $post) {
            $page = self::post(self::CUSTOMER, $post);
            self::assertStringContainsString($sentence, $page);
            self::assertSame(1, self::rows('202_customers', 'customer_id = ' . self::KEPT_CUSTOMER
                . " AND first_name = 'Ada' AND email = 'ada@example.com'"), $sentence . ' changed the customer');
        }

        // The form drawn again shows what was typed, and the stored value
        // where nothing usable was: never the group's one value in each field.
        $page = self::post(self::CUSTOMER, ['crm' => 'Eve'] + $save);
        self::assertStringContainsString('name="crm[first_name]" maxlength="100" value="Ada"', $page);
        self::assertStringNotContainsString('value="Eve"', $page);
        $page = self::post(
            self::CUSTOMER,
            ['crm' => ['first_name' => ['Eve'], 'last_name' => 'Byron'] + self::CRM] + $save
        );
        self::assertStringContainsString('name="crm[first_name]" maxlength="100" value="Ada"', $page);
        self::assertStringContainsString('name="crm[last_name]" maxlength="100" value="Byron"', $page);

        $page = self::post(self::CUSTOMER, ['crm' => ['first_name' => 'Eve', 'phone' => '555'] + self::CRM] + $save);
        self::assertStringContainsString('Customer updated.', $page);
        self::assertSame(1, self::rows('202_customers', 'customer_id = ' . self::KEPT_CUSTOMER
            . " AND first_name = 'Eve' AND phone = '555' AND email = 'eve@example.com'"), $page);
    }

    public function testAProductIsSavedAndDeletedOnlyByItsExactId(): void
    {
        $untouched = fn (): bool => self::rows('202_products', 'product_id = ' . self::PRODUCT
            . " AND name = 'Widget'") === 1
            && self::rows('202_products', 'product_id = ' . self::OTHER_PRODUCT . " AND name = 'Gadget'") === 1;
        $this->assertEachNotIdIsRefused(
            self::PRODUCTS,
            ['action' => 'save_product', 'product_name' => 'Renamed', 'product_sku' => '', 'product_price' => ''],
            'product_id',
            self::PRODUCT,
            'Pick the product to edit from the list.',
            $untouched
        );
        $this->assertEachNotIdIsRefused(
            self::PRODUCTS,
            ['action' => 'delete_product'],
            'product_id',
            self::OTHER_PRODUCT,
            'Pick the product to delete from the list.',
            $untouched
        );

        $page = self::post(self::PRODUCTS, [
            'action' => 'save_product', 'product_id' => (string) self::PRODUCT,
            'product_name' => 'Renamed', 'product_sku' => ['x'], 'product_price' => '',
        ]);
        self::assertStringContainsString('The form sent "product_sku" as a list; it takes one value.', $page);
        self::assertTrue($untouched(), 'a SKU sent as a list saved nothing');

        $page = self::post(self::PRODUCTS, ['edit' => self::PRODUCT . 'abc']);
        self::assertStringContainsString('Pick the product to edit from the list.', $page);
        self::assertStringNotContainsString('id="ltv-product-row-', $page, 'no row is opened for an id nobody named');
        $page = self::post(self::PRODUCTS, ['edit' => (string) self::PRODUCT]);
        self::assertStringContainsString('id="ltv-product-row-' . self::PRODUCT . '"', $page);
        self::assertStringNotContainsString('Pick the product', $page);

        self::assertStringContainsString('Product updated.', self::post(self::PRODUCTS, [
            'action' => 'save_product', 'product_id' => (string) self::PRODUCT,
            'product_name' => 'Renamed', 'product_sku' => '', 'product_price' => '',
        ]));
        self::assertSame(1, self::rows('202_products', 'product_id = ' . self::PRODUCT . " AND name = 'Renamed'"));
        self::assertStringContainsString('Product deleted.', self::post(self::PRODUCTS, [
            'action' => 'delete_product', 'product_id' => (string) self::OTHER_PRODUCT,
        ]));
        self::assertSame(0, self::rows('202_products', 'product_id = ' . self::OTHER_PRODUCT));
        self::assertSame(1, self::rows('202_products', 'product_id = ' . self::PRODUCT), 'only the one named');
    }

    /**
     * Every list reads its page as the pager writes it, says so of anything
     * else, and a request naming a page nobody can name runs no action.
     */
    public function testAPageNobodyCanNameIsSaidAndRunsNothing(): void
    {
        foreach ([self::COMPANIES, self::PRODUCTS, self::SUBSCRIPTIONS, self::REPORT] as $page) {
            foreach (self::NOT_OFFSETS as $offset) {
                self::assertStringContainsString(
                    self::NO_PAGE,
                    self::post($page, ['offset' => $offset]),
                    $page . ' ' . var_export($offset, true)
                );
            }
            foreach (['0', '', '50'] as $offset) {
                self::assertStringNotContainsString(self::NO_PAGE, self::post($page, ['offset' => $offset]), $page);
            }
            self::assertStringNotContainsString(self::NO_PAGE, self::post($page, []), $page . ' with no offset');
        }

        $page = self::post(self::COMPANIES, [
            'action' => 'delete_company', 'company_id' => (string) self::GONE_COMPANY, 'offset' => '50abc',
        ]);
        self::assertStringContainsString(self::NO_PAGE, $page);
        self::assertStringNotContainsString('Company deleted.', $page);
        self::assertSame(1, self::rows('202_companies', 'company_id = ' . self::GONE_COMPANY), 'nothing was deleted');
        $page = self::post(self::PRODUCTS, [
            'action' => 'delete_product', 'product_id' => (string) self::OTHER_PRODUCT, 'offset' => '-50',
        ]);
        self::assertStringContainsString(self::NO_PAGE, $page);
        self::assertSame(1, self::rows('202_products', 'product_id = ' . self::OTHER_PRODUCT), 'nothing was deleted');
    }

    public function testAFilterTheListDoesNotHaveIsSaid(): void
    {
        $refusals = [
            [self::REPORT, ['ltv_by' => 'compaign'], 'Pick what to group LTV by from the list.'],
            [self::REPORT, ['ltv_by' => ['campaign']], 'Pick what to group LTV by from the list.'],
            [self::REPORT, ['segment' => 'vip'], 'Pick a segment from the list.'],
            [self::REPORT, ['q' => ['Ada']], 'The form sent "q" as a list; it takes one value.'],
            [self::SUBSCRIPTIONS, ['status' => 'activ'], 'Pick a status from the list.'],
            [self::COMPANY, ['company' => ['Acme']], 'The form sent "company" as a list; it takes one value.'],
        ];
        foreach ($refusals as [$page, $post, $sentence]) {
            self::assertStringContainsString($sentence, self::post($page, $post), $page . ' ' . json_encode($post));
        }
        $accepted = [
            [self::REPORT, ['ltv_by' => 'product', 'segment' => 'repeat', 'q' => 'Ada']],
            [self::SUBSCRIPTIONS, ['status' => 'active']],
        ];
        foreach ($accepted as [$page, $post]) {
            self::assertStringNotContainsString('Pick ', self::post($page, $post), $page);
        }
    }

    /**
     * The merge dialog's search excludes the record it merges into: that id
     * is read exactly too, and a refusal is answered as one (with what to
     * pick, which the dialog shows), never as a list of matches.
     */
    public function testTheMergeSearchReadsTheRecordItMergesIntoExactly(): void
    {
        $search = ['entity' => 'company', 'q' => 'a'];
        $posts = [$search];
        foreach (self::NOT_IDS as $spelling) {
            $posts[] = $search + ['exclude' => is_array($spelling)
                ? [sprintf($spelling[0], self::KEPT_COMPANY)] : sprintf($spelling, self::KEPT_COMPANY)];
        }
        foreach ($posts as $post) {
            self::assertSame(
                ['results' => [], 'error' => 'Pick the record to merge into from the list.'],
                self::json(self::post(self::SEARCH, $post)),
                var_export($post['exclude'] ?? '(absent)', true)
            );
        }

        $found = self::json(self::post(self::SEARCH, $search + ['exclude' => (string) self::KEPT_COMPANY]));
        self::assertSame(
            [self::GONE_COMPANY],
            array_column($found['results'] ?? [], 'id'),
            'all but the one merged into'
        );

        foreach ([['entity' => 'compnay'] + $search, ['q' => ['a']] + $search] as $post) {
            $found = self::json(self::post(self::SEARCH, $post + ['exclude' => (string) self::KEPT_COMPANY]));
            self::assertSame([], $found['results'] ?? null);
            self::assertIsString($found['error'] ?? null, json_encode($post));
        }
    }

    /**
     * Every NOT_IDS spelling of $id in $field is refused with $sentence and
     * changes nothing ($untouched holds after each), and so is no id at all.
     *
     * @param array<string, mixed> $post the rest of the request, every other id exact
     */
    private function assertEachNotIdIsRefused(
        string $page,
        array $post,
        string $field,
        int $id,
        string $sentence,
        callable $untouched
    ): void {
        $posts = [$post];
        foreach (self::NOT_IDS as $spelling) {
            $value = is_array($spelling) ? [sprintf($spelling[0], $id)] : sprintf($spelling, $id);
            $posts[] = $post + [$field => $value];
        }
        self::assertTrue($untouched(), 'the records were not as seeded');
        foreach ($posts as $sent) {
            $what = basename($page) . ' ' . (string) ($sent['action'] ?? '') . ' ' . $field . '='
                . var_export($sent[$field] ?? '(absent)', true);
            self::assertStringContainsString($sentence, self::post($page, $sent), $what);
            self::assertTrue($untouched(), $what . ' changed the records');
        }
    }

    /** @param array<string, mixed> $fields the page's own token is added */
    private static function post(string $page, array $fields): string
    {
        return self::runPage($page, [], $fields + ['token' => 'SESSION']);
    }

    /** The customer page opened by a link: ?customer_id=… and no body. */
    private static function get(array $query): string
    {
        return self::runPage(self::CUSTOMER, $query, []);
    }

    /**
     * @param array<string, mixed> $get
     * @param array<string, mixed> $post
     */
    private static function runPage(string $page, array $get, array $post): string
    {
        self::requireScratch();
        $env = [
            'P202_TEST_REPORT_USER' => (string) self::USER,
            'P202_TEST_SESSION_DIR' => self::$sessions,
            'P202_TEST_GET' => (string) json_encode((object) $get),
            'P202_TEST_POST' => (string) json_encode((object) $post),
            'P202_TEST_AJAX' => '1',
        ] + getenv();
        $proc = proc_open(
            [
                PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=E_ALL & ~E_DEPRECATED',
                dirname(__DIR__) . '/Setup/fixtures/page-runner.php', $page,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env
        );
        self::assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        self::assertNotSame(2, $code, "the runner could not set up: $err");
        self::assertStringNotContainsString('Fatal error', $out . $err, $err);
        self::assertStringNotContainsString('Warning', $err, 'the page warned: ' . $err);
        self::assertStringNotContainsString('unavailable', $out, $page . ': ' . $err);

        return html_entity_decode($out, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** @return array<string, mixed> */
    private static function json(string $body): array
    {
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded, 'not JSON: ' . $body);

        return $decoded;
    }

    private static function rows(string $table, string $where): int
    {
        $found = self::$db->query("SELECT COUNT(*) FROM $table WHERE user_id = " . self::USER . " AND ($where)");
        self::assertInstanceOf(\mysqli_result::class, $found, (string) self::$db->error);

        return (int) $found->fetch_row()[0];
    }

    private static function cleanUp(): void
    {
        self::revokeLtvRole(self::USER, self::USER);
        $u = self::USER;
        foreach ([...self::LTV_TABLES, '202_users_pref', '202_users'] as $table) {
            self::q("DELETE FROM $table WHERE user_id = $u");
        }
    }
}
