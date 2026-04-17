<?php

declare(strict_types=1);

namespace Tests\Redirect;

use PHPUnit\Framework\TestCase;
use Tracking202\Redirect\RedirectHelper;

/**
 * Tests for RedirectHelper used by cl.php and dl.php.
 *
 * RedirectHelper validates and sanitizes URL parameters from $_GET.
 * If these break, redirects fail or produce XSS/injection vulnerabilities.
 */
final class RedirectHelperTest extends TestCase
{
    private array $originalGet = [];

    protected function setUp(): void
    {
        $this->originalGet = $_GET;
        $_GET = [];
    }

    protected function tearDown(): void
    {
        $_GET = $this->originalGet;
    }

    // --- getIntParam ---

    public function testGetIntParamReturnsIntForValidValue(): void
    {
        $_GET['pci'] = '12345';
        self::assertSame(12345, RedirectHelper::getIntParam('pci'));
    }

    public function testGetIntParamReturnsNullForMissingParam(): void
    {
        self::assertNull(RedirectHelper::getIntParam('pci'));
    }

    public function testGetIntParamReturnsNullForNonNumeric(): void
    {
        $_GET['pci'] = 'abc';
        self::assertNull(RedirectHelper::getIntParam('pci'));
    }

    public function testGetIntParamReturnsNullForFloat(): void
    {
        $_GET['pci'] = '12.5';
        self::assertNull(RedirectHelper::getIntParam('pci'));
    }

    public function testGetIntParamReturnsNullForEmpty(): void
    {
        $_GET['pci'] = '';
        self::assertNull(RedirectHelper::getIntParam('pci'));
    }

    public function testGetIntParamReturnsZeroForZero(): void
    {
        $_GET['pci'] = '0';
        self::assertSame(0, RedirectHelper::getIntParam('pci'));
    }

    public function testGetIntParamReturnsNegativeForNegative(): void
    {
        $_GET['pci'] = '-1';
        self::assertSame(-1, RedirectHelper::getIntParam('pci'));
    }

    public function testGetIntParamRejectsHexValues(): void
    {
        $_GET['pci'] = '0xFF';
        self::assertNull(RedirectHelper::getIntParam('pci'));
    }

    public function testGetIntParamRejectsScientificNotation(): void
    {
        $_GET['pci'] = '1e5';
        self::assertNull(RedirectHelper::getIntParam('pci'));
    }

    public function testGetIntParamRejectsSqlInjection(): void
    {
        $_GET['pci'] = "1' OR 1=1 --";
        self::assertNull(RedirectHelper::getIntParam('pci'));
    }

    // --- getStringParam ---

    public function testGetStringParamReturnsCleanString(): void
    {
        $_GET['202vars'] = 'key=value&other=data';
        self::assertSame('key=value&other=data', RedirectHelper::getStringParam('202vars'));
    }

    public function testGetStringParamReturnsNullForMissing(): void
    {
        self::assertNull(RedirectHelper::getStringParam('202vars'));
    }

    public function testGetStringParamTrimsWhitespace(): void
    {
        $_GET['202vars'] = '  hello  ';
        self::assertSame('hello', RedirectHelper::getStringParam('202vars'));
    }

    public function testGetStringParamReturnsNullForEmpty(): void
    {
        $_GET['202vars'] = '';
        // After trim, empty string is still returned (filter returns '')
        $result = RedirectHelper::getStringParam('202vars');
        self::assertSame('', $result);
    }
}
