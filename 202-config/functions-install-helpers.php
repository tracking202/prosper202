<?php

declare(strict_types=1);

/**
 * Pure, dependency-free helpers for the installer.
 *
 * These are kept in their own file (rather than inline in install.php, which runs
 * the whole install on include) so the field rules, CSRF check and account
 * validation can be unit-tested in isolation.
 */

if (!function_exists('install_default_rules')) {
    /**
     * Single source of truth for the install field rules, shared by the server-side
     * validation and injected into the client-side JS so the two can't drift.
     *
     * @return array{username_min:int,username_max:int,password_min:int,password_max:int}
     */
    function install_default_rules(): array
    {
        return [
            'username_min' => 4,
            'username_max' => 20,
            'password_min' => 6,
            'password_max' => 35,
        ];
    }
}

if (!function_exists('install_csrf_ok')) {
    /**
     * Constant-time CSRF token comparison. An empty expected token (no token ever
     * issued for this session) never matches.
     */
    function install_csrf_ok(string $expected, string $submitted): bool
    {
        return $expected !== '' && hash_equals($expected, $submitted);
    }
}

if (!function_exists('install_validate_account')) {
    /**
     * Validate the install account form. Returns a map of field => HTML error string
     * ('' when the field is valid), matching the markup install.php renders inline.
     *
     * Mirrors the original inline checks exactly, including the empty()/isset()
     * password semantics, so extracting them changed no behavior.
     *
     * @param  array<string,mixed> $post  raw $_POST
     * @param  array{username_min:int,username_max:int,password_min:int,password_max:int} $rules
     * @return array{user_email:string,user_name:string,user_pass:string}
     */
    function install_validate_account(array $post, array $rules): array
    {
        $errors = ['user_email' => '', 'user_name' => '', 'user_pass' => ''];

        $email = (string) ($post['user_email'] ?? '');
        $name  = (string) ($post['user_name'] ?? '');

        // Prefer the shared check_email_address() in production so the email rule
        // can't drift from the rest of the app (CLAUDE.md #5); fall back to the
        // identical filter_var only when this helper is loaded in isolation (tests).
        $emailValid = function_exists('check_email_address')
            ? (bool) check_email_address($email)
            : filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
        if (!$emailValid) {
            $errors['user_email'] = '<div class="error">Please enter a valid email address</div>';
        }

        if ($name === '') {
            $errors['user_name'] = '<div class="error">You must type in your desired username</div>';
        }
        if (!ctype_alnum($name)) {
            $errors['user_name'] .= '<div class="error">Your username may only contain alphanumeric characters</div>';
        }
        if (strlen($name) < $rules['username_min'] || strlen($name) > $rules['username_max']) {
            $errors['user_name'] .= '<div class="error">Your username must be between ' . $rules['username_min'] . ' and ' . $rules['username_max'] . ' characters long</div>';
        }

        // Preserve the original isset()/empty() password semantics.
        $hasPass   = isset($post['user_pass']) && !empty($post['user_pass']);
        $hasVerify = isset($post['verify_user_pass']) && !empty($post['verify_user_pass']);

        if (!$hasPass) {
            $errors['user_pass'] = '<div class="error">You must type in your desired password</div>';
        }
        if (!$hasVerify) {
            $errors['user_pass'] .= '<div class="error">You must verify your password</div>';
        }
        if ($hasPass) {
            $len = strlen((string) $post['user_pass']);
            if ($len < $rules['password_min']) {
                $errors['user_pass'] .= '<div class="error">Your password must be at least ' . $rules['password_min'] . ' characters long</div>';
            } elseif ($len > $rules['password_max']) {
                $errors['user_pass'] .= '<div class="error">Your password must be no more than ' . $rules['password_max'] . ' characters long</div>';
            }
        }
        if (isset($post['user_pass'], $post['verify_user_pass']) && $post['user_pass'] != $post['verify_user_pass']) {
            $errors['user_pass'] .= '<div class="error">Your passwords did not match, please try again</div>';
        }

        return $errors;
    }
}

if (!function_exists('install_encode_response')) {
    /**
     * Encode an install AJAX payload to JSON. On a json_encode() failure (e.g. a
     * value containing malformed UTF-8) it returns an explicit error body rather
     * than an empty one (CLAUDE.md #4).
     *
     * @param  array<string,mixed> $payload
     * @return array{ok:bool,body:string}
     */
    function install_encode_response(array $payload): array
    {
        $json = json_encode($payload);
        if ($json === false) {
            return [
                'ok'   => false,
                'body' => '{"success":false,"retryable":true,"errors":{"general":"<div class=\"error\">The server hit an unexpected error encoding its response. Please try again.</div>"}}',
            ];
        }

        return ['ok' => true, 'body' => $json];
    }
}

if (!function_exists('install_request_base_url')) {
    /**
     * Build the absolute base URL (scheme://host + app path) for the success screen's
     * cron line and CLI connection hints. Prefers the Host header so a non-default
     * port (e.g. Docker's :8000) or vhost is preserved — SERVER_NAME drops the port,
     * and inside a container SERVER_PORT is the internal 80, not the mapped port the
     * user sees. $server is injected so this stays testable; callers MUST escape the
     * result before emitting it (a forged Host header is attacker-controlled).
     *
     * @param array<string,mixed> $server $_SERVER
     * @param string               $base   get_absolute_url() (the app path)
     */
    function install_request_base_url(array $server, string $base): string
    {
        $scheme = (isset($server['HTTPS']) && strtolower((string) $server['HTTPS']) === 'on') ? 'https' : 'http';
        $host = (string) ($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? 'localhost');
        return $scheme . '://' . $host . $base;
    }
}

if (!function_exists('render_install_success')) {
    /**
     * Render the post-install success panel (the .main block) so it can be swapped
     * into the page over AJAX or printed directly on the no-JS path. The base URL
     * and server name are injected (no globals) so the markup can be unit-tested.
     *
     * $html['user_name'] is expected pre-escaped and $base is a trusted internal URL
     * (get_absolute_url()); both are emitted as-is. $serverName and $baseUrl are
     * escaped here. $html['rest_api_key'] is expected pre-escaped (an htmlentities
     * value); $warnings are developer-authored, safe HTML strings.
     *
     * @param array<string,mixed> $html
     * @param list<string>        $warnings
     */
    function render_install_success(array $html, array $warnings, string $base, string $serverName, string $baseUrl): void
    {
        $safeServerName = htmlentities($serverName, ENT_QUOTES, 'UTF-8');
        $cron_line = '* * * * * curl -s "' . $baseUrl . '202-cronjobs/index.php" >/dev/null 2>&1';
        ?>
        <div class="main col-xs-7 install">
            <center><img src="<?php echo $base; ?>202-img/prosper202.png"></center>
            <h6>Success!</h6>
            <small>Prosper202 has been installed. Now you can <a href="<?php echo $base; ?>202-login.php">log in</a>.</small><br></br>
            <div class="row" style="margin-bottom: 10px;">
                <div class="col-xs-3"><span class="label label-default">Username:</span></div>
                <div class="col-xs-9"><span class="label label-primary"><?php echo $html['user_name']; ?></span></div>
            </div>
            <div class="row" style="margin-bottom: 10px;">
                <div class="col-xs-3"><span class="label label-default">Login address:</span></div>
                <div class="col-xs-9"><small><?php printf('<a href="%s202-login.php">%s202-login.php</a>', $base, $safeServerName . $base); ?></small></div>
            </div>

            <h6 style="margin-top: 20px;">Keep background jobs running (cron)</h6>
            <small>Reports, attribution and emails run on a schedule. If you used Docker this is already handled by the <code>cron</code> service. Otherwise, add this one line to your crontab (<code>crontab -e</code>):</small>
            <pre style="white-space: pre-wrap; word-break: break-all; font-size: 11px;"><?php echo htmlspecialchars($cron_line, ENT_QUOTES, 'UTF-8'); ?></pre>

            <?php if (($html['rest_api_key'] ?? '') !== '') { ?>
            <h6 style="margin-top: 20px;">Connect the CLI / Claude onboarding (optional)</h6>
            <small>Use this REST API key with the <code>p202</code> CLI or the Claude <code>/onboard-prosper202</code> skill to finish setup hands-free. <strong>Copy it now</strong> — for security it isn't shown again (you can always generate a new one under Account &rarr; REST API Keys).</small>
            <div class="row" style="margin-top: 8px; margin-bottom: 6px;">
                <div class="col-xs-3"><span class="label label-default">Instance URL:</span></div>
                <div class="col-xs-9"><small><code><?php echo htmlspecialchars(rtrim($baseUrl, '/'), ENT_QUOTES, 'UTF-8'); ?></code></small> &mdash; use with <code>p202 config set-url</code> (the CLI appends <code>/api/v3</code> itself)</div>
            </div>
            <div class="row" style="margin-bottom: 6px;">
                <div class="col-xs-3"><span class="label label-default">User ID:</span></div>
                <div class="col-xs-9"><small><code><?php echo (int) ($html['user_id'] ?? 0); ?></code></small></div>
            </div>
            <div class="row" style="margin-bottom: 6px;">
                <div class="col-xs-3"><span class="label label-default">API key:</span></div>
                <div class="col-xs-9"><small><code style="word-break: break-all;"><?php echo $html['rest_api_key']; ?></code></small></div>
            </div>
            <?php } ?>

            <?php if ($warnings) { ?>
                <div style="margin: 12px 0; padding: 8px 12px; border: 1px solid #faebcc; background: #fcf8e3; color: #8a6d3b; border-radius: 4px; font-size: 12px;">
                    <strong>You're all set — a couple of optional steps need a quick follow-up:</strong>
                    <ul style="margin: 6px 0 0 18px;">
                        <?php foreach ($warnings as $w) { echo '<li>' . $w . '</li>'; } ?>
                    </ul>
                </div>
            <?php } ?>
            <p><small>Were you expecting more steps? Sorry, that's it!</small></p>
        </div>
        <?php
    }
}
