<?php

declare(strict_types=1);

namespace Api\V3\Support;

use Prosper202\Ltv\MysqlCustomerRepository;

/**
 * The values of the objects nested in the LTV writes, read strictly at the
 * write boundary: a line item (POST /conversions, POST /ltv/revenue), a
 * catalog product (a line item's product, POST /ltv/products), the CRM
 * fields that create or describe a customer (`customer_crm`, and a
 * customer's own record fields) and a customer alias.
 *
 * MysqlCustomerRepository read these by hand: the keys it knew, each cast.
 * A unit price of "abc" was stored as 0, a misspelled `unit_pirce` stored no
 * price and a line amount of 0, `qty` for `quantity` stored one unit, and a
 * country of "United States" was a 500 in strict mode and "Un" without it
 * (CLAUDE.md #4). The handlers now hand each object to
 * PayloadKeys::objectErrors() / listErrors() with the keys below and one of
 * these methods, which answers the errors of its values keyed by the
 * object's own keys; PayloadKeys puts the field in front (`items.0.amount`)
 * and refuses the keys that are not listed.
 *
 * The ranges are the columns': a value past one was clamped or cut without a
 * word. The repository keeps its own floor (a product needs a key, a
 * quantity is positive), for the static pixel endpoints that write line
 * items without this check.
 */
final class LtvBody
{
    /** What MysqlCustomerRepository::insertLineItems() and upsertProduct() read from a line item. */
    public const LINE_ITEM_KEYS = ['external_product_id', 'sku', 'name', 'quantity', 'unit_price', 'amount', 'price'];

    /** What MysqlCustomerRepository::upsertProduct() reads. */
    public const PRODUCT_KEYS = ['external_product_id', 'sku', 'name', 'price'];

    /**
     * The CRM columns of 202_customers a customer is created or updated with
     * (MysqlCustomerRepository::insertCustomer(), MysqlCustomerCrmRepository::
     * applyCrmFields()), each with its column's length.
     */
    public const CRM_MAX_LENGTH = [
        'first_name' => 100, 'last_name' => 100, 'email' => 255, 'phone' => 50, 'company' => 255,
        'address_line1' => 255, 'address_line2' => 255, 'city' => 100, 'region' => 100, 'postal_code' => 20,
        'country' => 2,
    ];

    /** What MysqlCustomerCrmRepository::upsert() reads from each of a customer's `aliases`. */
    public const ALIAS_KEYS = ['type', 'value'];

    /**
     * Each number's range, [min, max, as a message names it]: its column's.
     * price is decimal(14,5) (202_products) and 0 or more, as PUT
     * /ltv/products/{id} reads it; unit_price decimal(14,5) and amount
     * decimal(16,5) (202_revenue_line_items) keep either sign, as the
     * repository does (a negative event stores every line negative, whichever
     * sign was sent); quantity is decimal(12,3) and positive, and less than
     * its smallest step would be stored as 0. The text is spelled out: PHP
     * prints 99999999999.99999 as 100000000000.
     */
    private const RANGES = [
        'price' => [0.0, 999999999.99999, '0 to 999999999.99999'],
        'quantity' => [0.001, 999999999.999, '0.001 to 999999999.999'],
        'unit_price' => [-999999999.99999, 999999999.99999, '-999999999.99999 to 999999999.99999'],
        'amount' => [-99999999999.99999, 99999999999.99999, '-99999999999.99999 to 99999999999.99999'],
    ];

    /** varchar(191): 202_products.external_product_id and sku. */
    private const PRODUCT_KEY_MAX = 191;

    private function __construct()
    {
    }

    /**
     * The CRM keys, as PayloadKeys takes them.
     *
     * @return list<string>
     */
    public static function crmKeys(): array
    {
        return array_keys(self::CRM_MAX_LENGTH);
    }

    /**
     * A catalog product's values: its key (external_product_id, or a sku,
     * which then becomes the key as `sku:<sku>`), its name and its list
     * price (0 or more, as PUT /ltv/products/{id} reads it).
     *
     * @param array<array-key, mixed> $product
     * @return array<string, string>
     */
    public static function product(array $product): array
    {
        $errors = [];
        $external = self::text($product, 'external_product_id', self::PRODUCT_KEY_MAX, $errors);
        // Without an external id the sku is the key, stored as "sku:<sku>"
        // in the same 191 characters.
        $skuMax = $external === '' ? self::PRODUCT_KEY_MAX - 4 : self::PRODUCT_KEY_MAX;
        $about = $external === '' ? ' without an external_product_id (the product is then keyed sku:<sku>)' : '';
        $sku = self::text($product, 'sku', $skuMax, $errors, $about);
        self::text($product, 'name', 255, $errors);
        self::number($product, 'price', $errors);
        if ($external === '' && $sku === '' && !isset($errors['external_product_id']) && !isset($errors['sku'])) {
            $errors['external_product_id'] = 'is required when there is no sku: '
                . 'the product is found, or created, by one of them';
        }

        return $errors;
    }

    /**
     * A line item's values: its product's (product()), a quantity, a unit
     * price and an amount (RANGES). An amount left out is unit_price times
     * quantity, which must fit the column too.
     *
     * @param array<array-key, mixed> $item
     * @return array<string, string>
     */
    public static function lineItem(array $item): array
    {
        $errors = self::product($item);
        $quantity = self::number($item, 'quantity', $errors);
        $unitPrice = self::number($item, 'unit_price', $errors);
        $amount = self::number($item, 'amount', $errors);
        $computed = $amount === null && $unitPrice !== null && !isset($errors['quantity'])
            ? abs($unitPrice * ($quantity ?? 1.0))
            : null;
        if ($computed !== null && $computed > self::RANGES['amount'][1]) {
            $errors['amount'] = 'is required here: unit_price × quantity is past what a line amount holds ('
                . self::RANGES['amount'][2] . ')';
        }

        return $errors;
    }

    /**
     * CRM field values: a string (or a whole number, read as its digits) no
     * longer than its column, or null for none; an email must be one.
     *
     * @param array<array-key, mixed> $crm
     * @return array<string, string>
     */
    public static function crm(array $crm): array
    {
        $errors = [];
        foreach (self::CRM_MAX_LENGTH as $key => $max) {
            $about = $key === 'country' ? ': a two-letter country code, e.g. US' : '';
            $text = self::text($crm, $key, $max, $errors, $about);
            if ($key === 'email' && $text !== '' && filter_var($text, FILTER_VALIDATE_EMAIL) === false) {
                $errors['email'] = 'must be an email address';
            }
        }

        return $errors;
    }

    /**
     * An alias: a value (the identifier, required) and a type, one of
     * MysqlCustomerRepository::ALIAS_TYPES (custom when left out); an email
     * digest must be one.
     *
     * @param array<array-key, mixed> $alias
     * @return array<string, string>
     */
    public static function alias(array $alias): array
    {
        $errors = [];
        $type = 'custom';
        if (array_key_exists('type', $alias) && $alias['type'] !== null) {
            $sent = is_string($alias['type']) ? strtolower(trim($alias['type'])) : null;
            if ($sent === null || ($sent !== '' && !in_array($sent, MysqlCustomerRepository::ALIAS_TYPES, true))) {
                $errors['type'] = 'must be one of: ' . implode(', ', MysqlCustomerRepository::ALIAS_TYPES);
            } elseif ($sent !== '') {
                $type = $sent;
            }
        }
        $value = self::text($alias, 'value', 255, $errors);
        if ($value === '' && !isset($errors['value'])) {
            $errors['value'] = 'is required: the identifier this alias maps to the customer';
        } elseif ($value !== '' && !isset($errors['type'])) {
            try {
                MysqlCustomerRepository::canonicalizeAliasValue($type, $value);
            } catch (\RuntimeException $e) {
                $errors['value'] = $e->getMessage();
            }
        }

        return $errors;
    }

    /**
     * $key as trimmed text, '' when absent, null or blank; a value that is
     * not a string or a whole number, or is longer than $max characters, is
     * an error (and reads as '').
     *
     * @param array<array-key, mixed> $object
     * @param array<string, string> $errors
     */
    private static function text(array $object, string $key, int $max, array &$errors, string $about = ''): string
    {
        if (!array_key_exists($key, $object) || $object[$key] === null) {
            return '';
        }
        $value = $object[$key];
        if (!is_string($value) && !is_int($value)) {
            $errors[$key] = 'must be a string' . $about;

            return '';
        }
        $text = trim((string) $value);
        // A company name is stored with its runs of whitespace collapsed
        // (MysqlCompanyRepository::canonicalName()), so that is what must fit.
        $length = mb_strlen($key === 'company' ? (string) preg_replace('/\s+/u', ' ', $text) : $text);
        if ($length > $max) {
            $errors[$key] = 'must be at most ' . $max . ' characters' . $about . ' (got ' . $length . ')';

            return '';
        }

        return $text;
    }

    /**
     * $key as a number in its range (RANGES): a JSON number or a numeric
     * string, finite. Absent or null is null; anything else is an error (and
     * null).
     *
     * @param array<array-key, mixed> $object
     * @param array<string, string> $errors
     */
    private static function number(array $object, string $key, array &$errors): ?float
    {
        [$min, $max, $range] = self::RANGES[$key];
        if (!array_key_exists($key, $object) || $object[$key] === null) {
            return null;
        }
        $value = $object[$key];
        $numeric = is_int($value) || is_float($value) || (is_string($value) && is_numeric($value));
        $number = $numeric ? (float) $value : null;
        if ($number === null || !is_finite($number) || $number < $min || $number > $max) {
            $errors[$key] = 'must be a number from ' . $range . ' (a JSON number, or a string holding one)';

            return null;
        }

        return $number;
    }
}
