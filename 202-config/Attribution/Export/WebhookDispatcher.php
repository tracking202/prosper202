<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Export;

final class WebhookDispatcher
{
    /**
     * Maximum file size for webhook dispatch (10MB default).
     * Files larger than this will not be dispatched to prevent memory exhaustion.
     */
    private const MAX_FILE_SIZE_BYTES = 10 * 1024 * 1024;

    /**
     * Chunk size for reading files (1MB).
     * Files are read and base64-encoded in chunks to reduce memory usage.
     */
    private const CHUNK_SIZE_BYTES = 1024 * 1024;

    public function dispatch(ExportJob $job, string $filePath): WebhookResult
    {
        if ($job->webhookUrl === null || $job->webhookUrl === '') {
            return new WebhookResult(null, null, null);
        }

        if (!is_file($filePath) || !is_readable($filePath)) {
            return new WebhookResult(null, null, 'Export file is not readable.');
        }

        $fileSize = filesize($filePath);
        if ($fileSize === false || $fileSize > self::MAX_FILE_SIZE_BYTES) {
            $maxSizeMB = self::MAX_FILE_SIZE_BYTES / (1024 * 1024);
            return new WebhookResult(
                null,
                null,
                sprintf('Export file exceeds maximum size limit of %d MB for webhook dispatch.', $maxSizeMB)
            );
        }

        $base64Content = $this->encodeFileBase64($filePath);
        if ($base64Content === null) {
            return new WebhookResult(null, null, 'Failed to encode export file.');
        }

        $body = [
            'export_id' => $job->exportId,
            'model_id' => $job->modelId,
            'format' => $job->format->value,
            'status' => $job->status->value,
            'generated_at' => time(),
            'file_name' => basename($filePath),
            'file_mime' => $job->format === ExportFormat::CSV ? 'text/csv' : 'application/vnd.ms-excel',
            'file_content' => $base64Content,
        ];

        $headers = array_merge(['Content-Type' => 'application/json'], $job->webhookHeaders);
        $method = strtoupper($job->webhookMethod ?: 'POST');

        if (function_exists('curl_init')) {
            return $this->dispatchWithCurl($job->webhookUrl, $method, $headers, json_encode($body, JSON_THROW_ON_ERROR));
        }

        return $this->dispatchWithStream($job->webhookUrl, $method, $headers, json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * Encode file content to base64 using chunked reading to minimize memory usage.
     */
    private function encodeFileBase64(string $filePath): ?string
    {
        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            return null;
        }

        $base64 = '';
        $remainder = '';

        while (!feof($handle)) {
            $chunk = fread($handle, self::CHUNK_SIZE_BYTES);
            if ($chunk === false) {
                fclose($handle);
                return null;
            }

            // Combine with any remainder from previous iteration
            $data = $remainder . $chunk;

            // Base64 encoding works best with data length divisible by 3
            // to avoid padding issues between chunks
            $dataLength = strlen($data);
            $encodeLength = $dataLength - ($dataLength % 3);

            if ($encodeLength > 0) {
                $base64 .= base64_encode(substr($data, 0, $encodeLength));
                $remainder = substr($data, $encodeLength);
            } else {
                $remainder = $data;
            }
        }

        // Encode any remaining data
        if ($remainder !== '') {
            $base64 .= base64_encode($remainder);
        }

        fclose($handle);
        return $base64;
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
