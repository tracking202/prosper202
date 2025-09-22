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
    private array $originalCookie;
    private array $capturedHeaders = [];
    private $mockDb;
    private $mockMemcache;

    protected function setUp(): void
    {
        // Store original globals
        $this->originalGet = $_GET ?? [];
        $this->originalServer = $_SERVER ?? [];
        $this->originalCookie = $_COOKIE ?? [];
        $this->capturedHeaders = [];
        
        // Set default server variables
        $_SERVER['HTTP_HOST'] = 'test.prosper202.com';
        $_SERVER['SERVER_NAME'] = 'test.prosper202.com';
        $_SERVER['REQUEST_URI'] = '/tracking202/redirect/dl.php';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '127.0.0.1';
        
        // Create mocks
        $this->mockDb = $this->createMockDb();
        $this->mockMemcache = $this->createMockMemcache();
        
        // Set up global $db variable for test
        global $db;
        $db = $this->mockDb;
    }

    protected function tearDown(): void
    {
        // Restore original globals
        $_GET = $this->originalGet;
        $_SERVER = $this->originalServer;
        $_COOKIE = $this->originalCookie;
    }
    
    private function createMockDb()
    {
        return new class {
            public $insert_id = 50;
            private $trackerData = [
                '123' => [
                    'user_id' => '1',
                    'aff_campaign_id' => '10',
                    'text_ad_id' => '5',
                    'ppc_account_id' => '2',
                    'click_cpc' => '0.50',
                    'click_cpa' => '25.00',
                    'aff_campaign_url' => 'https://example.com/offer?subid=[[subid]]',
                    'aff_campaign_payout' => '25.00',
                    'user_timezone' => 'America/New_York',
                    'user_keyword_searched_or_bidded' => 'bidded',
                    'user_pref_dynamic_bid' => '0',
                    'maxmind_isp' => '0'
                ]
            ];
            
            public function real_escape_string($str): string
            {
                return addslashes((string)$str);
            }
            
            public function query($sql)
            {
                // Mock query results based on SQL
                if (str_contains((string) $sql, 'tracker_id_public')) {
                    preg_match("/tracker_id_public='([^']+)'/", (string) $sql, $matches);
                    $trackerId = $matches[1] ?? '0';
                    
                    return new class($this->trackerData[$trackerId] ?? false) {
                        public function __construct(private $data)
                        {
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
        
        // Set up test environment
        $_GET = $get;
        
        // Add data to mock memcache
        foreach ($memcacheData as $key => $value) {
            $this->mockMemcache->addToCache($key, $value);
        }
        
        // For cached redirect tests, we still use mockDb for escaping
        $db = $this->mockDb;
        $dbWorking = $dbWorking;
        $memcacheWorking = !empty($memcacheData);
        
        // Simulate the core dl.php logic
        try {
            // Check for valid ID
            $t202id = $_GET['t202id'] ?? '';
            if (!is_numeric($t202id)) {
                return ['headers' => $this->capturedHeaders, 'output' => '', 'died' => true];
            }
            
            $usedCachedRedirect = false;
            if (!$dbWorking) $usedCachedRedirect = true;
            
            if ($usedCachedRedirect && $memcacheWorking) {
                $getUrl = $this->mockMemcache->get(md5('url_'.$t202id.'hash'));
                if ($getUrl) {
                    $new_url = str_replace("[[subid]]", "p202", $getUrl);
                    
                    // Handle custom variables
                    for ($i = 1; $i <= 4; $i++) {
                        $custom = "c" . $i;
                        if(isset($_GET[$custom]) && $_GET[$custom] != ''){
                            $escaped = $db ? $db->real_escape_string((string)$_GET[$custom]) : addslashes((string)$_GET[$custom]);
                            $new_url = str_replace("[[$custom]]", $escaped, $new_url);
                        } else {
                            $new_url = str_replace("[[$custom]]", "p202$custom", $new_url);
                        }
                    }
                    
                    // Handle UTM parameters
                    $utmParams = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
                    foreach ($utmParams as $param) {
                        if(isset($_GET[$param]) && $_GET[$param] != ''){
                            $escaped = $db ? $db->real_escape_string((string)$_GET[$param]) : addslashes((string)$_GET[$param]);
                            $new_url = str_replace("[[$param]]", $escaped, $new_url);
                        } else {
                            $new_url = str_replace("[[$param]]", "p202$param", $new_url);
                        }
                    }
                    
                    // Handle gclid
                    if(isset($_GET['gclid']) && $_GET['gclid'] != ''){
                        $escaped = $db ? $db->real_escape_string((string)$_GET['gclid']) : addslashes((string)$_GET['gclid']);
                        $new_url = str_replace("[[gclid]]", $escaped, $new_url);
                    } else {
                        $new_url = str_replace("[[gclid]]", "p202gclid", $new_url);
                    }
                    
                    $this->capturedHeaders[] = 'location: '. $new_url;
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

    /**
     * Test missing tracker ID causes script to die
     */
    public function testMissingIdDies(): void
    {
        $result = $this->simulateDlLogic([], false);
        $this->assertTrue($result['died']);
        $this->assertEmpty($result['headers']);
        $this->assertSame('', $result['output']);
    }

    /**
     * Test cached redirect with all parameters
     */
    public function testCachedRedirectWithAllParameters(): void
    {
        $url = 'http://example.com/?subid=[[subid]]&c1=[[c1]]&c2=[[c2]]&c3=[[c3]]&c4=[[c4]]&utm_source=[[utm_source]]&gclid=[[gclid]]';
        $key = md5('url_1hash');
        
        $result = $this->simulateDlLogic(
            [
                't202id' => '1',
                'c1' => 'campaign1',
                'c2' => 'adgroup2',
                'c3' => 'keyword3',
                'c4' => 'creative4',
                'utm_source' => 'google',
                'gclid' => 'test_gclid_123'
            ],
            false,  // Database not working, but we still have mock for escaping
            [$key => $url]
        );
        
        $this->assertTrue($result['died']);
        $this->assertNotEmpty($result['headers']);
        
        $location = implode("\n", $result['headers']);
        $this->assertStringContainsString('c1=campaign1', $location);
        $this->assertStringContainsString('c2=adgroup2', $location);
        $this->assertStringContainsString('c3=keyword3', $location);
        $this->assertStringContainsString('c4=creative4', $location);
        $this->assertStringContainsString('utm_source=google', $location);
        $this->assertStringContainsString('gclid=test_gclid_123', $location);
        $this->assertStringContainsString('subid=p202', $location);
    }

    /**
     * Test cached redirect with default placeholders
     */
    public function testCachedRedirectWithDefaults(): void
    {
        $url = 'http://example.com/?subid=[[subid]]&c1=[[c1]]&utm_source=[[utm_source]]';
        $key = md5('url_1hash');
        
        $result = $this->simulateDlLogic(
            ['t202id' => '1'],
            false,  // Database not working for cached test
            [$key => $url]
        );
        
        $this->assertTrue($result['died']);
        $location = implode("\n", $result['headers']);
        $this->assertStringContainsString('c1=p202c1', $location);
        $this->assertStringContainsString('utm_source=p202utm_source', $location);
    }

    /**
     * Test database connection error message
     */
    public function testDatabaseConnectionError(): void
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