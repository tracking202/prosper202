<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Export;

final class WebhookDispatcher
{
    public function dispatch(ExportJob $job, string $filePath): WebhookResult
    {
        if ($job->webhookUrl === null || $job->webhookUrl === '') {
            return new WebhookResult(null, null, null);
        }

        // Validate webhook URL to prevent SSRF attacks
        $validationError = $this->validateWebhookUrl($job->webhookUrl);
        if ($validationError !== null) {
            return new WebhookResult(null, null, $validationError);
        }

        if (!is_file($filePath) || !is_readable($filePath)) {
            return new WebhookResult(null, null, 'Export file is not readable.');
        }

        $body = [
            'export_id' => $job->exportId,
            'model_id' => $job->modelId,
            'format' => $job->format->value,
            'status' => $job->status->value,
            'generated_at' => time(),
            'file_name' => basename($filePath),
            'file_mime' => $job->format === ExportFormat::CSV ? 'text/csv' : 'application/vnd.ms-excel',
            'file_content' => base64_encode((string) file_get_contents($filePath)),
        ];

        $headers = array_merge(['Content-Type' => 'application/json'], $job->webhookHeaders);
        $method = strtoupper($job->webhookMethod ?: 'POST');

        if (function_exists('curl_init')) {
            return $this->dispatchWithCurl($job->webhookUrl, $method, $headers, json_encode($body, JSON_THROW_ON_ERROR));
        }

        return $this->dispatchWithStream($job->webhookUrl, $method, $headers, json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function dispatchWithCurl(string $url, string $method, array $headers, string $payload): WebhookResult
    {
        $curl = curl_init($url);
        if ($curl === false) {
            return new WebhookResult(null, null, 'Unable to initialise cURL.');
        }

        $headerList = [];
        foreach ($headers as $key => $value) {
            $headerList[] = $key . ': ' . $value;
        }

        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerList,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($curl);
        $error = curl_error($curl);
        $statusCode = curl_getinfo($curl, CURLINFO_RESPONSE_CODE) ?: null;
        curl_close($curl);

        if ($response === false) {
            return new WebhookResult($statusCode, null, $error ?: 'Unknown cURL error.');
        }

        return new WebhookResult($statusCode, $response, null);
    }

    private function dispatchWithStream(string $url, string $method, array $headers, string $payload): WebhookResult
    {
        $headerLines = [];
        foreach ($headers as $key => $value) {
            $headerLines[] = $key . ': ' . $value;
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headerLines),
                'content' => $payload,
                'timeout' => 15,
            ],
        ]);

        $response = file_get_contents($url, false, $context);
        $error = $response === false ? error_get_last()['message'] ?? 'Unknown stream error.' : null;
        $statusCode = null;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $line) {
                if (preg_match('#HTTP/\S+\s+(\d{3})#', $line, $matches)) {
                    $statusCode = (int) $matches[1];
                    break;
                }
            }
        }

        return new WebhookResult($statusCode, $response ?: null, $error);
    }

    /**
     * Validates webhook URL to prevent SSRF attacks.
     * 
     * @param string $url The URL to validate
     * @return string|null Error message if validation fails, null if valid
     */
    private function validateWebhookUrl(string $url): ?string
    {
        // Parse the URL
        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['scheme']) || !isset($parsed['host'])) {
            return 'Invalid webhook URL format.';
        }

        // Only allow http and https schemes
        $scheme = strtolower($parsed['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return 'Webhook URL must use http or https scheme.';
        }

        $host = $parsed['host'];

        // Resolve hostname to IP address if it's not already an IP
        $ip = $host;
        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            // It's a hostname, resolve it
            $resolvedIp = gethostbyname($host);
            if ($resolvedIp === $host) {
                // DNS resolution failed - reject to prevent bypassing IP-based restrictions
                return 'Webhook URL hostname could not be resolved.';
            }
            $ip = $resolvedIp;
        }

        // Validate the IP address
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $this->validateIPv4Address($ip);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $this->validateIPv6Address($ip);
        }

        return 'Webhook URL resolves to an invalid IP address.';
    }

    /**
     * Validates an IPv4 address to ensure it's not in private or reserved ranges.
     * 
     * @param string $ip The IPv4 address to validate
     * @return string|null Error message if validation fails, null if valid
     */
    private function validateIPv4Address(string $ip): ?string
    {
        // Use filter_var for validation which is reliable across different architectures
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            // Check if it's a private or reserved range
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                // It's a valid IPv4 but in private or reserved range
                $ipLong = ip2long($ip);
                
                // Additional specific checks for clarity in error messages
                // 10.0.0.0/8 - Private network
                if (($ipLong & 0xFF000000) === 0x0A000000) {
                    return 'Webhook URL cannot target private IP ranges (10.0.0.0/8).';
                }
                
                // 172.16.0.0/12 - Private network
                if (($ipLong & 0xFFF00000) === 0xAC100000) {
                    return 'Webhook URL cannot target private IP ranges (172.16.0.0/12).';
                }
                
                // 192.168.0.0/16 - Private network
                if (($ipLong & 0xFFFF0000) === 0xC0A80000) {
                    return 'Webhook URL cannot target private IP ranges (192.168.0.0/16).';
                }
                
                // 169.254.0.0/16 - Link-local
                if (($ipLong & 0xFFFF0000) === 0xA9FE0000) {
                    return 'Webhook URL cannot target link-local addresses (169.254.0.0/16).';
                }
                
                // 127.0.0.0/8 - Loopback
                if (($ipLong & 0xFF000000) === 0x7F000000) {
                    return 'Webhook URL cannot target loopback addresses (127.0.0.0/8).';
                }
                
                // Generic message for other private/reserved ranges
                return 'Webhook URL cannot target private or reserved IP addresses.';
            }
            return 'Invalid IPv4 address.';
        }

        return null;
    }

    /**
     * Validates an IPv6 address to ensure it's not in private or reserved ranges.
     * 
     * @param string $ip The IPv6 address to validate
     * @return string|null Error message if validation fails, null if valid
     */
    private function validateIPv6Address(string $ip): ?string
    {
        // Use filter_var for initial validation
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                // It's a valid IPv6 but in private or reserved range
                $binary = inet_pton($ip);
                if ($binary === false) {
                    return 'Invalid IPv6 address.';
                }

                $firstByte = ord($binary[0]);

                // ::1/128 - Loopback
                if ($binary === inet_pton('::1')) {
                    return 'Webhook URL cannot target loopback addresses (::1).';
                }

                // ::/128 - Unspecified
                if ($binary === inet_pton('::')) {
                    return 'Webhook URL cannot target unspecified addresses (::).';
                }

                // fe80::/10 - Link-local
                if ($firstByte === 0xFE && (ord($binary[1]) & 0xC0) === 0x80) {
                    return 'Webhook URL cannot target link-local addresses (fe80::/10).';
                }

                // fc00::/7 - Unique local addresses
                if (($firstByte & 0xFE) === 0xFC) {
                    return 'Webhook URL cannot target unique local addresses (fc00::/7).';
                }

                // ff00::/8 - Multicast
                if ($firstByte === 0xFF) {
                    return 'Webhook URL cannot target multicast addresses (ff00::/8).';
                }

                // Generic message for other private/reserved ranges
                return 'Webhook URL cannot target private or reserved IPv6 addresses.';
            }
            return 'Invalid IPv6 address.';
        }

        return null;
    }
}
