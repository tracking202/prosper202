<?php

declare(strict_types=1);

namespace Tests\Apps\Android\Integrity;

use Api\V3\Apps\Android\Integrity\ServiceAccountCredential;

/**
 * Runs tests/fixtures/play-integrity/fake_google.py — Google's token and
 * decodeIntegrityToken endpoints over real TLS on 127.0.0.1 — for the
 * client's tests, and mints service accounts for it. Skips (null) when
 * python3 or the openssl CLI is missing.
 *
 * The port is P202_FAKE_GOOGLE_PORT, default 8241.
 */
final class FakeGoogle
{
    /** @var resource */
    private $process;
    public readonly string $origin;
    public readonly string $caFile;
    private readonly string $dir;

    private function __construct(string $dir, int $port, mixed $process, public readonly ServiceAccountCredential $credential, public readonly string $privateKeyPem)
    {
        $this->dir = $dir;
        $this->process = $process;
        $this->origin = 'https://127.0.0.1:' . $port;
        $this->caFile = $dir . '/tls/cert.pem';
    }

    public static function start(): ?self
    {
        foreach (['python3', 'openssl'] as $bin) {
            if (trim((string) shell_exec('command -v ' . $bin . ' 2>/dev/null')) === '') {
                return null;
            }
        }
        $dir = sys_get_temp_dir() . '/p202-fake-google-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        [$pem, $public] = self::rsaKey();
        file_put_contents($dir . '/sa-public.pem', $public);
        $port = (int) (getenv('P202_FAKE_GOOGLE_PORT') ?: 8241);
        $script = dirname(__DIR__, 3) . '/fixtures/play-integrity/fake_google.py';
        $process = proc_open(
            ['python3', $script, '--port', (string) $port, '--tls-dir', $dir . '/tls', '--sa-public-key', $dir . '/sa-public.pem'],
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
            throw new \RuntimeException('the fake Google server did not start: ' . $line . (string) @file_get_contents($dir . '/stderr.log'));
        }

        return new self($dir, $port, $process, self::credential($pem), $pem);
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
        self::remove($this->dir);
    }

    public static function remove(string $dir): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }

    /** A service account signed with a key the fake does NOT trust. */
    public static function strangerCredential(): ServiceAccountCredential
    {
        return self::credential(self::rsaKey()[0]);
    }

    private static function credential(string $pem): ServiceAccountCredential
    {
        return ServiceAccountCredential::fromKeyFile([
            'type' => 'service_account',
            'project_id' => 'p202-test-project',
            'private_key_id' => '0123456789abcdef0123456789abcdef01234567',
            'private_key' => $pem,
            'client_email' => 'integrity@p202-test-project.iam.gserviceaccount.com',
            'client_id' => '1234567890',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]);
    }

    /** @return array{0: string, 1: string} private PEM, public PEM */
    public static function rsaKey(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false || !openssl_pkey_export($key, $pem)) {
            throw new \RuntimeException('could not mint an RSA key');
        }
        $details = openssl_pkey_get_details($key);

        return [(string) $pem, (string) $details['key']];
    }

    /** @param array<string, mixed> $body */
    public function control(string $path, array $body = []): void
    {
        $this->call('POST', '/control/' . $path, $body);
    }

    /** @param list<array<string, mixed>> $responses */
    public function scenario(string $token, array $responses): void
    {
        $this->control('scenario', ['token' => $token, 'responses' => $responses]);
    }

    /** @return list<array<string, mixed>> */
    public function requests(): array
    {
        return $this->call('GET', '/control/requests')['requests'];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, array $body = []): array
    {
        $ch = curl_init($this->origin . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CAINFO => $this->caFile,
            CURLOPT_NOPROXY => '*',
            CURLOPT_TIMEOUT => 10,
        ] + ($method === 'POST' ? [CURLOPT_POSTFIELDS => (string) json_encode($body)] : []));
        $out = curl_exec($ch);
        curl_close($ch);
        if (!is_string($out)) {
            throw new \RuntimeException('fake Google control call failed: ' . $path);
        }

        return (array) json_decode($out, true);
    }
}
