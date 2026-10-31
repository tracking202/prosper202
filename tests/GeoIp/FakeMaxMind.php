<?php

declare(strict_types=1);

namespace Tests\GeoIp;

/**
 * Runs tests/fixtures/geoip/fake_maxmind.py — MaxMind's download endpoint
 * and its R2 redirect, over real TLS on 127.0.0.1 — and serves what the test
 * writes with serve(). Null when python3 or the openssl CLI is missing.
 *
 * The port is P202_FAKE_MAXMIND_PORT, default 8151.
 */
final class FakeMaxMind
{
    public const ACCOUNT = '123456';
    public const LICENSE = 'fakeKey_0123456789abcdefghijklmnopqrstu_mmk';

    /** @var resource */
    private $process;
    public readonly string $origin;
    public readonly string $caFile;
    public readonly string $serveDir;

    private function __construct(private readonly string $dir, int $port, mixed $process)
    {
        $this->process = $process;
        $this->origin = 'https://127.0.0.1:' . $port;
        $this->caFile = $dir . '/tls/cert.pem';
        $this->serveDir = $dir . '/serve';
    }

    public static function start(): ?self
    {
        foreach (['python3', 'openssl'] as $bin) {
            if (trim((string) shell_exec('command -v ' . $bin . ' 2>/dev/null')) === '') {
                return null;
            }
        }
        $dir = GeoIpFixture::tempDir();
        mkdir($dir . '/serve', 0700);
        $port = (int) (getenv('P202_FAKE_MAXMIND_PORT') ?: 8151);
        $process = proc_open(
            [
                'python3', dirname(__DIR__) . '/fixtures/geoip/fake_maxmind.py', '--port', (string) $port,
                '--tls-dir', $dir . '/tls', '--serve-dir', $dir . '/serve',
                '--account', self::ACCOUNT, '--license', self::LICENSE,
            ],
            [1 => ['pipe', 'w'], 2 => ['file', $dir . '/stderr.log', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            return null;
        }
        stream_set_timeout($pipes[1], 20);
        $line = (string) fgets($pipes[1]);
        if (!str_starts_with($line, 'READY ')) {
            proc_terminate($process);
            throw new \RuntimeException('the fake MaxMind server did not start: ' . $line
                . (string) @file_get_contents($dir . '/stderr.log'));
        }

        return new self($dir, $port, $process);
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
        GeoIpFixture::remove($this->dir);
    }

    /** What the download serves next: the archive, and the checksum line (MaxMind's own, unless one is given). */
    public function serve(string $archive, ?string $sha256 = null): void
    {
        file_put_contents($this->serveDir . '/GeoLite2-City.tar.gz', $archive);
        file_put_contents(
            $this->serveDir . '/GeoLite2-City.tar.gz.sha256',
            ($sha256 ?? hash('sha256', $archive)) . "  GeoLite2-City_20261022.tar.gz\n"
        );
    }

    /** Answer $status for the file's R2 URL instead of serving it; null serves it again. */
    public function answer(string $file, ?int $status): void
    {
        $path = $this->serveDir . '/' . $file . '.status';
        if ($status === null) {
            @unlink($path);
        } else {
            file_put_contents($path, (string) $status);
        }
    }

    /** @return list<array{method: string, path: string, query: string, authorization: bool, status: int}> */
    public function requests(): array
    {
        return $this->call('GET', '/control/requests')['requests'];
    }

    public function reset(): void
    {
        $this->call('POST', '/control/reset');
    }

    /** @return array<string, mixed> */
    private function call(string $method, string $path): array
    {
        $ch = curl_init($this->origin . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CAINFO => $this->caFile,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_TIMEOUT => 10,
        ]);
        $out = curl_exec($ch);
        curl_close($ch);
        if (!is_string($out)) {
            throw new \RuntimeException('fake MaxMind control call failed: ' . $path);
        }

        return $out === '' ? [] : (array) json_decode($out, true);
    }
}
