<?php

declare(strict_types=1);

namespace Tests\License;

use PHPUnit\Framework\TestCase;
use Prosper202\License\ClickServerKeyValidator;

/**
 * Every licence request carries X-P202-Licence-Grace: 1, which tells the
 * service and its edge that this install reads 503 as "no answer" (its grace
 * period). Sent on the wire: ClickServerKeyValidator's one request, made to a
 * loopback server that answers with the headers it received. Every caller
 * asks through that request (answer(), which check() and api_key_validate()
 * use; ClickServerAuthAnswerTest holds the pages to it).
 */
final class LicenceRequestHeaderTest extends TestCase
{
    /** @var resource|null */
    private $server = null;

    private string $dir = '';

    private int $port = 0;

    private function startServer(): void
    {
        $this->dir = sys_get_temp_dir() . '/p202-licence-header-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($this->dir, 0700));
        file_put_contents(
            $this->dir . '/router.php',
            '<?php header("Content-Type: application/json"); echo json_encode(['
            . '"headers" => array_change_key_case(getallheaders()), "method" => $_SERVER["REQUEST_METHOD"]]);'
        );
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($probe, 'a loopback port could be found');
        $name = (string) stream_socket_get_name($probe, false);
        $this->port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($probe);
        $this->server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, $this->dir . '/router.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', $this->port);
            if ($socket !== false) {
                fclose($socket);
                return;
            }
            usleep(100000);
        }
        self::fail('the loopback server did not start');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        if ($this->dir !== '') {
            @unlink($this->dir . '/router.php');
            @rmdir($this->dir);
        }
    }

    public function testTheGraceHeaderIsSentWithTheKey(): void
    {
        $this->startServer();
        $post = new \ReflectionMethod(ClickServerKeyValidator::class, 'post');
        $answer = $post->invoke(null, 'http://127.0.0.1:' . $this->port . '/', 'header-test-key', 2000, 4000);
        self::assertIsArray($answer, 'the loopback server answered');
        [$status, $body] = $answer;
        self::assertSame(200, $status);
        $seen = json_decode($body, true);
        self::assertIsArray($seen);
        self::assertSame('POST', $seen['method']);
        self::assertSame('1', $seen['headers']['x-p202-licence-grace'] ?? null, 'X-P202-Licence-Grace: 1 was sent');
    }

    public function testTheHeaderIsTheOneTheServiceLooksFor(): void
    {
        self::assertSame(['X-P202-Licence-Grace: 1'], ClickServerKeyValidator::REQUEST_HEADERS);
    }
}
