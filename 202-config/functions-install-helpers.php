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

        // Mirrors check_email_address() (filter_var FILTER_VALIDATE_EMAIL).
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
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
