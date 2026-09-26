<?php

declare(strict_types=1);

/**
 * Analyze › Mobile Apps: the Verify tab, a scratch pad for checking that a captured
 * postback's signature is genuine. Nothing is stored.
 *
 * @var array<string, mixed> $mobileReport
 * @var callable $e
 * @var callable $num
 * @var callable $link
 * @var callable $empty
 * @var callable $signatureTone
 * @var array<string, array{name: string, key: string, platform: string}> $appNames
 * @var string $notGiven
 * @var string $setupUrl
 */
?>
<!-- ── Verify ──────────────────────────────────────────────────── -->
<section class="p202-panel">
    <div class="p202-panel__head">
        <h2 class="p202-panel__title">Check a postback's signature</h2>
        <p class="p202-panel__sub">Paste the JSON a device sent. Nothing is stored, and nothing here changes your report.</p>
    </div>
    <div class="p202-panel__body">
        <?php /* No CSRF token, and deliberately: this POST stores nothing
                 and changes nothing — it hands a pasted string to the
                 signature verifier and prints the verdict. A forged one
                 would make the victim's browser render a page. Every POST
                 on this site that writes carries a token. */ ?>
        <form method="post" action="<?php echo $e($link(['view' => 'verify'])); ?>">
            <div class="mb-3">
                <label class="form-label" for="payload">Postback JSON</label>
                <textarea class="form-control font-monospace" id="payload" name="payload" rows="10"
                          placeholder='{"version":"4.0","ad-network-id":"example.skadnetwork", ...}'><?php echo $e($mobileReport['verifyPayload'] ?? ''); ?></textarea>
                <div class="form-text">An AdAttributionKit postback is the object containing <code>jws-string</code>; a SKAdNetwork one is the object Apple POSTs.</div>
            </div>
            <div class="p202-form-actions">
                <button class="btn btn-primary" type="submit">Check signature</button>
            </div>
        </form>

        <?php $verify = $mobileReport['verify'] ?? null; ?>
        <?php if ($verify !== null) { ?>
            <hr>
            <div class="p202-strip">
                <div class="p202-strip__row">
                    <span class="<?php echo $e($signatureTone($verify['signature'] ?? '')); ?>"><?php echo $e((string)($verify['signature'] ?? 'unknown')); ?></span>
                    <span class="p202-strip__label">Signature</span>
                    <span class="p202-strip__value"><?php echo $e((string)($verify['protocol'] ?? '')); ?></span>
                </div>
                <?php if (isset($verify['key_id'])) { ?>
                    <div class="p202-strip__row">
                        <span class="p202-pill">key</span>
                        <span class="p202-strip__label">Signing key</span>
                        <span class="p202-strip__value"><code><?php echo $e((string)$verify['key_id']); ?></code></span>
                    </div>
                <?php } ?>
            </div>

            <?php if (array_key_exists('signed_message_base64', $verify)) { ?>
                <?php if ($verify['signed_message_base64'] !== null) { ?>
                    <h3 class="p202-panel__title mt-4">Signed message</h3>
                    <p class="text-secondary small">Base64 of the exact bytes Apple signed, for diffing against another implementation when a signature unexpectedly fails.</p>
                    <div class="p202-code">
                        <pre class="p202-code__value mb-0"><?php echo $e((string)$verify['signed_message_base64']); ?></pre>
                        <button type="button" class="btn btn-sm btn-outline-secondary p202-copy" data-p202-copy="<?php echo $e((string)$verify['signed_message_base64']); ?>">Copy</button>
                    </div>
                <?php } else { ?>
                    <?php /* A verdict with no signed message is the one
                             result that looks like a bug from outside: the
                             answer above says the signature did not check
                             out, and the reason is that there was nothing
                             to check it against. Say which. */ ?>
                    <div class="alert alert-warning p202-flash mt-4" role="status">
                        <i class="bi bi-exclamation-triangle"></i>
                        <div class="p202-flash__body">
                            The bytes Apple signs could not be rebuilt from this postback, so the signature was never really tested.
                            <?php $pastedVersion = $mobileReport['verifyVersion'] ?? null; ?>
                            <?php if ($pastedVersion === null) { ?>
                                It carries no <code>version</code>, and the fields that are signed depend on which one it is.
                            <?php } else { ?>
                                Either a field version <code><?php echo $e($pastedVersion); ?></code> requires is missing, or that is not a version this install can check.
                            <?php } ?>
                            Checkable versions: <?php echo $e(implode(', ', (array)($verify['verifiable_versions'] ?? []))); ?>.
                        </div>
                    </div>
                <?php } ?>
            <?php } ?>

            <?php if (($verify['signature'] ?? '') === 'unverifiable' && isset($verify['key_id'])) { ?>
                <div class="alert alert-warning p202-flash mt-4" role="status">
                    <i class="bi bi-exclamation-triangle"></i>
                    <div class="p202-flash__body">
                        <code><?php echo $e((string)$verify['key_id']); ?></code> is not a signing key this install knows, so the signature could not be judged either way.
                        Production keys here: <?php echo $e(implode(', ', (array)($verify['known_key_ids'] ?? []))); ?>.
                        Development keys: <?php echo $e(implode(', ', (array)($verify['development_key_ids'] ?? []))); ?>.
                    </div>
                </div>
            <?php } ?>

            <?php if (isset($verify['payload']) && is_array($verify['payload'])) { ?>
                <h3 class="p202-panel__title mt-4">What the postback claims</h3>
                <?php $claims = json_encode($verify['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); ?>
                <div class="p202-code">
                    <pre class="p202-code__value mb-0"><?php echo $e($claims === false ? '(could not be re-encoded)' : $claims); ?></pre>
                </div>
            <?php } ?>
        <?php } ?>
    </div>
</section>
