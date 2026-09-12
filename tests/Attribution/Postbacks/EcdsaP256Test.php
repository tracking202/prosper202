<?php

declare(strict_types=1);

namespace Tests\Attribution\Postbacks;

use Api\V3\Attribution\EcdsaP256;
use Tests\TestCase;

/**
 * Both postback verifiers (SKAdNetwork's PostbackVerifier and
 * AdAttributionKit's JwsVerifier) now decide "is this signature genuine?"
 * inside EcdsaP256::verify(), so a single edit here moves both trust
 * boundaries at once. The three answers it can give have to stay three:
 *
 *   true   the signature verifies
 *   false  the signature does not verify
 *   null   this installation cannot judge
 *
 * The null arm has two causes, and one of them — OpenSSL not being present —
 * cannot be provoked in a process that has OpenSSL. Left untested, replacing
 * `return null;` there with `return true;` turns every postback on a host
 * without OpenSSL into a verified one and no test in the tree notices. So
 * that arm is exercised in a child PHP process started with the two
 * functions disabled, against the real class file.
 */
final class EcdsaP256Test extends TestCase
{
    /** The functions EcdsaP256::verify() probes for before it judges anything. */
    private const REQUIRED_FUNCTIONS = ['openssl_verify', 'openssl_pkey_get_public'];

    private static ?\OpenSSLAsymmetricKey $key = null;
    private static string $publicKeyB64 = '';
    private static string $signature = '';

    private const MESSAGE = 'the exact bytes that were signed';

    public static function setUpBeforeClass(): void
    {
        $key = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        if ($key === false) {
            self::fail('Could not generate a P-256 key: ' . (string)openssl_error_string());
        }
        self::$key = $key;

        $details = openssl_pkey_get_details($key);
        if ($details === false) {
            self::fail('Could not read generated key details');
        }
        self::$publicKeyB64 = str_replace(
            ['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----', "\n"],
            '',
            $details['key']
        );

        $signature = '';
        if (!openssl_sign(self::MESSAGE, $signature, $key, OPENSSL_ALGO_SHA256)) {
            self::fail('Could not sign the fixture message');
        }
        self::$signature = $signature;
    }

    public function testTheThreeOutcomesStayDistinguishable(): void
    {
        // The whole point of the ?bool return: a wrong signature and an
        // installation that cannot check one are different answers, and
        // neither may be the permissive one.
        $this->assertTrue(
            EcdsaP256::verify(self::MESSAGE, self::$signature, self::$publicKeyB64),
            'a genuine signature verifies'
        );
        $this->assertFalse(
            EcdsaP256::verify('tampered ' . self::MESSAGE, self::$signature, self::$publicKeyB64),
            'a signature over other bytes is a rejection, not a shrug'
        );
        $this->assertFalse(
            EcdsaP256::verify(self::MESSAGE, 'not a DER ECDSA-Sig-Value', self::$publicKeyB64),
            'an unparseable signature blob is a rejection'
        );
        $this->assertNull(
            EcdsaP256::verify(self::MESSAGE, self::$signature, 'not-a-key'),
            'a verification key that will not load is an installation problem, not a verdict'
        );
        $this->assertNull(
            EcdsaP256::verify(self::MESSAGE, self::$signature, ''),
            'an empty configured key must not fall back to anything permissive'
        );
    }

    public function testASignatureThatCannotBeCheckedIsNeverReportedAsVerified(): void
    {
        if (!function_exists('exec')) {
            $this->markTestSkipped('exec() is disabled, so the child process cannot be started');
        }

        $probe = $this->probeWithoutOpenSslVerify();

        // Confirm the child really lost the primitive rather than assuming
        // the -d flag took effect: openssl_sign is still there (so the
        // extension is loaded) while the two functions verify() probes for
        // are gone (so disable_functions applied).
        if ($probe['has_openssl_sign'] !== true) {
            $this->markTestSkipped('The child process has no OpenSSL at all; disable_functions proves nothing here');
        }
        foreach (self::REQUIRED_FUNCTIONS as $function) {
            if ($probe['has_' . $function] !== false) {
                $this->markTestSkipped(
                    "-d disable_functions did not take effect: $function still exists in the child process"
                );
            }
        }

        // Every one of these verifies, fails, or cannot be parsed in a normal
        // process. A host that cannot run the primitive knows none of that,
        // so all four must read as "cannot judge".
        $this->assertNull($probe['genuine_signature'], 'a genuine signature must not read as verified');
        $this->assertNull($probe['wrong_signature'], 'a wrong signature must not read as verified');
        $this->assertNull($probe['absent_signature'], 'a missing signature must not read as verified');
        $this->assertNull($probe['broken_key'], 'a key that will not load must not read as verified');
    }

    /**
     * Run EcdsaP256::verify() in a child process that has no openssl_verify
     * and no openssl_pkey_get_public, and report what it answered.
     *
     * The child requires the class file directly — no composer autoloader,
     * no bootstrap — so the probe depends on nothing but the file under
     * test and cannot be diverted by a partially installed vendor/.
     *
     * @return array<string, bool|null>
     */
    private function probeWithoutOpenSslVerify(): array
    {
        $classFile = (new \ReflectionClass(EcdsaP256::class))->getFileName();
        $this->assertIsString($classFile, 'EcdsaP256 must be a file-backed class');

        $dir = sys_get_temp_dir() . '/p202-ecdsa-' . bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($dir, 0700, true), "could not create $dir");

        $scriptPath = $dir . '/probe.php';
        $resultPath = $dir . '/result.json';

        try {
            $this->assertNotFalse(
                file_put_contents($scriptPath, $this->probeScript($classFile, $resultPath)),
                'could not write the probe script'
            );

            $command = escapeshellarg(PHP_BINARY)
                . ' -d ' . escapeshellarg('disable_functions=' . implode(',', self::REQUIRED_FUNCTIONS))
                . ' ' . escapeshellarg($scriptPath)
                . ' 2>&1';
            $output = [];
            $exitCode = 0;
            exec($command, $output, $exitCode);
            $transcript = implode("\n", $output);

            $this->assertSame(0, $exitCode, "probe process failed:\n$transcript");
            $this->assertFileExists($resultPath, "probe wrote no result:\n$transcript");

            $json = file_get_contents($resultPath);
            $this->assertIsString($json);
            $probe = json_decode($json, true);
            $this->assertIsArray($probe, "probe result is not JSON: $json");

            foreach ([
                'has_openssl_verify',
                'has_openssl_pkey_get_public',
                'has_openssl_sign',
                'genuine_signature',
                'wrong_signature',
                'absent_signature',
                'broken_key',
            ] as $expected) {
                $this->assertArrayHasKey($expected, $probe, "probe result is missing $expected: $json");
            }

            return $probe;
        } finally {
            @unlink($scriptPath);
            @unlink($resultPath);
            @rmdir($dir);
        }
    }

    /**
     * The child script, with the fixture baked in — it has no way to sign
     * anything itself once the primitive is gone.
     */
    private function probeScript(string $classFile, string $resultPath): string
    {
        $values = [
            'class_file' => $classFile,
            'result_path' => $resultPath,
            'message' => self::MESSAGE,
            'signature_b64' => base64_encode(self::$signature),
            'public_key_b64' => self::$publicKeyB64,
        ];

        $literals = '';
        foreach ($values as $name => $value) {
            $literals .= sprintf("$%s = %s;\n", $name, var_export($value, true));
        }

        return <<<PHP
        <?php
        declare(strict_types=1);

        $literals
        require \$class_file;

        \$signature = base64_decode(\$signature_b64, true);
        if (\$signature === false) {
            fwrite(STDERR, "fixture signature is not base64\\n");
            exit(1);
        }

        \$result = [
            'has_openssl_verify' => function_exists('openssl_verify'),
            'has_openssl_pkey_get_public' => function_exists('openssl_pkey_get_public'),
            'has_openssl_sign' => function_exists('openssl_sign'),
            'genuine_signature' => \\Api\\V3\\Attribution\\EcdsaP256::verify(\$message, \$signature, \$public_key_b64),
            'wrong_signature' => \\Api\\V3\\Attribution\\EcdsaP256::verify('tampered ' . \$message, \$signature, \$public_key_b64),
            'absent_signature' => \\Api\\V3\\Attribution\\EcdsaP256::verify(\$message, null, \$public_key_b64),
            'broken_key' => \\Api\\V3\\Attribution\\EcdsaP256::verify(\$message, \$signature, 'not-a-key'),
        ];

        \$json = json_encode(\$result);
        if (\$json === false) {
            fwrite(STDERR, 'could not encode the probe result: ' . json_last_error_msg() . "\\n");
            exit(1);
        }
        if (file_put_contents(\$result_path, \$json) === false) {
            fwrite(STDERR, "could not write the probe result\\n");
            exit(1);
        }
        PHP;
    }
}
