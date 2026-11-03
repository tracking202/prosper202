<?php

declare(strict_types=1);

// Release signing (Ed25519, detached). See RELEASING.md, "Signed releases".
//   sign <zip>...       writes <zip>.sig with the key in $P202_RELEASE_SIGNING_KEY
//   keygen <key-file>   writes a new secret key to <key-file>, prints the public key

require dirname(__DIR__, 2) . '/202-config/Update/ReleaseSignature.php';

use Prosper202\Update\ReleaseSignature;

$fail = static function (string $message): never {
    fwrite(STDERR, "::error::sign-release: {$message}\n");
    exit(1);
};

$command = $argv[1] ?? '';
$paths = array_slice($argv, 2);

if (!function_exists('sodium_crypto_sign_detached')) {
    $fail('PHP has no sodium extension');
}

if ($command === 'keygen') {
    if (count($paths) !== 1) {
        $fail('usage: sign-release.php keygen <secret-key-file>');
    }
    $file = $paths[0];
    if (file_exists($file)) {
        $fail("{$file} already exists; not overwriting a key");
    }
    $pair = sodium_crypto_sign_keypair();
    umask(0077);
    $handle = fopen($file, 'x');
    if ($handle === false || !chmod($file, 0600)) {
        $fail("cannot create {$file}");
    }
    $secretKey = base64_encode(sodium_crypto_sign_secretkey($pair));
    $written = fwrite($handle, $secretKey);
    if ($written !== strlen($secretKey) || !fclose($handle)) {
        @unlink($file);
        $fail("cannot write {$file}");
    }
    echo base64_encode(sodium_crypto_sign_publickey($pair)), PHP_EOL;
    exit(0);
}

if ($command !== 'sign' || $paths === []) {
    $fail('usage: sign-release.php sign <zip>... | keygen <secret-key-file>');
}

$secret = getenv('P202_RELEASE_SIGNING_KEY');
if (!is_string($secret) || trim($secret) === '') {
    $fail('the P202_RELEASE_SIGNING_KEY secret is not set; a release is never published unsigned');
}

foreach ($paths as $zip) {
    $bytes = @file_get_contents($zip);
    if (!is_string($bytes) || $bytes === '') {
        $fail("cannot read {$zip}");
    }
    try {
        $signature = ReleaseSignature::sign($bytes, $secret);
    } catch (\RuntimeException $e) {
        $fail($e->getMessage());
    }
    if (file_put_contents($zip . '.sig', $signature . "\n") === false) {
        $fail("cannot write {$zip}.sig");
    }
    echo "signed {$zip} -> {$zip}.sig\n";
}
