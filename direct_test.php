<?php
/**
 * Direct test script for TelesaludClient
 * This script bypasses OpenEMR and directly tests the TelesaludClient
 */

// Set error reporting to maximum
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', '/var/log/apache2/error.log');

// Define the SystemLogger class if it doesn't exist
if (!class_exists('OpenEMR\Common\Logging\SystemLogger')) {
    class SystemLogger {
        public function debug($message) {
            error_log("[DEBUG] $message");
        }
        
        public function info($message) {
            error_log("[INFO] $message");
        }
        
        public function warning($message) {
            error_log("[WARNING] $message");
        }
        
        public function error($message) {
            error_log("[ERROR] $message");
        }
    }
}

// Define the TelesaludClient class with our debugging
class TelesaludClient {
    public $baseUrl;
    private $apiToken;
    private $logger;
    public $lastResponse;
    
    // Container and public URLs for transformation
    private $containerUrl = 'official-staging-telehealth-web-1';
    private $publicUrl = 'vc-staging.localhost';
    
    public function __construct($baseUrl, $apiToken, $logger) {
        $this->logger = $logger;
        
        // Log constructor parameters
        $this->logger->debug("DIRECT_TEST: Constructor called with baseUrl: {$baseUrl}");
        $this->logger->debug("DIRECT_TEST: API token length: " . strlen($apiToken));
        
        // Normalize base URL (remove trailing slash if present)
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->logger->debug("DIRECT_TEST: Normalized base URL: {$this->baseUrl}");
        
        // Ensure /api is in the base URL if not already present
        if (strpos($this->baseUrl, '/api') === false) {
            $this->baseUrl .= '/api';
            $this->logger->debug("DIRECT_TEST: Added /api suffix to base URL: {$this->baseUrl}");
        } else {
            $this->logger->debug("DIRECT_TEST: Base URL already contains /api: {$this->baseUrl}");
        }
        
        $this->apiToken = $apiToken;
        $this->logger->debug("DIRECT_TEST: FINAL BASE URL: {$this->baseUrl}");
    }
    
    /**
     * Transform URLs in API responses from container URLs to public-facing URLs
     * 
     * @param array $response The API response to transform
     * @return array The transformed response
     */
    public function transformUrls($response)
    {
        // If response is not an array, return it unchanged
        if (!is_array($response)) {
            return $response;
        }
        
        // Log the transformation
        $this->logger->debug("DIRECT_TEST: Transforming URLs from {$this->containerUrl} to {$this->publicUrl}");
        error_log("DIRECT_TEST: Transforming URLs from {$this->containerUrl} to {$this->publicUrl}");
        
        // Function to recursively transform URLs in the response
        $transform = function(&$data) use (&$transform) {
            foreach ($data as $key => &$value) {
                if (is_string($value)) {
                    // Check for both http:// and https:// versions of the container URL
                    if (strpos($value, $this->containerUrl) !== false) {
                        $oldValue = $value;
                        $value = str_replace($this->containerUrl, $this->publicUrl, $value);
                        $this->logger->debug("DIRECT_TEST: Transformed URL from '$oldValue' to '$value'");
                        error_log("DIRECT_TEST: Transformed URL from '$oldValue' to '$value'");
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
    
    public function testConnection() {
        $this->logger->debug("DIRECT_TEST: Starting connection test");
        
        // Test data
        $testData = [
            'appointment_date' => date('Y-m-d H:i:s'),
            'days_before_expiration' => 1,
            'medic_name' => 'Test Doctor',
            'patient_name' => 'Test Patient',
            'extra' => [
                'test_connection' => true,
            ]
        ];
        
        // Endpoint
        $endpoint = '/videoconsultation';
        $url = $this->baseUrl . $endpoint;
        
        $this->logger->debug("DIRECT_TEST: Making request to URL: {$url}");
        $this->logger->debug("DIRECT_TEST: Request data: " . json_encode($testData));
        
        // Initialize cURL
        $ch = curl_init($url);
        
        // Set common options
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($testData));
        
        // Debug log the token being used
        $this->logger->debug("DIRECT_TEST: Using API token (first 10 chars): " . substr($this->apiToken, 0, 10));
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->apiToken,
            'Accept: application/json',
            'Content-Type: application/json',
        ]);
        
        // Disable SSL verification for local development
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        // Execute request
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        // Log response details
        $this->logger->debug("DIRECT_TEST: Response HTTP code: {$httpCode}");
        if ($response === false) {
            $this->logger->error("DIRECT_TEST: cURL error: {$error}");
            return false;
        } else {
            // Try to parse JSON
            $data = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->error("DIRECT_TEST: JSON parse error: " . json_last_error_msg());
                $this->logger->error("DIRECT_TEST: Raw response: {$response}");
                echo "JSON parse error: " . json_last_error_msg() . "\n";
                echo "Raw response: {$response}\n\n";
                return false;
            } else {
                // Transform URLs in the response
                $data = $this->transformUrls($data);
                
                // Store the transformed response
                $this->lastResponse = $data;
                
                // Check if the API call was successful
                if (isset($data['success']) && $data['success'] === true) {
                    $this->logger->debug("DIRECT_TEST: API call successful");
                    return true;
                } else {
                    $this->logger->error("DIRECT_TEST: API call failed. Response: " . json_encode($data));
                    return false;
                }
            }
        }
        
        // Log raw response
        $responsePreview = substr($response, 0, 1000); // First 1000 chars for logging
        $this->logger->debug("DIRECT_TEST: Raw response (first 1000 chars): {$responsePreview}");
        
        // Always show the raw response in the output
        echo "HTTP code: {$httpCode}\n";
        echo "Raw response: {$responsePreview}\n";
        
        // Parse response
        $responseData = json_decode($response, true);
        $jsonError = json_last_error();
        $jsonErrorMsg = json_last_error_msg();
        
        if ($jsonError !== JSON_ERROR_NONE) {
            $this->logger->error("DIRECT_TEST: JSON parse error: {$jsonErrorMsg}");
            $this->logger->error("DIRECT_TEST: Raw response that caused JSON error: {$responsePreview}");
            echo "JSON parse error: $jsonErrorMsg\n";
            echo "Raw response: $responsePreview\n";
            return false;
        }
        
        // Check for HTTP errors
        if ($httpCode >= 400) {
            $errorMessage = $responseData['message'] ?? 'Unknown error';
            $this->logger->debug("DIRECT_TEST: HTTP error $httpCode: $errorMessage");
            
            // For 400/422 errors, this is actually expected for a test
            if ($httpCode == 400 || $httpCode == 422) {
                $this->logger->debug("DIRECT_TEST: Test connection succeeded (expected validation error)");
                echo "Test connection succeeded (expected validation error)\n";
                return true;
            }
            
            echo "HTTP error $httpCode: $errorMessage\n";
            return false;
        }
        
        $this->logger->debug("DIRECT_TEST: Test connection succeeded unexpectedly");
        echo "Test connection succeeded unexpectedly\n";
        return true;
    }
}

// Create logger
$logger = new SystemLogger();
$logger->debug("DIRECT_TEST: Starting direct test script");

// Get environment variables from database
function getGlobalSetting($key) {
    try {
        $host = 'mysql';
        $port = '3306';
        $dbname = 'openemr';
        $username = 'openemr';
        $password = 'openemr';
        
        // Connect to database
        $dsn = "mysql:host=$host;port=$port;dbname=$dbname";
        $pdo = new PDO($dsn, $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $stmt = $pdo->prepare("SELECT gl_value FROM globals WHERE gl_name = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && isset($result['gl_value'])) {
            return $result['gl_value'];
        }
        
        return null;
    } catch (Exception $e) {
        error_log("Error getting global setting $key: " . $e->getMessage());
        return null;
    }
}

// Get environment variables
$vcApiUrl = getGlobalSetting('VC_API_URL');
$vcApiPort = getGlobalSetting('VC_API_PORT');
$vcApiPath = getGlobalSetting('VC_API');
$vcApiToken = getGlobalSetting('VC_API_TOKEN');

// Construct full URL using environment variables
$fullEnvUrl = $vcApiUrl;
if (!empty($vcApiPort) && $vcApiPort != '80' && $vcApiPort != '443') {
    $fullEnvUrl .= ':' . $vcApiPort;
}
$fullEnvUrl .= $vcApiPath;

// Test URLs
$urls = [
    'container' => 'http://official-staging-telehealth-web-1',
    'https' => 'https://vc-staging.localhost',
    'env-vars' => $fullEnvUrl
];

// API token
$apiToken = $vcApiToken ?: '1|JrgDUPLV07493VDFrUqGxcQy2vwG96WQkMvthfjl';

// Log environment variables
echo "Environment Variables:\n";
echo "VC_API_URL: $vcApiUrl\n";
echo "VC_API_PORT: $vcApiPort\n";
echo "VC_API_PATH: $vcApiPath\n";
echo "Full ENV URL: $fullEnvUrl\n\n";

// Add a specific test for URL transformation
echo "\n=== Testing URL transformation directly ===\n";
$logger->debug("DIRECT_TEST: Testing URL transformation directly");

// Create a client with the container URL
$client = new TelesaludClient('http://official-staging-telehealth-web-1', $apiToken, $logger);

// Create a mock response with container URLs
$mockResponse = [
    'success' => true,
    'data' => [
        'id' => '123456',
        'medic_secret' => 'abcdef',
        'patient_url' => 'https://official-staging-telehealth-web-1/videoconsultation?vc=123456',
        'data_url' => 'https://official-staging-telehealth-web-1/api/videoconsultation/data?vc=123456&medic=abcdef',
        'nested' => [
            'another_url' => 'https://official-staging-telehealth-web-1/something/else'
        ]
    ]
];

echo "Original response URLs:\n";
if (isset($mockResponse['data']['patient_url'])) {
    echo "patient_url: " . $mockResponse['data']['patient_url'] . "\n";
}
if (isset($mockResponse['data']['data_url'])) {
    echo "data_url: " . $mockResponse['data']['data_url'] . "\n";
}

// Transform the URLs
$transformedResponse = $client->transformUrls($mockResponse);

echo "\nTransformed response URLs:\n";
if (isset($transformedResponse['data']['patient_url'])) {
    echo "patient_url: " . $transformedResponse['data']['patient_url'] . "\n";
}
if (isset($transformedResponse['data']['data_url'])) {
    echo "data_url: " . $transformedResponse['data']['data_url'] . "\n";
}

// Check if URLs were transformed
$containsPublicDomain = false;
$containsContainerName = false;

// Function to recursively search for URLs in the response
$searchUrls = function($data) use (&$searchUrls, &$containsPublicDomain, &$containsContainerName) {
    if (is_array($data)) {
        foreach ($data as $key => $value) {
            if (is_string($value) && strpos($value, 'http') === 0) {
                if (strpos($value, 'vc-staging.localhost') !== false) {
                    $containsPublicDomain = true;
                }
                if (strpos($value, 'official-staging-telehealth-web-1') !== false) {
                    $containsContainerName = true;
                }
            } else if (is_array($value)) {
                $searchUrls($value);
            }
        }
    }
};

$searchUrls($transformedResponse);

echo "\nURL Transformation Results:\n";
echo "Contains public domain (vc-staging.localhost): " . ($containsPublicDomain ? "YES ✅" : "NO ❌") . "\n";
echo "Contains container name (official-staging-telehealth-web-1): " . ($containsContainerName ? "YES ❌" : "NO ✅") . "\n";

if ($containsPublicDomain && !$containsContainerName) {
    echo "\n✅ URL transformation is working correctly!\n";
} else if (!$containsPublicDomain && $containsContainerName) {
    echo "\n❌ URL transformation is NOT working - container names are still present.\n";
} else if ($containsPublicDomain && $containsContainerName) {
    echo "\n⚠️ URL transformation is partially working - some URLs were transformed but others weren't.\n";
} else {
    echo "\n⚠️ No URLs found in the response to check transformation.\n";
}

// Test each URL
foreach ($urls as $type => $url) {
    echo "\nTesting $type URL: $url\n";
    $logger->debug("DIRECT_TEST: Testing $type URL: $url");
    
    // Make sure we're using the correct token
    echo "Using token (first 10 chars): " . substr($apiToken, 0, 10) . "\n";
    $logger->debug("DIRECT_TEST: Using token (first 10 chars): " . substr($apiToken, 0, 10));
    
    $client = new TelesaludClient($url, $apiToken, $logger);
    $result = $client->testConnection();
    
    echo "Result for $type URL: " . ($result ? "SUCCESS" : "FAILED") . "\n";
    $logger->debug("DIRECT_TEST: Result for $type URL: " . ($result ? "SUCCESS" : "FAILED"));
    
    echo "\n";
}

echo "Test complete. Check error logs for detailed information.\n";
$logger->debug("DIRECT_TEST: Test script completed");
