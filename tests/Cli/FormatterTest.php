<?php

declare(strict_types=1);

namespace Tests\Cli;

use P202Cli\Formatter;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class FormatterTest extends TestCase
{
    private BufferedOutput $output;

    protected function setUp(): void
    {
        parent::setUp();
        // Use VERBOSITY_NORMAL and disable decoration so we don't get ANSI codes
        $this->output = new BufferedOutput(BufferedOutput::VERBOSITY_NORMAL, false);
    }

    // --- JSON output tests ---

    public function testOutputWithJsonTruePrettyPrintsJson(): void
    {
        $data = ['data' => ['id' => 1, 'name' => 'Test Campaign']];
        Formatter::output($this->output, $data, true);

        $result = $this->output->fetch();
        $decoded = json_decode($result, true);

        $this->assertIsArray($decoded);
        $this->assertSame(1, $decoded['data']['id']);
        $this->assertSame('Test Campaign', $decoded['data']['name']);
        // Pretty print has newlines
        $this->assertStringContainsString("\n", $result);
    }

    public function testOutputJsonPreservesSlashes(): void
    {
        $data = ['url' => 'https://example.com/path/to/resource'];
        Formatter::output($this->output, $data, true);

        $result = $this->output->fetch();
        // Unescaped slashes flag is used
        $this->assertStringContainsString('https://example.com/path/to/resource', $result);
        $this->assertStringNotContainsString('\\/', $result);
    }

    // --- Single record (key-value) tests ---

    public function testOutputWithSingleRecordRendersKeyValuePairs(): void
    {
        $data = [
            'data' => [
                'id' => 42,
                'name' => 'My Campaign',
                'status' => 'active',
            ],
        ];
        Formatter::output($this->output, $data);

        $result = $this->output->fetch();
        $this->assertStringContainsString('id:', $result);
        $this->assertStringContainsString('42', $result);
        $this->assertStringContainsString('name:', $result);
        $this->assertStringContainsString('My Campaign', $result);
        $this->assertStringContainsString('status:', $result);
        $this->assertStringContainsString('active', $result);
    }

    // --- List rendering tests ---

    public function testOutputWithListRendersTableWithHeaders(): void
    {
        $data = [
            'data' => [
                ['id' => 1, 'name' => 'Campaign A', 'clicks' => 100],
                ['id' => 2, 'name' => 'Campaign B', 'clicks' => 200],
            ],
        ];
        Formatter::output($this->output, $data);

        $result = $this->output->fetch();
        // Table headers
        $this->assertStringContainsString('id', $result);
        $this->assertStringContainsString('name', $result);
        $this->assertStringContainsString('clicks', $result);
        // Table data
        $this->assertStringContainsString('Campaign A', $result);
        $this->assertStringContainsString('Campaign B', $result);
        $this->assertStringContainsString('100', $result);
        $this->assertStringContainsString('200', $result);
    }

    public function testOutputWithListShowsPagination(): void
    {
        $data = [
            'data' => [
                ['id' => 1, 'name' => 'Item 1'],
                ['id' => 2, 'name' => 'Item 2'],
            ],
            'pagination' => [
                'offset' => 0,
                'limit' => 2,
                'total' => 10,
            ],
        ];
        Formatter::output($this->output, $data);

        $result = $this->output->fetch();
        $this->assertStringContainsString('1-2', $result);
        $this->assertStringContainsString('10', $result);
    }

    // --- Empty data tests ---

    public function testOutputWithEmptyDataArray(): void
    {
        $data = ['data' => []];
        Formatter::output($this->output, $data);

        $result = $this->output->fetch();
        $this->assertStringContainsString('No results found', $result);
    }

    // --- Nested data tests ---

    public function testOutputWithNestedData(): void
    {
        $data = [
            'data' => [
                'id' => 1,
                'settings' => [
                    'cloaking' => true,
                    'rotation' => false,
                ],
            ],
        ];
        Formatter::output($this->output, $data);

        $result = $this->output->fetch();
        $this->assertStringContainsString('id:', $result);
        $this->assertStringContainsString('settings:', $result);
        $this->assertStringContainsString('cloaking:', $result);
        $this->assertStringContainsString('rotation:', $result);
    }

    // --- renderTable tests ---

    public function testRenderTableTruncatesLongValues(): void
    {
        $longValue = str_repeat('A', 100);
        $rows = [
            ['id' => 1, 'description' => $longValue],
        ];
        Formatter::renderTable($this->output, $rows);

        $result = $this->output->fetch();
        // Should be truncated to 57 chars + '...'
        $this->assertStringContainsString(substr($longValue, 0, 57) . '...', $result);
        // Should NOT contain the full string
        $this->assertStringNotContainsString($longValue, $result);
    }

    public function testRenderTableWithShortValuesDoesNotTruncate(): void
    {
        $rows = [
            ['id' => 1, 'name' => 'Short value'],
        ];
        Formatter::renderTable($this->output, $rows);

        $result = $this->output->fetch();
        $this->assertStringContainsString('Short value', $result);
        $this->assertStringNotContainsString('...', $result);
    }

    public function testRenderTableWithEmptyRows(): void
    {
        Formatter::renderTable($this->output, []);
        $result = $this->output->fetch();
        $this->assertSame('', $result);
    }

    public function testRenderTableHandlesArrayValues(): void
    {
        $rows = [
            ['id' => 1, 'tags' => ['a', 'b', 'c']],
        ];
        Formatter::renderTable($this->output, $rows);

        $result = $this->output->fetch();
        // Array values are json_encoded
        $this->assertStringContainsString('["a","b","c"]', $result);
    }

    // --- renderKeyValue tests ---

    public function testRenderKeyValueHandlesNestedArrays(): void
    {
        $data = [
            'name' => 'Test',
            'details' => [
                'type' => 'campaign',
                'status' => 'active',
            ],
        ];
        Formatter::renderKeyValue($this->output, $data);

        $result = $this->output->fetch();
        $this->assertStringContainsString('name:', $result);
        $this->assertStringContainsString('Test', $result);
        $this->assertStringContainsString('details:', $result);
        $this->assertStringContainsString('type:', $result);
        $this->assertStringContainsString('campaign', $result);
        $this->assertStringContainsString('status:', $result);
        $this->assertStringContainsString('active', $result);
    }

    public function testRenderKeyValueHandlesIndexedArrays(): void
    {
        $data = [
            'items' => ['apple', 'banana', 'cherry'],
        ];
        Formatter::renderKeyValue($this->output, $data);

        $result = $this->output->fetch();
        $this->assertStringContainsString('items:', $result);
        $this->assertStringContainsString('- apple', $result);
        $this->assertStringContainsString('- banana', $result);
        $this->assertStringContainsString('- cherry', $result);
    }

    public function testRenderKeyValueHandlesNestedArrayOfObjects(): void
    {
        $data = [
            'rules' => [
                ['type' => 'country', 'value' => 'US'],
                ['type' => 'device', 'value' => 'mobile'],
            ],
        ];
        Formatter::renderKeyValue($this->output, $data);

        $result = $this->output->fetch();
        $this->assertStringContainsString('rules:', $result);
        $this->assertStringContainsString('[0]', $result);
        $this->assertStringContainsString('[1]', $result);
        $this->assertStringContainsString('type:', $result);
        $this->assertStringContainsString('country', $result);
        $this->assertStringContainsString('US', $result);
        $this->assertStringContainsString('device', $result);
        $this->assertStringContainsString('mobile', $result);
    }

    public function testRenderKeyValueWithPrefix(): void
    {
        $data = ['key' => 'value'];
        Formatter::renderKeyValue($this->output, $data, '  ');

        $result = $this->output->fetch();
        $this->assertStringContainsString('  key:', $result);
        $this->assertStringContainsString('value', $result);
    }

    // --- Generic object rendering (no 'data' key) ---

    public function testOutputWithGenericObjectRendersKeyValue(): void
    {
        $data = [
            'status' => 'ok',
            'version' => '1.0.0',
        ];
        Formatter::output($this->output, $data);

        $result = $this->output->fetch();
        $this->assertStringContainsString('status:', $result);
        $this->assertStringContainsString('ok', $result);
        $this->assertStringContainsString('version:', $result);
        $this->assertStringContainsString('1.0.0', $result);
    }

    public function testOutputSingleRecordWithPagination(): void
    {
        $data = [
            'data' => [
                'id' => 1,
                'name' => 'Sole Item',
            ],
            'pagination' => [
                'offset' => 0,
                'limit' => 1,
                'total' => 1,
            ],
        ];
        Formatter::output($this->output, $data);

        $result = $this->output->fetch();
        // Single record should render key-value, and pagination should follow
        $this->assertStringContainsString('id:', $result);
        $this->assertStringContainsString('Sole Item', $result);
        $this->assertStringContainsString('offset:', $result);
        $this->assertStringContainsString('limit:', $result);
        $this->assertStringContainsString('total:', $result);
    }

    public function testRenderTableHandlesNullValuesInRow(): void
    {
        $rows = [
            ['id' => 1, 'name' => 'Test', 'optional' => 'present'],
            ['id' => 2, 'name' => 'Test2'],
        ];
        Formatter::renderTable($this->output, $rows);

        $result = $this->output->fetch();
        // Second row missing 'optional' should not crash
        $this->assertStringContainsString('Test', $result);
        $this->assertStringContainsString('Test2', $result);
    }
}
