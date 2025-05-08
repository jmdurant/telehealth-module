<?php
/**
 * Telesalud API Client
 *
 * This class provides a comprehensive wrapper around the telesalud backend API,
 * centralizing all API communication and providing robust error handling,
 * logging, and convenience methods for all supported operations.
 *
 * @package OpenEMR
 * @subpackage Telehealth
 * @author OpenEMR Telehealth Module
 * @copyright Copyright (c) 2023
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace Telehealth\Classes;

use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Common\Csrf\CsrfUtils;

class TelesaludClient
{
    // API endpoints
    private const ENDPOINT_VIDEOCONSULTATIONS = '/videoconsultations';
    private const ENDPOINT_MEETINGS = '/meetings';
    private const ENDPOINT_STATUS = '/status';
    
    // HTTP methods
    private const METHOD_GET = 'GET';
    private const METHOD_POST = 'POST';
    private const METHOD_PUT = 'PUT';
    private const METHOD_DELETE = 'DELETE';
    
    // Response codes
    private const HTTP_OK = 200;
    private const HTTP_CREATED = 201;
    private const HTTP_NO_CONTENT = 204;
    private const HTTP_BAD_REQUEST = 400;
    private const HTTP_UNAUTHORIZED = 401;
    private const HTTP_NOT_FOUND = 404;
    private const HTTP_SERVER_ERROR = 500;
    
    /** @var string Base URL for the telesalud API */
    private $baseUrl;
    
    /** @var string API token for authentication */
    private $apiToken;
    
    /** @var SystemLogger Logger instance */
    private $logger;
    
    /** @var int Maximum number of retry attempts for failed requests */
    private $maxRetries = 3;
    
    /** @var array Default request options */
    private $defaultOptions = [
        'timeout' => 10,
        'connect_timeout' => 5,
    ];
    
    /**
     * Constructor
     *
     * @param string $baseUrl Base URL for the telesalud API
     * @param string $apiToken API token for authentication
     * @param SystemLogger|null $logger Logger instance (optional)
     */
    public function __construct(string $baseUrl, string $apiToken, $logger = null)
    {
        // Normalize base URL (remove trailing slash if present)
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiToken = $apiToken;
        
        // Use provided logger or create a new one
        $this->logger = $logger ?? new SystemLogger();
    }
    
    /**
     * Create a new meeting in the telesalud backend
     *
     * @param int $encounterId OpenEMR encounter ID
     * @param string $providerName Name of the provider
     * @param string $patientName Name of the patient
     * @param string $startTime Start time of the meeting (ISO 8601 format)
     * @param array $options Additional options for the meeting
     * @return array Meeting data including URL and backend_id
     * @throws \Exception If the request fails
     */
    public function createMeeting(int $encounterId, string $providerName, string $patientName, string $startTime, array $options = []): array
    {
        $data = [
            'encounter_id' => $encounterId,
            'provider_name' => $providerName,
            'patient_name' => $patientName,
            'start_time' => $startTime,
            'options' => $options,
        ];
        
        $response = $this->request(self::METHOD_POST, self::ENDPOINT_VIDEOCONSULTATIONS, $data);
        
        if (!isset($response['id'], $response['url'])) {
            throw new \Exception("Invalid response from telesalud API when creating meeting");
        }
        
        return [
            'backend_id' => $response['id'],
            'meeting_url' => $response['url'],
            'raw_response' => $response,
        ];
    }
    
    /**
     * Get meeting details from the telesalud backend
     *
     * @param string $meetingId Backend meeting ID
     * @return array Meeting details
     * @throws \Exception If the request fails or meeting is not found
     */
    public function getMeeting(string $meetingId): array
    {
        return $this->request(self::METHOD_GET, self::ENDPOINT_VIDEOCONSULTATIONS . '/' . $meetingId);
    }
    
    /**
     * List meetings with optional filters
     *
     * @param array $filters Optional filters for the meeting list
     * @param int $page Page number for pagination
     * @param int $perPage Number of items per page
     * @return array List of meetings
     * @throws \Exception If the request fails
     */
    public function listMeetings(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $queryParams = array_merge($filters, [
            'page' => $page,
            'per_page' => $perPage,
        ]);
        
        $url = self::ENDPOINT_VIDEOCONSULTATIONS . '?' . http_build_query($queryParams);
        return $this->request(self::METHOD_GET, $url);
    }
    
    /**
     * Update a meeting in the telesalud backend
     *
     * @param string $meetingId Backend meeting ID
     * @param array $data Data to update
     * @return array Updated meeting data
     * @throws \Exception If the request fails
     */
    public function updateMeeting(string $meetingId, array $data): array
    {
        return $this->request(self::METHOD_PUT, self::ENDPOINT_VIDEOCONSULTATIONS . '/' . $meetingId, $data);
    }
    
    /**
     * Finish a meeting in the telesalud backend
     *
     * @param string $meetingId Backend meeting ID
     * @param string|null $notes Optional clinical notes from the consultation
     * @return array Response data
     * @throws \Exception If the request fails
     */
    public function finishMeeting(string $meetingId, ?string $notes = null): array
    {
        $data = [];
        if ($notes !== null) {
            $data['evolution'] = $notes;
        }
        
        return $this->request(self::METHOD_POST, self::ENDPOINT_VIDEOCONSULTATIONS . '/' . $meetingId . '/finish', $data);
    }
    
    /**
     * Get meeting recording if available
     *
     * @param string $meetingId Backend meeting ID
     * @return array Recording data including URL
     * @throws \Exception If the request fails or recording is not available
     */
    public function getMeetingRecording(string $meetingId): array
    {
        return $this->request(self::METHOD_GET, self::ENDPOINT_VIDEOCONSULTATIONS . '/' . $meetingId . '/recording');
    }
    
    /**
     * Get server status
     *
     * @return array Status information
     * @throws \Exception If the request fails
     */
    public function getServerStatus(): array
    {
        return $this->request(self::METHOD_GET, self::ENDPOINT_STATUS);
    }
    
    /**
     * Test the connection to the telesalud backend
     *
     * @return bool True if connection is successful
     */
    public function testConnection(): bool
    {
        try {
            $response = $this->request(self::METHOD_GET, self::ENDPOINT_STATUS);
            return isset($response['status']) && $response['status'] === 'ok';
        } catch (\Exception $e) {
            $this->logger->error("Telesalud connection test failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get WebSocket connection details
     *
     * @return array WebSocket connection details
     * @throws \Exception If the request fails
     */
    public function getWebSocketDetails(): array
    {
        $response = $this->request(self::METHOD_GET, '/websocket/auth');
        
        if (!isset($response['url'], $response['token'])) {
            throw new \Exception("Invalid response from telesalud API when getting WebSocket details");
        }
        
        return [
            'url' => $response['url'],
            'token' => $response['token'],
        ];
    }
    
    /**
     * Send a request to the telesalud API
     *
     * @param string $method HTTP method (GET, POST, PUT, DELETE)
     * @param string $endpoint API endpoint (starting with /)
     * @param array|null $data Request data (for POST/PUT requests)
     * @param array $options Additional request options
     * @return array Response data
     * @throws \Exception If the request fails
     */
    private function request(string $method, string $endpoint, ?array $data = null, array $options = []): array
    {
        $url = $this->baseUrl . $endpoint;
        $retries = 0;
        $lastException = null;
        
        // Merge with default options
        $options = array_merge($this->defaultOptions, $options);
        
        while ($retries < $this->maxRetries) {
            try {
                $this->logger->debug("Telesalud API request: $method $url");
                
                // Initialize cURL
                $ch = curl_init();
                
                // Set common options
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, $options['timeout']);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $options['connect_timeout']);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Authorization: Bearer ' . $this->apiToken,
                    'Accept: application/json',
                    'Content-Type: application/json',
                ]);
                
                // Set method-specific options
                switch ($method) {
                    case self::METHOD_GET:
                        break;
                    case self::METHOD_POST:
                        curl_setopt($ch, CURLOPT_POST, true);
                        if ($data !== null) {
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                        }
                        break;
                    case self::METHOD_PUT:
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                        if ($data !== null) {
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                        }
                        break;
                    case self::METHOD_DELETE:
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                        break;
                    default:
                        throw new \Exception("Unsupported HTTP method: $method");
                }
                
                // Execute request
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
                curl_close($ch);
                
                // Handle cURL errors
                if ($response === false) {
                    throw new \Exception("cURL error: $error");
                }
                
                // Parse response
                $responseData = json_decode($response, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \Exception("Invalid JSON response: " . json_last_error_msg());
                }
                
                // Handle HTTP errors
                if ($httpCode >= 400) {
                    $errorMessage = $responseData['message'] ?? 'Unknown error';
                    throw new \Exception("HTTP error $httpCode: $errorMessage");
                }
                
                $this->logger->debug("Telesalud API response: HTTP $httpCode");
                return $responseData;
                
            } catch (\Exception $e) {
                $lastException = $e;
                $retries++;
                
                // Only retry on network errors or 5xx server errors
                if (!$this->isRetryableError($e)) {
                    break;
                }
                
                $this->logger->warning(
                    "Telesalud API request failed (attempt $retries of {$this->maxRetries}): " . $e->getMessage()
                );
                
                // Exponential backoff with jitter
                if ($retries < $this->maxRetries) {
                    $sleepMs = min(1000 * pow(2, $retries - 1), 5000) + rand(0, 1000);
                    usleep($sleepMs * 1000);
                }
            }
        }
        
        // If we get here, all retries failed
        $this->logger->error("Telesalud API request failed after {$this->maxRetries} attempts: " . $lastException->getMessage());
        throw new \Exception("Failed to communicate with telesalud API: " . $lastException->getMessage(), 0, $lastException);
    }
    
    /**
     * Check if an error is retryable
     *
     * @param \Exception $e The exception to check
     * @return bool True if the error is retryable
     */
    private function isRetryableError(\Exception $e): bool
    {
        $message = $e->getMessage();
        
        // Network errors
        if (strpos($message, 'cURL error') !== false) {
            return true;
        }
        
        // Server errors (5xx)
        if (strpos($message, 'HTTP error 5') !== false) {
            return true;
        }
        
        return false;
    }
}
