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

        $response = @file_get_contents($url, false, $context);
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
}
