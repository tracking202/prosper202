<?php
declare(strict_types=1);

namespace Tests\Redirect;

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for dl.php that test actual HTTP requests and responses
 * @group integration
 */
class DlIntegrationTest extends TestCase
{
    private $baseUrl = 'http://localhost:8000/tracking202/redirect/dl.php';
    
    /**
     * Test redirect with invalid tracker ID
     */
    public function testInvalidTrackerIdReturnsEmpty(): void
    {
        $ch = curl_init($this->baseUrl . '?t202id=0');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_HEADER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // Should return 302 redirect to cl.php
        $this->assertEquals(302, $httpCode);
        $this->assertStringNotContainsString('Fatal error', $response);
        $this->assertStringNotContainsString('Warning', $response);
    }
    
    /**
     * Test redirect with non-numeric tracker ID
     */
    public function testNonNumericTrackerIdReturnsEmpty(): void
    {
        $ch = curl_init($this->baseUrl . '?t202id=abc');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_HEADER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // Should return 200 and die silently for non-numeric tracker ID
        $this->assertEquals(200, $httpCode);
        $this->assertEmpty(trim(substr($response, strpos($response, "\r\n\r\n") + 4)));
    }
    
    /**
     * Test redirect with missing tracker ID
     */
    public function testMissingTrackerIdReturnsEmpty(): void
    {
        $ch = curl_init($this->baseUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_HEADER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // Should return 200 and die silently for missing tracker ID
        $this->assertEquals(200, $httpCode);
        $this->assertEmpty(trim(substr($response, strpos($response, "\r\n\r\n") + 4)));
    }
    
    /**
     * Test that all GET parameters are handled without errors
     */
    public function testAllParametersHandledWithoutErrors(): void
    {
        $params = [
            't202id' => '0',
            't202kw' => 'test keyword',
            'c1' => 'campaign1',
            'c2' => 'adgroup2', 
            'c3' => 'keyword3',
            'c4' => 'creative4',
            'gclid' => 'test_gclid_123',
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'summer_sale',
            'utm_term' => 'discount shoes',
            'utm_content' => 'text_ad_1',
            't202b' => '1.50',
            'OVKEY' => 'yahoo keyword',
            'OVRAW' => 'yahoo raw',
            'target_passthrough' => 'media traffic',
            'keyword' => 'generic keyword',
            'search_word' => 'eniro search',
            'query' => 'naver query',
            'encquery' => 'aol query',
            'terms' => 'about terms',
            'rdata' => 'viola data',
            'qs' => 'virgilio query',
            'wd' => 'baidu word',
            'text' => 'yandex text',
            'szukaj' => 'wp.pl search',
            'qt' => 'onet query',
            'k' => 'yam keyword',
            'words' => 'rambler words',
            't202ref' => 'custom referer',
            'ua' => 'Mozilla/5.0 Test User Agent'
        ];
        
        $url = $this->baseUrl . '?' . http_build_query($params);
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_HEADER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // Should redirect without PHP errors
        $this->assertEquals(302, $httpCode);
        $this->assertStringNotContainsString('Fatal error', $response);
        $this->assertStringNotContainsString('Warning', $response);
        $this->assertStringNotContainsString('Notice', $response);
        $this->assertStringNotContainsString('Undefined', $response);
        $this->assertStringNotContainsString('TypeError', $response);
    }
    
    /**
     * Test behavior without HTTP_REFERER
     */
    public function testWithoutHttpReferer(): void
    {
        $ch = curl_init($this->baseUrl . '?t202id=0&t202kw=test');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_HEADER, true);
        // Don't send referer header
        curl_setopt($ch, CURLOPT_REFERER, '');
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // Should handle missing referer gracefully and redirect
        $this->assertEquals(302, $httpCode);
        $this->assertStringNotContainsString('HTTP_REFERER', $response);
        $this->assertStringNotContainsString('Undefined', $response);
    }
    
    /**
     * Test special characters in parameters
     */
    public function testSpecialCharactersInParameters(): void
    {
        $params = [
            't202id' => '0',
            'c1' => 'test & special',
            'c2' => 'test < > chars',
            'c3' => 'test " quotes',
            'c4' => "test ' apostrophe",
            't202kw' => 'keyword with spaces and %20 encoding'
        ];
        
        $url = $this->baseUrl . '?' . http_build_query($params);
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_HEADER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // Should handle special characters without errors and redirect
        $this->assertEquals(302, $httpCode);
        $this->assertStringNotContainsString('Fatal error', $response);
        $this->assertStringNotContainsString('Warning', $response);
    }
    
    /**
     * Test with various numeric formats
     */
    public function testNumericFormats(): void
    {
        $testCases = [
            ['t202id' => '123', 'expected' => true],
            ['t202id' => '0', 'expected' => true],
            ['t202id' => '-1', 'expected' => true],
            ['t202id' => '123.45', 'expected' => true],
            ['t202id' => '1e5', 'expected' => true],
            ['t202id' => '0xFF', 'expected' => false], // hex not numeric
            ['t202id' => '123abc', 'expected' => false],
            ['t202id' => '', 'expected' => false],
        ];
        
        foreach ($testCases as $test) {
            $ch = curl_init($this->baseUrl . '?t202id=' . urlencode($test['t202id']));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($ch, CURLOPT_HEADER, true);
            
            $response = curl_exec($ch);
            curl_close($ch);
            
            $body = substr($response, strpos($response, "\r\n\r\n") + 4);
            
            if ($test['expected']) {
                // Should process (even if tracker not found)
                $this->assertStringNotContainsString('is_numeric', $body);
            } else {
                // Should die early
                $this->assertEmpty(trim($body));
            }
        }
    }
    
    /**
     * Test concurrent requests don't interfere
     */
    public function testConcurrentRequests(): void
    {
        $this->markTestSkipped('Concurrent testing requires special setup');
        
        // This would test that multiple simultaneous requests
        // don't interfere with each other's data
    }
}