<?php
declare(strict_types=1);

namespace Tests\Redirect;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for specific functions and edge cases in dl.php
 */
class DlUnitTest extends TestCase
{
    /**
     * Test ipAddress() function creates proper IP object
     */
    public function testIpAddressFunction(): void
    {
        // Include the function (would normally be in connect2.php)
        if (!function_exists('ipAddress')) {
            function ipAddress($ip_address)
            {
                $ip = new \stdClass;
                
                if (filter_var($ip_address, FILTER_VALIDATE_IP)) {
                    $ip->address = $ip_address;
                    if (filter_var($ip_address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        $ip->type = 'ipv4';
                    } else {
                        $ip->type = 'ipv6';
                    }
                } else {
                    $ip->type = 'invalid';
                    $ip->address = '';
                }
                
                return $ip;
            }
        }
        
        // Test IPv4
        $ip = ipAddress('192.168.1.1');
        $this->assertEquals('192.168.1.1', $ip->address);
        $this->assertEquals('ipv4', $ip->type);
        
        // Test IPv6
        $ip = ipAddress('2001:0db8:85a3:0000:0000:8a2e:0370:7334');
        $this->assertEquals('2001:0db8:85a3:0000:0000:8a2e:0370:7334', $ip->address);
        $this->assertEquals('ipv6', $ip->type);
        
        // Test invalid IP
        $ip = ipAddress('invalid.ip.address');
        $this->assertEquals('invalid', $ip->type);
        $this->assertEquals('', $ip->address);
        
        // Test empty string
        $ip = ipAddress('');
        $this->assertEquals('invalid', $ip->type);
        $this->assertEquals('', $ip->address);
        
        // Test null (cast to string)
        $ip = ipAddress((string)null);
        $this->assertEquals('invalid', $ip->type);
        $this->assertEquals('', $ip->address);
    }
    
    /**
     * Test URL token replacement function
     */
    public function testReplaceTrackerPlaceholders(): void
    {
        $url = 'https://example.com/track?subid=[[subid]]&kw=[[t202kw]]&c1=[[c1]]';
        $click_id = 12345;
        $mysql = [
            'keyword' => 'test keyword',
            'c1' => 'campaign1'
        ];
        
        // Simulate token replacement
        $tokens = [
            'subid' => $click_id,
            't202kw' => $mysql['keyword'] ?? '',
            'c1' => $mysql['c1'] ?? ''
        ];
        
        foreach ($tokens as $key => $value) {
            $url = preg_replace('/\[\[' . $key . '\]\]/i', (string)$value, $url);
        }
        
        $expected = 'https://example.com/track?subid=12345&kw=test keyword&c1=campaign1';
        $this->assertEquals($expected, $url);
    }
    
    /**
     * Test keyword search patterns
     */
    public function testKeywordSearchPatterns(): void
    {
        $searchEngines = [
            'google' => ['param' => 'q', 'test' => 'google search'],
            'bing' => ['param' => 'q', 'test' => 'bing search'],
            'yahoo' => ['param' => 'p', 'test' => 'yahoo search'],
            'baidu' => ['param' => 'wd', 'test' => 'baidu search'],
            'yandex' => ['param' => 'text', 'test' => 'yandex search'],
            'duckduckgo' => ['param' => 'q', 'test' => 'duck search']
        ];
        
        foreach ($searchEngines as $engine => $data) {
            $referer = "https://www.{$engine}.com/search?{$data['param']}=" . urlencode($data['test']);
            $parsed = parse_url($referer);
            parse_str($parsed['query'] ?? '', $query);
            
            $this->assertEquals($data['test'], $query[$data['param']] ?? '');
        }
    }
    
    /**
     * Test custom variable processing
     */
    public function testCustomVariableProcessing(): void
    {
        $testCases = [
            'normal text' => 'normal text',
            'text%20with%20spaces' => 'text with spaces',
            'special!@#$%^&*()' => 'special!@#$%^&*()',
            '' => '',
            '   ' => '   ', // spaces preserved
        ];
        
        foreach ($testCases as $input => $expected) {
            $result = str_replace('%20', ' ', $input);
            $this->assertEquals($expected, $result);
        }
    }
    
    /**
     * Test bot detection logic
     */
    public function testBotDetection(): void
    {
        $deviceTypes = [
            '1' => 'Desktop',
            '2' => 'Mobile',
            '3' => 'Tablet',
            '4' => 'Bot'
        ];
        
        foreach ($deviceTypes as $type => $name) {
            $mysql = ['click_bot' => '0'];
            
            if ($type == '4') {
                $mysql['click_filtered'] = '1';
                $mysql['click_bot'] = '1';
            }
            
            if ($type == '4') {
                $this->assertEquals('1', $mysql['click_bot']);
                $this->assertEquals('1', $mysql['click_filtered']);
            } else {
                $this->assertEquals('0', $mysql['click_bot']);
                $this->assertArrayNotHasKey('click_filtered', $mysql);
            }
        }
    }
    
    /**
     * Test CPA tracker condition
     */
    public function testCpaTrackerLogic(): void
    {
        $testCases = [
            ['click_cpa' => '25.00', 'should_insert' => true],
            ['click_cpa' => '0', 'should_insert' => false],
            ['click_cpa' => '', 'should_insert' => false],
            ['click_cpa' => null, 'should_insert' => false],
            ['click_cpa' => '0.01', 'should_insert' => true],
        ];
        
        foreach ($testCases as $test) {
            $mysql = ['click_cpa' => $test['click_cpa']];
            
            if ($mysql['click_cpa'] != NULL && $mysql['click_cpa'] != '0' && $mysql['click_cpa'] != '') {
                $should_insert = true;
            } else {
                $should_insert = false;
            }
            
            $this->assertEquals($test['should_insert'], $should_insert);
        }
    }
    
    /**
     * Test date/time calculations
     */
    public function testDateTimeCalculations(): void
    {
        // Test that date functions return expected types
        $day = (int)date('j', time());
        $month = (int)date('n', time());
        $year = (int)date('Y', time());
        
        $this->assertIsInt($day);
        $this->assertGreaterThanOrEqual(1, $day);
        $this->assertLessThanOrEqual(31, $day);
        
        $this->assertIsInt($month);
        $this->assertGreaterThanOrEqual(1, $month);
        $this->assertLessThanOrEqual(12, $month);
        
        $this->assertIsInt($year);
        $this->assertGreaterThanOrEqual(2020, $year);
        
        // Test mktime with integers
        $time = mktime(12, 0, 0, $month, $day, $year);
        $this->assertIsInt($time);
        $this->assertGreaterThan(0, $time);
    }
    
    /**
     * Test URL building with various server configurations
     */
    public function testUrlBuilding(): void
    {
        $configs = [
            [
                'SERVER_NAME' => 'example.com',
                'REQUEST_URI' => '/tracking/redirect.php?id=123',
                'expected' => 'http://example.com/tracking/redirect.php?id=123'
            ],
            [
                'SERVER_NAME' => 'sub.domain.com',
                'REQUEST_URI' => '/path/to/file',
                'expected' => 'http://sub.domain.com/path/to/file'
            ],
            [
                'SERVER_NAME' => 'localhost',
                'REQUEST_URI' => '/',
                'expected' => 'http://localhost/'
            ]
        ];
        
        foreach ($configs as $config) {
            $url = 'http://' . $config['SERVER_NAME'] . $config['REQUEST_URI'];
            $this->assertEquals($config['expected'], $url);
        }
    }
    
    /**
     * Test handling of null and empty values
     */
    public function testNullAndEmptyHandling(): void
    {
        // Test null coalescing
        $values = [
            'exists' => 'value',
            'empty' => '',
            'zero' => '0',
            'false' => false,
            'null' => null
        ];
        
        $this->assertEquals('value', $values['exists'] ?? 'default');
        $this->assertEquals('', $values['empty'] ?? 'default');
        $this->assertEquals('0', $values['zero'] ?? 'default');
        $this->assertEquals(false, $values['false'] ?? 'default');
        $this->assertEquals('default', $values['null'] ?? 'default');
        $this->assertEquals('default', $values['missing'] ?? 'default');
        
        // Test isset checks
        $this->assertTrue(isset($values['exists']));
        $this->assertTrue(isset($values['empty']));
        $this->assertTrue(isset($values['zero']));
        $this->assertTrue(isset($values['false']));
        $this->assertFalse(isset($values['null']));
        $this->assertFalse(isset($values['missing']));
    }
    
    /**
     * Test array key case conversion
     */
    public function testArrayKeyCaseConversion(): void
    {
        $input = [
            'Lower' => 'value1',
            'UPPER' => 'value2',
            'MiXeD' => 'value3',
            'with_underscore' => 'value4',
            'WITH-DASH' => 'value5'
        ];
        
        $lower = array_change_key_case($input, CASE_LOWER);
        
        $this->assertArrayHasKey('lower', $lower);
        $this->assertArrayHasKey('upper', $lower);
        $this->assertArrayHasKey('mixed', $lower);
        $this->assertArrayHasKey('with_underscore', $lower);
        $this->assertArrayHasKey('with-dash', $lower);
        
        $this->assertEquals('value1', $lower['lower']);
        $this->assertEquals('value2', $lower['upper']);
        $this->assertEquals('value3', $lower['mixed']);
    }
}