<?php
declare(strict_types=1);

/**
 * Dashboard API Client
 * Handles communication with my.tracking202.com API endpoints
 */
class DashboardAPI
{
    private readonly string $baseUrl;
    private readonly int $timeout;
    private readonly int $maxRetries;

    public function __construct()
    {
        $this->baseUrl = DASHBOARD_API_URL;
        $this->timeout = 10; // 10 seconds
        $this->maxRetries = 3;
    }

    /**
     * Fetch data from a specific endpoint
     * @param string $endpoint The API endpoint (e.g., 'alerts', 'tweets', 'posts', 'meetups', 'sponsors')
     * @return array|null Returns decoded JSON data or null on failure
     */
    public function fetchEndpoint(string $endpoint): ?array
    {
        $url = $this->baseUrl . '/' . $endpoint;
        
        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            $result = $this->makeRequest($url);
            
            if ($result !== null) {
                return $result;
            }
            
            // Wait before retry (exponential backoff)
            if ($attempt < $this->maxRetries) {
                sleep($attempt * 2);
            }
        }
        
        error_log("DashboardAPI: Failed to fetch {$endpoint} after {$this->maxRetries} attempts");
        return null;
    }

    /**
     * Make HTTP request to API
     * @param string $url The full URL to request
     * @return array|null Returns decoded JSON data or null on failure
     */
    private function makeRequest(string $url): ?array
    {
        // Initialize cURL
        $ch = curl_init();
        
        if ($ch === false) {
            error_log("DashboardAPI: Failed to initialize cURL");
            return null;
        }
        
        // Set cURL options
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT => 'Prosper202-Dashboard/1.0',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json'
            ]
        ]);
        
        // Execute request
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        // Check for cURL errors
        if ($response === false || !empty($error)) {
            error_log("DashboardAPI: cURL error for {$url}: {$error}");
            return null;
        }
        
        // Check HTTP status code
        if ($httpCode !== 200) {
            error_log("DashboardAPI: HTTP {$httpCode} error for {$url}");
            return null;
        }
        
        // Validate and decode JSON
        if (!is_string($response) || empty($response)) {
            error_log("DashboardAPI: Empty response from {$url}");
            return null;
        }
        
        $decoded = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("DashboardAPI: JSON decode error for {$url}: " . json_last_error_msg());
            return null;
        }
        
        // Validate response structure
        if (!is_array($decoded)) {
            error_log("DashboardAPI: Invalid response format from {$url}");
            return null;
        }
        
        return $decoded;
    }

    /**
     * Fetch alerts from API
     * @return array|null
     */
    public function fetchAlerts(): ?array
    {
        return $this->fetchEndpoint('alerts');
    }

    /**
     * Fetch tweets from API
     * @return array|null
     */
    public function fetchTweets(): ?array
    {
        return $this->fetchEndpoint('social/tweets');
    }

    /**
     * Fetch blog posts from API
     * @return array|null
     */
    public function fetchPosts(): ?array
    {
        return $this->fetchEndpoint('blog/posts');
    }

    /**
     * Fetch meetup events from API
     * @return array|null
     */
    public function fetchMeetups(): ?array
    {
        return $this->fetchEndpoint('events/meetups');
    }

    /**
     * Fetch sponsors from API
     * @return array|null
     */
    public function fetchSponsors(): ?array
    {
        return $this->fetchEndpoint('sponsors');
    }
}