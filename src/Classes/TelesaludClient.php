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

namespace OpenEMR\Modules\Telehealth\Classes;

use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Common\Csrf\CsrfUtils;

class TelesaludClient
{
    // API endpoints based on telesalud backend
    // Note: The full URL will be baseUrl + endpoint, where baseUrl already includes /api if needed
    private const ENDPOINT_VIDEOCONSULTATIONS = '/videoconsultation';
    private const ENDPOINT_VIDEOCONSULTATIONS_DATA = '/videoconsultation/data';
    private const ENDPOINT_STATUS = '/videoconsultation/status';
    
    // Alternative endpoints to try if the main ones fail
    private const ALT_ENDPOINT_VIDEOCONSULTATIONS = '/api/videoconsultation';
    private const ALT_ENDPOINT_VIDEOCONSULTATIONS_DATA = '/api/videoconsultation/data';
    private const ALT_ENDPOINT_STATUS = '/api/videoconsultation/status';
    
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
    
    /** @var array Last API response (for testing purposes) */
    public $lastResponse = [];
    
    /**
     * Constructor
     *
     * @param string $baseUrl Base URL for the telesalud API (can be overridden by environment variables)
     * @param string $apiToken API token for authentication
     * @param SystemLogger|null $logger Logger instance (optional)
     */
    public function __construct(string $baseUrl, string $apiToken, $logger = null)
    {
        // Create logger first to ensure all debug messages are captured
        $logger = $logger ?? new SystemLogger();
        
        // Check for environment variables (original structure)
        $vcApiUrl = $this->getGlobalSetting('VC_API_URL');
        $vcApiPort = $this->getGlobalSetting('VC_API_PORT');
        $vcApiPath = $this->getGlobalSetting('VC_API');
        $vcApiToken = $this->getGlobalSetting('VC_API_TOKEN');
        
        $logger->debug("TelesaludClient: Environment variables - URL: {$vcApiUrl}, Port: {$vcApiPort}, Path: {$vcApiPath}");
        
        // If environment variables are set, use them instead of the provided baseUrl
        if (!empty($vcApiUrl) && !empty($vcApiPath)) {
            // Construct URL using original format: URL + Port + Path
            $this->baseUrl = rtrim($vcApiUrl, '/'); // Remove trailing slash if present
            
            // Add port if specified and not default
            if (!empty($vcApiPort) && $vcApiPort != '80' && $vcApiPort != '443') {
                $this->baseUrl .= ':' . $vcApiPort;
            }
            
            // Add API path
            $this->baseUrl .= $vcApiPath;
            $logger->debug("TelesaludClient: Using environment variables to construct URL: {$this->baseUrl}");
            
            // Use environment token if available
            if (!empty($vcApiToken)) {
                $this->apiToken = $vcApiToken;
                $logger->debug("TelesaludClient: Using token from environment variables");
            } else {
                $this->apiToken = $apiToken;
            }
        } else {
            // Fallback to provided parameters
            $logger->debug("TelesaludClient: Environment variables not found, using provided parameters");
            $logger->debug("TelesaludClient: Constructor called with baseUrl: {$baseUrl}");
            
            // Normalize base URL (remove trailing slash if present)
            $this->baseUrl = rtrim($baseUrl, '/');
            
            // Ensure /api is in the base URL if not already present
            if (strpos($this->baseUrl, '/api') === false) {
                $this->baseUrl .= '/api';
                $logger->debug("TelesaludClient: Added /api suffix to base URL: {$this->baseUrl}");
            }
            
            $this->apiToken = $apiToken;
        }
        
        // Log final URL and token info
        $logger->debug("TelesaludClient: FINAL BASE URL: {$this->baseUrl}");
        $logger->debug("TelesaludClient: API token length: " . strlen($this->apiToken));
        
        // Use provided logger
        $this->logger = $logger;
    }
    
    /**
     * Create a new meeting in the telesalud backend
     *
     * @param int $encounterId OpenEMR encounter ID
     * @param string $providerName Name of the provider (medic_name)
     * @param string $patientName Name of the patient
     * @param string $startTime Start time of the meeting (appointment_date)
     * @param array $options Additional options for the meeting
     * @return array Meeting data including URLs and backend_id
     * @throws \Exception If the request fails
     */
    public function createMeeting(int $encounterId, string $providerName, string $patientName, string $startTime, array $options = []): array
    {
        // Build request data according to telesalud API specification
        $data = [
            'appointment_date' => $startTime,
            'days_before_expiration' => $options['days_before_expiration'] ?? 7, // Default 7 days
            'medic_name' => $providerName,
            'patient_name' => $patientName,
            'patient_id' => $encounterId, // Use encounter ID as patient_id
            'extra' => [
                'openemr_encounter_id' => $encounterId,
                'openemr_integration' => true,
            ]
        ];
        
        // ✅ ADD NOTIFICATION URL: Critical for post-visit processing!
        // The telesalud backend needs to know where to send webhook notifications
        $notificationUrl = $this->getNotificationUrl();
        if (!empty($notificationUrl)) {
            $data['extra']['notification_url'] = $notificationUrl;
            error_log("TelesaludClient createMeeting DEBUG - Using notification URL: " . $notificationUrl);
        } else {
            error_log("TelesaludClient createMeeting WARNING - No notification URL configured! Post-visit processing will not work.");
        }
        
        // Add optional fields if provided
        if (isset($options['patient_number'])) {
            $data['patient_number'] = $options['patient_number'];
        }
        if (isset($options['extra'])) {
            $data['extra'] = array_merge($data['extra'], $options['extra']);
        }
        
        // DEBUG: Log the data being sent to the backend
        error_log("TelesaludClient createMeeting DEBUG - Data being sent to backend: " . json_encode($data));
        error_log("TelesaludClient createMeeting DEBUG - Provider name: " . $providerName);
        error_log("TelesaludClient createMeeting DEBUG - Patient name: " . $patientName);

        try {
            // Log the attempt to use the primary endpoint
            error_log("TelesaludClient: Attempting to create meeting with primary endpoint: " . self::ENDPOINT_VIDEOCONSULTATIONS);
            
            // Try the primary endpoint first
            $response = $this->request(self::METHOD_POST, self::ENDPOINT_VIDEOCONSULTATIONS, $data);
            
            // If we get here, the primary endpoint worked
            error_log("TelesaludClient: Successfully created meeting with primary endpoint");
        } catch (\Exception $e) {
            // If the primary endpoint fails, try the alternative endpoint
            error_log("TelesaludClient: Primary endpoint failed with error: " . $e->getMessage());
            error_log("TelesaludClient: Attempting to use alternative endpoint: " . self::ALT_ENDPOINT_VIDEOCONSULTATIONS);
            
            // Try the alternative endpoint
            $response = $this->request(self::METHOD_POST, self::ALT_ENDPOINT_VIDEOCONSULTATIONS, $data);
            
            error_log("TelesaludClient: Alternative endpoint succeeded");
        }
        
        // Validate response structure
        if (!isset($response['success']) || !$response['success'] || !isset($response['data'])) {
            throw new \Exception("Invalid response from telesalud API when creating meeting");
        }
        
        $meetingData = $response['data'];
        if (!isset($meetingData['id'], $meetingData['patient_url'], $meetingData['medic_url'])) {
            throw new \Exception("Missing required fields in telesalud API response");
        }
        
        // Extract medic identifier from medic_url for future API calls
        $medicId = $this->extractMedicIdFromUrl($meetingData['medic_url']);
        
        // DEBUG: Log the response data and URLs
        error_log("TelesaludClient createMeeting DEBUG - Backend response data: " . json_encode($meetingData));
        error_log("TelesaludClient createMeeting DEBUG - Patient URL: " . $meetingData['patient_url']);
        error_log("TelesaludClient createMeeting DEBUG - Medic URL: " . $meetingData['medic_url']);
        error_log("TelesaludClient createMeeting DEBUG - Extracted medic ID: " . $medicId);

        return [
            'backend_id' => $meetingData['id'],
            'medic_id' => $medicId,
            'patient_url' => $meetingData['patient_url'],
            'medic_url' => $meetingData['medic_url'],
            'data_url' => $meetingData['data_url'] ?? null,
            'valid_from' => $meetingData['valid_from'] ?? null,
            'valid_to' => $meetingData['valid_to'] ?? null,
            'raw_response' => $response,
        ];
    }
    
    /**
     * Get meeting details from the telesalud backend
     *
     * @param string $vcId Backend VC ID (the secret identifier)
     * @param string $medicId Backend medic ID (the medic's secret identifier) 
     * @return array Meeting details
     * @throws \Exception If the request fails or meeting is not found
     */
    public function getMeeting(string $vcId, string $medicId): array
    {
        $queryParams = [
            'vc' => $vcId,
            'medic' => $medicId
        ];
        
        $url = self::ENDPOINT_VIDEOCONSULTATIONS_DATA . '?' . http_build_query($queryParams);
        return $this->request(self::METHOD_GET, $url);
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
        
        $url = self::ENDPOINT_VIDEOCONSULTATIONS_DATA . '?' . http_build_query($queryParams);
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
     * @throws \Exception Always throws since endpoint doesn't exist
     */
    public function finishMeeting(string $meetingId, ?string $notes = null): array
    {
        throw new \Exception("Finish meeting endpoint not available in this telesalud API - meetings auto-finish when participants leave");
    }
    
    /**
     * Get meeting recording if available
     *
     * @param string $meetingId Backend meeting ID
     * @return array Recording data including URL
     * @throws \Exception Always throws since endpoint doesn't exist
     */
    public function getMeetingRecording(string $meetingId): array
    {
        throw new \Exception("Recording endpoint not available in this telesalud API");
    }
    
    /**
     * Get server status (not available in this API)
     *
     * @return array Status information
     * @throws \Exception Always throws since endpoint doesn't exist
     */
    public function getServerStatus(): array
    {
        throw new \Exception("Server status endpoint not available in this telesalud API");
    }
    
    /**
     * Test the connection to the telesalud backend
     *
     * @return bool True if connection is successful
     */
    public function testConnection(): bool
    {
        $this->logger->debug("TelesaludClient: Starting connection test");
        $this->logger->debug("TelesaludClient: Using base URL: {$this->baseUrl}");
        
        try {
            // Create a minimal test meeting to verify API connectivity
            $testData = [
                'appointment_date' => date('Y-m-d H:i:s'),
                'days_before_expiration' => 1,
                'medic_name' => 'Test Doctor',
                'patient_name' => 'Test Patient',
                'extra' => [
                    'test_connection' => true,
                ]
            ];
            
            $this->logger->debug("TelesaludClient: Sending test data: " . json_encode($testData));
            $this->logger->debug("TelesaludClient: Endpoint: " . self::ENDPOINT_VIDEOCONSULTATIONS);
            
            // We expect this to fail with a validation error (400/422) since it's not a real meeting
            // But it should successfully connect to the API and get a valid JSON response
            $response = $this->request(self::METHOD_POST, self::ENDPOINT_VIDEOCONSULTATIONS, $testData);
            
            // If we get here, the request succeeded (which is unexpected for a test request)
            $this->logger->debug("TelesaludClient: API test connection succeeded unexpectedly with response: " . json_encode($response));
            return true;
            
        } catch (\Exception $e) {
            $message = $e->getMessage();
            $this->logger->debug("TelesaludClient: Test connection exception: {$message}");
            
            // Check if this is an expected validation error (400/422)
            if (strpos($message, 'HTTP error 400') !== false || strpos($message, 'HTTP error 422') !== false) {
                // This is actually a successful test - we connected to the API and got a validation error
                $this->logger->debug("TelesaludClient: API test connection succeeded (expected validation error)");
                return true;
            }
            $this->logger->error("Telesalud connection test failed: " . $message);
            return false;
        }
    }
    
    /**
     * Extract medic ID from medic URL
     *
     * @param string $medicUrl The medic URL returned by the API
     * @return string The medic ID
     */
    private function extractMedicIdFromUrl(string $medicUrl): string
    {
        // Parse URL to extract medic parameter
        // Example: https://localhost:499/videoconsultation?vc=bf9f...&medic=WWzoDe1RLu
        $parts = parse_url($medicUrl);
        if (isset($parts['query'])) {
            parse_str($parts['query'], $queryParams);
            return $queryParams['medic'] ?? '';
        }
        return '';
    }
    
    /**
     * Get WebSocket connection details
     *
     * @return array WebSocket connection details
     * @throws \Exception Always throws since endpoint doesn't exist
     */
    public function getWebSocketDetails(): array
    {
        throw new \Exception("WebSocket endpoint not available in this telesalud API - use polling or callbacks for real-time updates");
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
        // Build full URL
        $url = $this->baseUrl . $endpoint;
        $retries = 0;
        $lastException = null;
        
        // Enhanced debug logging
        $this->logger->debug("TelesaludClient: Making request - Method: {$method}, Endpoint: {$endpoint}");
        $this->logger->debug("TelesaludClient: Full request URL: {$url}");
        if ($data !== null) {
            $this->logger->debug("TelesaludClient: Request data: " . json_encode($data));
        }
        
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
                
                // Disable SSL verification for local development
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                
                // Special handling for .localhost domains using CURLOPT_RESOLVE
                $urlParts = parse_url($url);
                $hostname = $urlParts['host'] ?? '';
                $scheme = $urlParts['scheme'] ?? 'https';
                $port = $urlParts['port'] ?? ($scheme === 'https' ? 443 : 80);
                
                // If this is a .localhost domain, try to get its IP from the hosts file
                if (strpos($hostname, '.localhost') !== false) {
                    // Read the hosts file to get the Docker IP for this hostname
                    $hostsContent = file_get_contents('/etc/hosts');
                    if ($hostsContent !== false) {
                        $lines = explode("\n", $hostsContent);
                        foreach ($lines as $line) {
                            $line = trim($line);
                            if (empty($line) || $line[0] === '#') {
                                continue; // Skip comments and empty lines
                            }
                            
                            $parts = preg_split('/\s+/', $line);
                            if (count($parts) >= 2 && in_array($hostname, array_slice($parts, 1))) {
                                $dockerIp = $parts[0];
                                $this->logger->debug("TelesaludClient: Found Docker IP $dockerIp for $hostname in hosts file");
                                
                                // Use CURLOPT_RESOLVE to force curl to use the Docker IP
                                curl_setopt($ch, CURLOPT_RESOLVE, ["$hostname:$port:$dockerIp"]);
                                $this->logger->debug("TelesaludClient: Using CURLOPT_RESOLVE to map $hostname:$port to $dockerIp");
                                break;
                            }
                        }
                    }
                }
                
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
                
                // Enhanced debug logging for response
                $this->logger->debug("TelesaludClient: Response HTTP code: {$httpCode}");
                $responsePreview = substr($response, 0, 1000); // First 1000 chars for logging
                $this->logger->debug("TelesaludClient: Raw response (first 1000 chars): {$responsePreview}");
                
                // Extra debugging for troubleshooting JSON issues
                error_log("TelesaludClient DEBUG - HTTP Code: {$httpCode}");
                error_log("TelesaludClient DEBUG - Raw response: " . $response);
                error_log("TelesaludClient DEBUG - Request URL: {$url}");
                error_log("TelesaludClient DEBUG - Request method: {$method}");
                
                // Handle cURL errors
                if ($response === false) {
                    $this->logger->error("TelesaludClient: cURL error: {$error}");
                    throw new \Exception("cURL error: $error");
                }
                
                // Parse response
                $responseData = json_decode($response, true);
                $jsonError = json_last_error();
                $jsonErrorMsg = json_last_error_msg();
                
                // More detailed JSON error logging
                if ($jsonError !== JSON_ERROR_NONE) {
                    error_log("TelesaludClient DEBUG - JSON error code: {$jsonError}");
                    error_log("TelesaludClient DEBUG - JSON error message: {$jsonErrorMsg}");
                    error_log("TelesaludClient DEBUG - Response that failed to parse: " . $response);
                }
                
                if ($jsonError !== JSON_ERROR_NONE) {
                    $this->logger->error("TelesaludClient: JSON parse error: {$jsonErrorMsg}");
                    $this->logger->error("TelesaludClient: Raw response that caused JSON error: {$responsePreview}");
                    throw new \Exception("Invalid JSON response: {$jsonErrorMsg}");
                }
                
                // Handle HTTP errors
                if ($httpCode >= 400) {
                    $errorMessage = $responseData['message'] ?? 'Unknown error';
                    throw new \Exception("HTTP error $httpCode: $errorMessage");
                }
                
                $this->logger->debug("Telesalud API response: HTTP $httpCode");
                
                // Transform URLs in the response from container URLs to public-facing URLs
                $transformedResponse = $this->transformUrls($responseData);
                
                // Store the response for testing purposes
                $this->lastResponse = $transformedResponse;
                
                return $transformedResponse;
                
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
    
    /**
     * Get a global setting from the OpenEMR database
     * 
     * @param string $key The global setting key
     * @return string|null The global setting value or null if not found
     */
    private function getGlobalSetting(string $key): ?string
    {
        try {
            // First check if it's in $GLOBALS
            if (isset($GLOBALS[$key])) {
                return $GLOBALS[$key];
            }
            
            // If not in $GLOBALS, try to query the database
            $sql = "SELECT gl_value FROM globals WHERE gl_name = ?";
            $result = sqlQuery($sql, [$key]);
            
            if ($result && isset($result['gl_value'])) {
                return $result['gl_value'];
            }
            
            // Not found
            return null;
        } catch (\Exception $e) {
            $this->logger->error("TelesaludClient: Error getting global setting $key: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Strip port number from a URL
     * 
     * @param string $url The URL to process
     * @return string The URL with port removed
     */
    private function stripPortFromUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!isset($parts['host'])) {
            return $url; // Not a valid URL, return as is
        }
        
        // Rebuild the URL without the port
        $scheme = $parts['scheme'] ?? 'https';
        $newUrl = $scheme . '://' . $parts['host'];
        
        // Add path if exists
        if (isset($parts['path'])) {
            $newUrl .= $parts['path'];
        }
        
        // Add query if exists
        if (isset($parts['query'])) {
            $newUrl .= '?' . $parts['query'];
        }
        
        // Add fragment if exists
        if (isset($parts['fragment'])) {
            $newUrl .= '#' . $parts['fragment'];
        }
        
        return $newUrl;
    }

    /**
     * Transform URLs in API responses from container URLs to public-facing URLs
     * 
     * @param array $response The API response to transform
     * @return array The transformed response
     */
    private function transformUrls($response)
    {
        // Get the public-facing URLs from environment variables
        $publicHttpsUrl = getenv('TELEHEALTH_EXTERNAL_HTTPS_URL') ?: $this->getGlobalSetting('VC_PUBLIC_URL', 'https://vc-staging.localhost');
        $publicHttpUrl = getenv('TELEHEALTH_EXTERNAL_URL') ?: str_replace('https://', 'http://', $publicHttpsUrl);
        
        // Strip port numbers from public-facing URLs since we're using NPM as a proxy
        $publicHttpsUrl = $this->stripPortFromUrl($publicHttpsUrl);
        $publicHttpUrl = $this->stripPortFromUrl($publicHttpUrl);
        
        // Log the cleaned URLs
        $this->logger->debug("TelesaludClient: Cleaned public URLs (ports removed) - HTTPS: {$publicHttpsUrl}, HTTP: {$publicHttpUrl}");

        // Get the container URL from the environment variable
        $containerUrl = getenv('TELEHEALTH_BASE_URL');
        if ($containerUrl !== false) {
            $containerUrl = rtrim($containerUrl, '\\'); // Remove any trailing backslashes
            $this->logger->debug("TelesaludClient: Using TELEHEALTH_BASE_URL from environment: {$containerUrl}");
        } else {
            // If not set, fall back to the global setting
            $containerUrl = $this->getGlobalSetting('VC_API_URL', 'http://official-staging-telehealth-web-1');
            $this->logger->debug("TelesaludClient: TELEHEALTH_BASE_URL not set, using fallback: {$containerUrl}");
        }
        
        // For debugging, use the public URL that matches the protocol of the container URL
        $publicUrl = (strpos($containerUrl, 'https://') === 0) ? $publicHttpsUrl : $publicHttpUrl;
        
        // Log all URLs for debugging
        $this->logger->debug("TelesaludClient: URL variables - HTTPS: {$publicHttpsUrl}, HTTP: {$publicHttpUrl}, Container: {$containerUrl}");
        
        // Log the URLs for debugging
        $this->logger->debug("TelesaludClient: Transforming URLs from $containerUrl to $publicUrl");
        
        // Add a debug log entry to verify the transformation is being called
        error_log("TelesaludClient: URL transformation called - from $containerUrl to $publicUrl");
        
        // If response is not an array, return it unchanged
        if (!is_array($response)) {
            return $response;
        }
        
        // Function to recursively transform URLs in the response
        $transform = function(&$data) use (&$transform, $publicHttpsUrl, $publicHttpUrl, $containerUrl) {
            foreach ($data as $key => &$value) {
                if (is_string($value)) {
                    // Check for different versions of the container URL
                    $httpContainerUrl = str_replace('https://', 'http://', $containerUrl);
                    $httpsContainerUrl = str_replace('http://', 'https://', $containerUrl);
                    
                    // Also check for container name without domain
                    $containerName = 'official-staging-telehealth-web-1';
                    $httpContainerName = "http://{$containerName}";
                    $httpsContainerName = "https://{$containerName}";
                    
                    // Replace container URL with public URL (both http and https versions)
                    if (strpos($value, $httpContainerUrl) !== false) {
                        $oldValue = $value;
                        $value = str_replace($httpContainerUrl, $publicHttpUrl, $value);
                        error_log("TelesaludClient: Transformed URL from '$oldValue' to '$value'");
                    } else if (strpos($value, $httpsContainerUrl) !== false) {
                        $oldValue = $value;
                        $value = str_replace($httpsContainerUrl, $publicHttpsUrl, $value);
                        error_log("TelesaludClient: Transformed URL from '$oldValue' to '$value'");
                    } else if (strpos($value, $httpContainerName) !== false) {
                        $oldValue = $value;
                        $value = str_replace($httpContainerName, $publicHttpUrl, $value);
                        error_log("TelesaludClient: Transformed URL from '$oldValue' to '$value'");
                    } else if (strpos($value, $httpsContainerName) !== false) {
                        $oldValue = $value;
                        $value = str_replace($httpsContainerName, $publicHttpsUrl, $value);
                        error_log("TelesaludClient: Transformed URL from '$oldValue' to '$value'");
                    }
                } else if (is_array($value)) {
                    $transform($value);
                }
            }
        };
        
        // Create a copy of the response to transform
        $result = $response;
        $transform($result);
        
        return $result;
    }

    /**
     * Get the notification URL for webhook callbacks
     * This tells the telesalud backend where to send post-visit notifications
     * 
     * @return string|null The webhook notification URL or null if not configured
     */
    private function getNotificationUrl(): ?string
    {
        try {
            // Check $_ENV first (like the original working code)
            if (isset($_ENV['OPS_NOTIFICATIONS_ENDPOINT']) && $_ENV['OPS_NOTIFICATIONS_ENDPOINT']) {
                $this->logger->debug("TelesaludClient: Using OPS_NOTIFICATIONS_ENDPOINT from \$_ENV: " . $_ENV['OPS_NOTIFICATIONS_ENDPOINT']);
                return $_ENV['OPS_NOTIFICATIONS_ENDPOINT'];
            }
            
            // Fallback to getenv() 
            $envUrl = getenv('OPS_NOTIFICATIONS_ENDPOINT');
            if (!empty($envUrl)) {
                $this->logger->debug("TelesaludClient: Using OPS_NOTIFICATIONS_ENDPOINT from getenv(): " . $envUrl);
                return $envUrl;
            }
            
            // If no environment variable set, build URL from OpenEMR globals (fallback)
            $siteAddr = $GLOBALS['qualified_site_addr'] ?? '';
            if (empty($siteAddr)) {
                // Fallback to webroot if qualified_site_addr not available
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $webroot = $GLOBALS['webroot'] ?? '';
                $siteAddr = $protocol . '://' . $host . $webroot;
            }
            
            // Build the webhook URL pointing to our notifications endpoint
            $webhookPath = '/interface/modules/custom_modules/oe-module-telehealth/api/notifications_simple.php';
            $notificationUrl = rtrim($siteAddr, '/') . $webhookPath;
            
            $this->logger->debug("TelesaludClient: Built notification URL from OpenEMR globals (fallback): " . $notificationUrl);
            
            return $notificationUrl;
            
        } catch (\Exception $e) {
            $this->logger->error("TelesaludClient: Error building notification URL: " . $e->getMessage());
            return null;
        }
    }
}
