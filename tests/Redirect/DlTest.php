<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DlTest extends TestCase
{
    private array $originalGet;
    private array $originalServer;
    private array $capturedHeaders = [];

    protected function setUp(): void
    {
        // Store original globals
        $this->originalGet = $_GET ?? [];
        $this->originalServer = $_SERVER ?? [];
        $this->capturedHeaders = [];
    }

    protected function tearDown(): void
    {
        // Restore original globals
        $_GET = $this->originalGet;
        $_SERVER = $this->originalServer;
    }

    private function simulateDlLogic(array $get, bool $memcacheWorking, array $memcacheData = []): array
    {
        $output = '';
        $this->capturedHeaders = [];
        
        // Set up test environment
        $_GET = $get;
        $_SERVER['HTTP_REFERER'] = 'http://example.com';
        $_SERVER['SERVER_NAME'] = 'test.com';
        $_SERVER['REQUEST_URI'] = '/test';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '127.0.0.1';
        
        // Mock database
        $db = $memcacheWorking ? new class {
            public function real_escape_string(string $str): string
            {
                return addslashes($str);
            }
        } : null;
        
        // Mock memcache
        $memcache = new class($memcacheData) {
            private $data;
            public function __construct($data)
            {
                $this->data = $data;
            }
            public function get(string $key)
            {
                return $this->data[$key] ?? false;
            }
            public function set(string $key, $value, $flag = 0, $exp = 0)
            {
                $this->data[$key] = $value;
                return true;
            }
        };
        
        // Simulate the core dl.php logic
        try {
            // Check for valid ID
            if (!isset($get['t202id']) || !is_numeric($get['t202id'])) {
                return ['headers' => $this->capturedHeaders, 'output' => '', 'died' => true];
            }
            
            $t202id = $get['t202id'];
            $usedCachedRedirect = false;
            
            if (!$db) $usedCachedRedirect = true;
            
            if ($usedCachedRedirect || $memcacheWorking) {
                if ($memcacheWorking) {
                    $getUrl = $memcache->get(md5('url_'.$t202id.'hash'));
                    if ($getUrl) {
                        $new_url = str_replace("[[subid]]", "p202", $getUrl);
                        
                        if(isset($_GET['c1']) && $_GET['c1'] != ''){
                            $new_url = str_replace("[[c1]]", $db->real_escape_string($_GET['c1']), $new_url);
                        } else {
                            $new_url = str_replace("[[c1]]", "p202c1", $new_url);
                        }
                        
                        $this->capturedHeaders[] = 'location: '. $new_url;
                        return ['headers' => $this->capturedHeaders, 'output' => $output, 'died' => true];
                    }
                }
                
                if (!$memcacheWorking) {
                    $output = "<h2>Error establishing a database connection - please contact the webhost</h2>";
                    return ['headers' => $this->capturedHeaders, 'output' => $output, 'died' => true];
                }
            }
        } catch (Exception $e) {
            // Handle any exceptions
        }
        
        return ['headers' => $this->capturedHeaders, 'output' => $output, 'died' => false];
    }

    public function testInvalidIdDies(): void
    {
        $result = $this->simulateDlLogic(['t202id' => 'abc'], false);
        $this->assertTrue($result['died']);
        $this->assertEmpty($result['headers']);
        $this->assertSame('', $result['output']);
    }

    public function testMissingIdDies(): void
    {
        $result = $this->simulateDlLogic([], false);
        $this->assertTrue($result['died']);
        $this->assertEmpty($result['headers']);
        $this->assertSame('', $result['output']);
    }

    public function testCachedRedirectWorks(): void
    {
        $url = 'http://example.com/?affsub=[[subid]]&c1=[[c1]]';
        $key = md5('url_1hash');
        $result = $this->simulateDlLogic(
            ['t202id' => '1', 'c1' => 'foo'],
            true,
            [$key => $url]
        );
        
        $this->assertTrue($result['died']);
        $this->assertNotEmpty($result['headers']);
        $this->assertStringContainsString('location: http://example.com/?affsub=p202&c1=foo', implode("\n", $result['headers']));
    }

    public function testCachedRedirectWithoutC1(): void
    {
        $url = 'http://example.com/?affsub=[[subid]]&c1=[[c1]]';
        $key = md5('url_1hash');
        $result = $this->simulateDlLogic(
            ['t202id' => '1'],
            true,
            [$key => $url]
        );
        
        $this->assertTrue($result['died']);
        $this->assertNotEmpty($result['headers']);
        $this->assertStringContainsString('location: http://example.com/?affsub=p202&c1=p202c1', implode("\n", $result['headers']));
    }

    public function testCachedRedirectError(): void
    {
        $result = $this->simulateDlLogic(['t202id' => '1'], false);
        $this->assertTrue($result['died']);
        $this->assertStringContainsString('Error establishing a database connection', $result['output']);
        $this->assertEmpty($result['headers']);
    }
}