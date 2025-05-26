<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DlTest extends TestCase
{
    private function runDl(array $get, bool $memcacheWorking, array $memcacheData = []): array
    {
        $headers = [];
        function header($string, $replace = true, $http_response_code = 0) {
            global $headers;
            $headers[] = $string;
        }

        $_GET = $get;
        $_SERVER['HTTP_REFERER'] = 'http://example.com';
        $_SERVER['SERVER_NAME'] = 'test.com';
        $_SERVER['REQUEST_URI'] = '/test';

        $memcacheWorking = $memcacheWorking;
        $db = new class {
            public function real_escape_string(string $str): string
            {
                return addslashes($str);
            }
        };
        class Memcache
        {
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
        }
        $memcache = new Memcache($memcacheData);
        function systemHash(): string { return 'hash'; }
        function memcache_mysql_fetch_assoc($db, $sql) { return []; }

        ob_start();
        require __DIR__ . '/../../tracking202/redirect/dl.php';
        $output = ob_get_clean();

        return ['headers' => $headers, 'output' => $output];
    }

    public function testInvalidIdDies(): void
    {
        $result = $this->runDl(['t202id' => 'abc'], false);
        $this->assertEmpty($result['headers']);
        $this->assertSame('', $result['output']);
    }

    public function testCachedRedirectWorks(): void
    {
        $url = 'http://example.com/?affsub=[[subid]]&c1=[[c1]]';
        $key = md5('url_1hash');
        $result = $this->runDl(
            ['t202id' => '1', 'c1' => 'foo'],
            true,
            [$key => $url]
        );
        $this->assertStringContainsString('location: http://example.com/?affsub=p202&c1=foo', implode("\n", $result['headers']));
    }

    public function testCachedRedirectError(): void
    {
        $result = $this->runDl(['t202id' => '1'], false);
        $this->assertStringContainsString('Error establishing a database connection', $result['output']);
        $this->assertEmpty($result['headers']);
    }
}
