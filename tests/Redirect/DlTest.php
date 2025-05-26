<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DlTest extends TestCase
{
    private function runDl(array $get, bool $memcacheWorking, array $memcacheData = []): array
    {
        $tmpDir = sys_get_temp_dir() . '/dltest_' . uniqid();
        mkdir($tmpDir . '/tracking202/redirect', 0777, true);
        mkdir($tmpDir . '/202-config', 0777, true);

        copy(__DIR__ . '/../../tracking202/redirect/dl.php', $tmpDir . '/tracking202/redirect/dl.php');

        $connectStub = <<'PHP'
<?php
$memcacheWorking = getenv('MEMCACHE_WORKING') === '1';
$db = new class {
    public function real_escape_string(string $str): string
    {
        return addslashes($str);
    }
};
class Memcache
{
    private $data;
    public function __construct()
    {
        $this->data = json_decode(getenv('MEMCACHE_DATA') ?: '[]', true);
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
$memcache = new Memcache();
function systemHash(): string { return 'hash'; }
function memcache_mysql_fetch_assoc($db, $sql) { return []; }
PHP;
        file_put_contents($tmpDir . '/202-config/connect2.php', $connectStub);

        $dataEngineStub = <<'PHP'
<?php
class DataEngine { public function setDirtyHour($id) {} }
PHP;
        file_put_contents($tmpDir . '/202-config/class-dataengine-slim.php', $dataEngineStub);

        $runScript = <<'PHP'
<?php
$headers = [];
function header($string, $replace = true, $http_response_code = 0) {
    global $headers;
    $headers[] = $string;
}
ob_start();
register_shutdown_function(function() use (&$headers) {
    $output = ob_get_clean();
    echo json_encode(['headers' => $headers, 'output' => $output]);
});
$_GET = json_decode(getenv('DL_GET'), true) ?: [];
$_SERVER['HTTP_REFERER'] = 'http://example.com';
$_SERVER['SERVER_NAME'] = 'test.com';
$_SERVER['REQUEST_URI'] = '/test';
require __DIR__ . '/tracking202/redirect/dl.php';
PHP;
        file_put_contents($tmpDir . '/run.php', $runScript);

        $env = [
            'DL_GET' => json_encode($get),
            'MEMCACHE_WORKING' => $memcacheWorking ? '1' : '0',
            'MEMCACHE_DATA' => json_encode($memcacheData),
        ];
        $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open('php run.php', $descriptor, $pipes, $tmpDir, $env);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        proc_close($process);
        return json_decode($output, true);
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
