<?php
declare(strict_types=1);

namespace Tests\Redirect;

use PHPUnit\Framework\TestCase;

/**
 * Enhanced test suite for dl.php redirect functionality
 * Tests core functionality, edge cases, and PHP 8 compatibility
 */
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
                return addslashes((string)$str);
            }
        } : null;
        
        // Mock memcache
        $memcache = new class($memcacheData) {
            private $data;
            public function __construct($data)
            {
                // Mock query results based on SQL
                if (strpos($sql, 'tracker_id_public') !== false) {
                    preg_match("/tracker_id_public='([^']+)'/", $sql, $matches);
                    $trackerId = $matches[1] ?? '0';
                    
                    return new class($this->trackerData[$trackerId] ?? false) {
                        private $data;
                        
                        public function __construct($data)
                        {
                            $this->data = $data;
                        }
                        
                        public function fetch_assoc()
                        {
                            return $this->data;
                        }
                    };
                }
                
                return new class {
                    public function fetch_assoc()
                    {
                        return [];
                    }
                };
            }
        };
    }
    
    private function createMockMemcache()
    {
        return new class {
            private $cache = [];
            
            public function get($key)
            {
                return $this->cache[$key] ?? false;
            }
            
            public function set($key, $value, $exp = 0)
            {
                $this->cache[$key] = $value;
                return true;
            }
            
            public function addToCache($key, $value)
            {
                $this->cache[$key] = $value;
            }
        };
    }

    private function simulateDlLogic(array $get, bool $dbWorking = true, array $memcacheData = []): array
    {
        $output = '';
        $this->capturedHeaders = [];
        
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
            
            if ($usedCachedRedirect && !$memcacheWorking) {
                $output = "<h2>Error establishing a database connection - please contact the webhost</h2>";
                return ['headers' => $this->capturedHeaders, 'output' => $output, 'died' => true];
            }
            
            // Database is working, fetch tracker
            $tracker_sql = "SELECT * FROM 202_trackers WHERE tracker_id_public='" . $db->real_escape_string($t202id) . "'";
            $tracker_result = $db->query($tracker_sql);
            $tracker_row = $tracker_result->fetch_assoc();
            
            if (!$tracker_row) {
                return ['headers' => $this->capturedHeaders, 'output' => '', 'died' => true];
            }
            
            // Process redirect
            $redirect_url = $tracker_row['aff_campaign_url'];
            $redirect_url = str_replace('[[subid]]', (string)$db->insert_id, $redirect_url);
            
            $this->capturedHeaders[] = 'Location: ' . $redirect_url;
            
        } catch (\Exception $e) {
            $output = 'Error: ' . $e->getMessage();
        }
        
        return ['headers' => $this->capturedHeaders, 'output' => $output, 'died' => false];
    }

    /**
     * Test invalid tracker ID causes script to die
     */
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

    /**
     * Test missing tracker ID causes script to die
     */
    public function testMissingIdDies(): void
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

    /**
     * Test tracker not found
     */
    public function testTrackerNotFound(): void
    {
        $result = $this->simulateDlLogic(['t202id' => '999'], true);
        $this->assertTrue($result['died']);
        $this->assertEmpty($result['output']);
        $this->assertEmpty($result['headers']);
    }

    /**
     * Test valid tracker redirect
     */
    public function testValidTrackerRedirect(): void
    {
        $result = $this->simulateDlLogic(['t202id' => '123'], true);
        $this->assertFalse($result['died']);
        $this->assertNotEmpty($result['headers']);
        
        $location = implode("\n", $result['headers']);
        $this->assertStringContainsString('Location: https://example.com/offer?subid=50', $location);
    }

    /**
     * Test special characters in parameters are escaped
     */
    public function testSpecialCharacterEscaping(): void
    {
        $url = 'http://example.com/?c1=[[c1]]';
        $key = md5('url_1hash');
        
        $result = $this->simulateDlLogic(
            [
                't202id' => '1',
                'c1' => "test'\"<>&"
            ],
            false,  // Database not working for cached test
            [$key => $url]
        );
        
        $location = implode("\n", $result['headers']);
        // Check that quotes are escaped
        $this->assertStringContainsString("test\\'\\\"<>&", $location);
    }

    /**
     * Test numeric t202id edge cases
     */
    public function testNumericEdgeCases(): void
    {
        $testCases = [
            '0' => true,      // Zero is numeric
            '123' => true,    // Normal number
            '-1' => true,     // Negative is numeric
            '1.5' => true,    // Float is numeric
            '1e5' => true,    // Scientific notation
            '' => false,      // Empty string
            'abc' => false,   // Letters
            '123abc' => false // Mixed
        ];
        
        foreach ($testCases as $id => $shouldPass) {
            $result = $this->simulateDlLogic(['t202id' => $id], false);
            
            if ($shouldPass) {
                // Should get to database error since no DB
                $this->assertStringContainsString('Error establishing', $result['output']);
            } else {
                // Should die immediately
                $this->assertTrue($result['died']);
                $this->assertEmpty($result['output']);
            }
        }
    }
}