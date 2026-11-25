<?php

declare(strict_types=1);

namespace Tests\Ltv;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * Every statement that rewrites a customer's company is listed with who tells
 * the account's webhooks: customer.updated is how a subscriber hears of it,
 * and a company rename, a merge and the maintenance sweep rewrote customers'
 * company with no event while a save through the API queued one. A new writer
 * fails here until it says how its change is announced, or why it is not.
 *
 * Seen: an UPDATE of 202_customers whose SET, before its WHERE, assigns
 * company or company_id. Not seen: a write through a variable table name or
 * SQL assembled elsewhere, and the INSERT that creates a customer (a new
 * customer is announced by the save that makes it).
 */
final class CustomerCompanyWritesAreAnnouncedTest extends TestCase
{
    private const WRITE = '/\bUPDATE\s+`?202_customers`?\b(?:(?!\bWHERE\b).)*?\bSET\b'
        . '(?:(?!\bWHERE\b).)*?\b(?:\w+\.)?company(?:_id)?\s*=/is';

    /** file => [statements, who announces them] */
    private const WRITERS = [
        '202-config/Ltv/MysqlCompanyRepository.php' => [
            7,
            'attachCustomer() (2) is one customer\'s save or creation, announced by the save'
                . ' (LtvController::upsertCustomer(), patchCustomer()); update(), merge(), writeLinks() (2)'
                . ' and reconcileAttachedStrings() queue it in their transaction (announce())',
        ],
        '202-config/Ltv/MysqlCustomerCrmRepository.php' => [
            3,
            'merge(), erase() and a save\'s detach: LtvController::mergeCustomer(), deleteCustomer() and'
                . ' patchCustomer() queue it; the Customer page (tracking202/ajax/ltv_customer.php) does not yet',
        ],
    ];

    public function testEveryWriteOfACustomersCompanyIsListed(): void
    {
        $found = [];
        foreach (SourceScan::phpFiles() as $file => $source) {
            $count = SourceScan::countMatches(self::WRITE, $source, $file);
            if ($count > 0) {
                $found[$file] = $count;
            }
        }
        ksort($found);
        $listed = array_map(static fn (array $writer): int => $writer[0], self::WRITERS);
        ksort($listed);
        self::assertSame(
            $listed,
            $found,
            'A statement rewrites 202_customers.company where this list does not say who queues'
                . ' customer.updated for it. Queue it as MysqlCompanyRepository::announce() does (in the'
                . ' write\'s transaction) or as the API does (LtvController), and list the file with how.'
        );
    }

    public function testTheListedWritersQueueTheEvent(): void
    {
        $companies = self::read('202-config/Ltv/MysqlCompanyRepository.php');
        foreach (['update', 'merge', 'writeLinks', 'reconcileAttachedStrings'] as $method) {
            self::assertStringContainsString(
                '$this->announce(',
                self::body($companies, $method),
                "MysqlCompanyRepository::$method() no longer queues customer.updated for what it writes"
            );
        }
        self::assertStringContainsString(
            '->enqueueEach(',
            self::body($companies, 'announce'),
            'MysqlCompanyRepository::announce() no longer queues anything'
        );

        $controller = self::read('api/v3/Controllers/LtvController.php');
        foreach (['upsertCustomer', 'patchCustomer', 'mergeCustomer', 'deleteCustomer'] as $method) {
            self::assertStringContainsString(
                "enqueueEvent('customer.updated'",
                self::body($controller, $method),
                "LtvController::$method() no longer queues customer.updated"
            );
        }
    }

    public function testThePatternSeesEveryShapeTheWritersUse(): void
    {
        $shapes = [
            "UPDATE 202_customers SET company = ?, updated_at = ? WHERE company_id = ?",
            "UPDATE `202_customers` SET company_id = ?, company = ? WHERE customer_id = ?",
            "UPDATE 202_customers\n SET merged_into_customer_id = ?,\n company = NULL WHERE customer_id = ?",
            "UPDATE 202_customers c JOIN 202_companies co ON co.company_id = c.company_id\n"
                . " SET c.company = co.name WHERE c.company IS NULL",
        ];
        foreach ($shapes as $sql) {
            self::assertSame(1, SourceScan::countMatches(self::WRITE, $sql, 'shape'), $sql);
        }
        $others = [
            "UPDATE 202_customers SET mrr = ?, updated_at = ? WHERE company_id = ?",
            "UPDATE 202_customers SET first_name = ? WHERE company = ?",
            "UPDATE 202_companies SET name = ? WHERE company_id = ?",
        ];
        foreach ($others as $sql) {
            self::assertSame(0, SourceScan::countMatches(self::WRITE, $sql, 'shape'), $sql);
        }
    }

    private static function read(string $file): string
    {
        $source = file_get_contents(SourceScan::repoRoot() . '/' . $file);
        self::assertIsString($source, $file);

        return $source;
    }

    /** A method's text, from its declaration to the next one's. */
    private static function body(string $source, string $method): string
    {
        $start = strpos($source, 'function ' . $method . '(');
        self::assertIsInt($start, "no method $method");
        $declaration = '/\n    (?:public|private|protected) (?:static )?function /';
        $next = preg_match($declaration, $source, $m, PREG_OFFSET_CAPTURE, $start + 1);
        self::assertNotFalse($next);

        return substr($source, $start, $next === 1 ? $m[0][1] - $start : null);
    }
}
