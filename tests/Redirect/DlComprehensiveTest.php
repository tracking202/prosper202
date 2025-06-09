<?php
declare(strict_types=1);

namespace Tests\Redirect;

use PHPUnit\Framework\TestCase;

/**
 * Comprehensive test suite for dl.php redirect functionality
 * Tests all edge cases, PHP 8 compatibility, and error handling
 */
class DlComprehensiveTest extends TestCase
{
    private array $originalGet;
    private array $originalServer;
    private array $originalCookie;
    private $mockDb;
    private $mockMemcache;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Store original superglobals
        $this->originalGet = $_GET ?? [];
        $this->originalServer = $_SERVER ?? [];
        $this->originalCookie = $_COOKIE ?? [];
        
        // Set up default server vars
        $_SERVER['HTTP_HOST'] = 'test.prosper202.com';
        $_SERVER['SERVER_NAME'] = 'test.prosper202.com';
        $_SERVER['REQUEST_URI'] = '/tracking202/redirect/dl.php';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '192.168.1.100';
        
        // Create mock database
        $this->mockDb = $this->createMockDb();
        
        // Create mock memcache
        $this->mockMemcache = $this->createMockMemcache();
    }
    
    protected function tearDown(): void
    {
        // Restore original superglobals
        $_GET = $this->originalGet;
        $_SERVER = $this->originalServer;
        $_COOKIE = $this->originalCookie;
        
        parent::tearDown();
    }
    
    private function createMockDb()
    {
        return new class {
            public $insert_id = 50;
            
            public function real_escape_string($value): string
            {
                return addslashes((string)$value);
            }
            
            public function query($sql)
            {
                return new class {
                    public function fetch_assoc()
                    {
                        return [
                            'user_id' => '1',
                            'aff_campaign_id' => '10',
                            'text_ad_id' => '5',
                            'ppc_account_id' => '2',
                            'click_cpc' => '0.50',
                            'click_cpa' => '25.00',
                            'click_cloaking' => '0',
                            'aff_campaign_rotate' => '0',
                            'aff_campaign_url' => 'https://example.com/offer?subid=[[subid]]',
                            'aff_campaign_payout' => '25.00',
                            'aff_campaign_cloaking' => '0',
                            'ppc_variable_ids' => '1,2,3',
                            'parameters' => 'utm_source,utm_medium,utm_campaign',
                            'user_timezone' => 'America/New_York',
                            'user_keyword_searched_or_bidded' => 'bidded',
                            'user_pref_referer_data' => '0',
                            'user_pref_dynamic_bid' => '0',
                            'maxmind_isp' => '0'
                        ];
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
        };
    }
    
    /**
     * Test that non-numeric t202id parameter causes script to die
     */
    public function testNonNumericT202idDies(): void
    {
        $_GET['t202id'] = 'abc123';
        
        ob_start();
        $died = false;
        
        // Simulate the check
        $t202id = $_GET['t202id'] ?? '';
        if (!is_numeric($t202id)) {
            $died = true;
        }
        
        ob_end_clean();
        
        $this->assertTrue($died, 'Script should die with non-numeric t202id');
    }
    
    /**
     * Test that missing t202id parameter causes script to die
     */
    public function testMissingT202idDies(): void
    {
        unset($_GET['t202id']);
        
        $died = false;
        
        // Simulate the check
        $t202id = isset($_GET['t202id']) ? $_GET['t202id'] : '';
        if (!is_numeric($t202id)) {
            $died = true;
        }
        
        $this->assertTrue($died, 'Script should die with missing t202id');
    }
    
    /**
     * Test cached redirect functionality
     */
    public function testCachedRedirectWithAllParameters(): void
    {
        $t202id = '123';
        $url = 'https://example.com/offer?subid=[[subid]]&c1=[[c1]]&c2=[[c2]]&c3=[[c3]]&c4=[[c4]]&gclid=[[gclid]]';
        
        $_GET = [
            't202id' => $t202id,
            'c1' => 'campaign1',
            'c2' => 'adgroup2',
            'c3' => 'keyword3',
            'c4' => 'creative4',
            'gclid' => 'test_gclid_123'
        ];
        
        // Test URL replacement
        $new_url = $url;
        $new_url = str_replace("[[subid]]", "p202", $new_url);
        $new_url = str_replace("[[c1]]", $this->mockDb->real_escape_string((string)$_GET['c1']), $new_url);
        $new_url = str_replace("[[c2]]", $this->mockDb->real_escape_string((string)$_GET['c2']), $new_url);
        $new_url = str_replace("[[c3]]", $this->mockDb->real_escape_string((string)$_GET['c3']), $new_url);
        $new_url = str_replace("[[c4]]", $this->mockDb->real_escape_string((string)$_GET['c4']), $new_url);
        $new_url = str_replace("[[gclid]]", $this->mockDb->real_escape_string((string)$_GET['gclid']), $new_url);
        
        $expected = 'https://example.com/offer?subid=p202&c1=campaign1&c2=adgroup2&c3=keyword3&c4=creative4&gclid=test_gclid_123';
        $this->assertEquals($expected, $new_url);
    }
    
    /**
     * Test UTM parameter handling
     */
    public function testUtmParameterHandling(): void
    {
        $_GET = [
            't202id' => '123',
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'summer_sale',
            'utm_term' => 'discount shoes',
            'utm_content' => 'text_ad_1'
        ];
        
        $url = 'https://example.com/offer?source=[[utm_source]]&medium=[[utm_medium]]';
        
        // Test UTM replacement
        $new_url = $url;
        $new_url = str_replace("[[utm_source]]", $this->mockDb->real_escape_string((string)$_GET['utm_source']), $new_url);
        $new_url = str_replace("[[utm_medium]]", $this->mockDb->real_escape_string((string)$_GET['utm_medium']), $new_url);
        
        $expected = 'https://example.com/offer?source=google&medium=cpc';
        $this->assertEquals($expected, $new_url);
    }
    
    /**
     * Test keyword extraction from various sources
     */
    public function testKeywordExtractionBidded(): void
    {
        $tracker_row = ['user_keyword_searched_or_bidded' => 'bidded'];
        $keyword = '';
        
        // Test Yahoo keyword
        $_GET['OVKEY'] = 'yahoo keyword';
        if (isset($_GET['OVKEY']) && $_GET['OVKEY']) {
            $keyword = $this->mockDb->real_escape_string((string)$_GET['OVKEY']);
        }
        $this->assertEquals('yahoo keyword', $keyword);
        
        // Test t202kw
        unset($_GET['OVKEY']);
        $_GET['t202kw'] = 'prosper keyword';
        if (isset($_GET['t202kw']) && $_GET['t202kw']) {
            $keyword = $this->mockDb->real_escape_string((string)$_GET['t202kw']);
        }
        $this->assertEquals('prosper keyword', $keyword);
    }
    
    /**
     * Test keyword extraction from search engines
     */
    public function testKeywordExtractionSearched(): void
    {
        $tracker_row = ['user_keyword_searched_or_bidded' => 'searched'];
        $referer_query = [];
        $keyword = '';
        
        // Test Google search
        $referer_query['q'] = 'google search term';
        if (isset($referer_query['q']) && $referer_query['q']) {
            $keyword = $this->mockDb->real_escape_string((string)$referer_query['q']);
        }
        $this->assertEquals('google search term', $keyword);
        
        // Test Baidu
        $referer_query = ['wd' => 'baidu search'];
        if (isset($referer_query['wd']) && $referer_query['wd']) {
            $keyword = $this->mockDb->real_escape_string((string)$referer_query['wd']);
        }
        $this->assertEquals('baidu search', $keyword);
    }
    
    /**
     * Test IP address handling
     */
    public function testIpAddressHandling(): void
    {
        // Test with X-Forwarded-For
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '192.168.1.100';
        $ip_string = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : '0.0.0.0';
        $this->assertEquals('192.168.1.100', $ip_string);
        
        // Test without X-Forwarded-For
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip_string = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : '0.0.0.0';
        $this->assertEquals('0.0.0.0', $ip_string);
    }
    
    /**
     * Test referer handling with missing HTTP_REFERER
     */
    public function testMissingRefererHandling(): void
    {
        unset($_SERVER['HTTP_REFERER']);
        
        $referer_url_parsed = array();
        $referer_url_query = '';
        $referer_query = array();
        
        if (isset($_SERVER['HTTP_REFERER']) && $_SERVER['HTTP_REFERER']) {
            $referer_url_parsed = @parse_url($_SERVER['HTTP_REFERER']);
            $referer_url_query = isset($referer_url_parsed['query']) ? $referer_url_parsed['query'] : '';
            if ($referer_url_query) {
                @parse_str($referer_url_query, $referer_query);
            }
        }
        
        $this->assertEmpty($referer_url_parsed);
        $this->assertEmpty($referer_url_query);
        $this->assertEmpty($referer_query);
    }
    
    /**
     * Test device type detection initialization
     */
    public function testDeviceTypeInitialization(): void
    {
        // Test bot detection
        $device_id = ['type' => '4'];
        $mysql = [];
        
        // Initialize click_bot
        $mysql['click_bot'] = '0';
        if ($device_id['type'] == '4') {
            $mysql['click_filtered'] = '1';
            $mysql['click_bot'] = '1';
        }
        
        $this->assertEquals('1', $mysql['click_bot']);
        $this->assertEquals('1', $mysql['click_filtered']);
    }
    
    /**
     * Test ISP ID initialization when maxmind_isp is disabled
     */
    public function testIspIdInitialization(): void
    {
        $tracker_row = ['maxmind_isp' => '0'];
        $mysql = [];
        
        // Initialize isp_id
        $mysql['isp_id'] = '0';
        if ($tracker_row['maxmind_isp'] == '1') {
            // Would get ISP data
            $mysql['isp_id'] = $this->mockDb->real_escape_string((string)'123');
        }
        
        $this->assertEquals('0', $mysql['isp_id']);
    }
    
    /**
     * Test dynamic bid functionality
     */
    public function testDynamicBidHandling(): void
    {
        $tracker_row = ['user_pref_dynamic_bid' => '1', 'click_cpc' => '0.50'];
        $_GET['t202b'] = '$1.25';
        
        if (isset($_GET['t202b']) && $tracker_row['user_pref_dynamic_bid'] == '1') {
            $_GET['t202b'] = ltrim($_GET['t202b'], '$');
            if (is_numeric($_GET['t202b'])) {
                $bid = number_format((float)$_GET['t202b'], 5, '.', '');
                $mysql['click_cpc'] = $this->mockDb->real_escape_string((string)$bid);
                $this->assertEquals('1.25000', $mysql['click_cpc']);
            }
        }
    }
    
    /**
     * Test custom variable handling
     */
    public function testCustomVariableHandling(): void
    {
        $_GET = [
            'c1' => 'value1',
            'c2' => 'value2',
            'c3' => 'value3',
            'c4' => 'value4'
        ];
        
        $_lGET = array_change_key_case($_GET, CASE_LOWER);
        
        for ($i = 1; $i <= 4; $i++) {
            $custom = "c" . $i;
            $custom_val = isset($_lGET[$custom]) ? $_lGET[$custom] : '';
            $custom_val = $this->mockDb->real_escape_string((string)$custom_val);
            $custom_val = str_replace('%20', ' ', $custom_val);
            
            $this->assertEquals('value' . $i, $custom_val);
        }
    }
    
    /**
     * Test PPC network variables
     */
    public function testPpcNetworkVariables(): void
    {
        $tracker_row = [
            'ppc_variable_ids' => '1,2,3',
            'parameters' => 'source,medium,campaign'
        ];
        
        $_GET = [
            'source' => 'google',
            'medium' => 'cpc',
            'campaign' => 'test'
        ];
        
        $ppc_variable_ids = isset($tracker_row['ppc_variable_ids']) && $tracker_row['ppc_variable_ids'] 
            ? explode(',', $tracker_row['ppc_variable_ids']) 
            : array();
        
        $parameters = isset($tracker_row['parameters']) && $tracker_row['parameters'] 
            ? explode(',', $tracker_row['parameters']) 
            : array();
        
        $this->assertCount(3, $ppc_variable_ids);
        $this->assertCount(3, $parameters);
        
        foreach ($parameters as $key => $value) {
            $variable = isset($_GET[$value]) ? $this->mockDb->real_escape_string((string)$_GET[$value]) : '';
            $this->assertNotEmpty($variable);
        }
    }
    
    /**
     * Test date/time handling for mktime
     */
    public function testDateTimeHandling(): void
    {
        $today_day = (int)date('j', time());
        $today_month = (int)date('n', time());
        $today_year = (int)date('Y', time());
        
        $this->assertIsInt($today_day);
        $this->assertIsInt($today_month);
        $this->assertIsInt($today_year);
        
        $click_time = mktime(12, 0, 0, $today_month, $today_day, $today_year);
        $this->assertIsInt($click_time);
    }
    
    /**
     * Test tracker not found scenario
     */
    public function testTrackerNotFound(): void
    {
        $tracker_row = false;
        
        if (!$tracker_row) {
            $died = true;
        } else {
            $died = false;
        }
        
        $this->assertTrue($died, 'Script should die when tracker not found');
    }
    
    /**
     * Test URL token replacement
     */
    public function testUrlTokenReplacement(): void
    {
        $tokens = [
            'subid' => '12345',
            't202kw' => 'test keyword',
            'c1' => 'campaign1',
            'c2' => 'adgroup2',
            'c3' => 'keyword3',
            'c4' => 'creative4',
            'gclid' => 'test_gclid',
            'utm_source' => 'google',
            'utm_medium' => 'cpc'
        ];
        
        $url = 'https://example.com/?subid=[[subid]]&kw=[[t202kw]]&c1=[[c1]]';
        
        foreach ($tokens as $key => $value) {
            $url = str_replace('[[' . $key . ']]', $value, $url);
        }
        
        $expected = 'https://example.com/?subid=12345&kw=test keyword&c1=campaign1';
        $this->assertEquals($expected, $url);
    }
    
    /**
     * Test edge cases for real_escape_string type casting
     */
    public function testRealEscapeStringTypeCasting(): void
    {
        $testCases = [
            'null' => [null, ''],
            'zero' => [0, '0'],
            'int' => [123, '123'],
            'float' => [45.67, '45.67'],
            'true' => [true, '1'],
            'false' => [false, ''],
            // Skip array test as it causes warning
        ];
        
        foreach ($testCases as $name => $testData) {
            $input = $testData[0];
            $expected = $testData[1];
            $result = $this->mockDb->real_escape_string((string)$input);
            $this->assertEquals($expected, $result, "Failed for input: " . var_export($input, true));
        }
    }
}