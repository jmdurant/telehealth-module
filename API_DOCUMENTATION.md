# Telesalud API Integration Documentation

## Overview
This document outlines the integration details for connecting OpenEMR's telehealth module with the Telesalud backend API.

## API Configuration

### Base URL Structure
- Base URL format: `https://your-telesalud-domain[:port]/api`
- Example: `https://vc-staging.localhost/api`
- The `/api` suffix is automatically appended if not present

### Required Environment Variables
```env
TELEHEALTH_BASE_URL=https://vc-staging.localhost
TELEHEALTH_API_TOKEN=1|JrgDUPLV07493VDFrUqGxcQy2vwG96WQkMvthfjl
NOTIFICATION_TOKEN=openemr-telehealth-secret-2024
```

### Optional Environment Variables
```env
TELEHEALTH_PORT=443
VC_API=/api/videoconsultation?
VC_API_DATA=/api/videoconsultation/data?
```

## API Endpoints

### Video Consultation Endpoint
- **Endpoint**: `/videoconsultation`
- **Method**: POST
- **Headers**:
  ```
  Authorization: Bearer {TELEHEALTH_API_TOKEN}
  Accept: application/json
  Content-Type: application/json
  ```
- **Required Fields**:
  - `appointment_date`
  - `medic_name`
  - `patient_name`
  - `days_before_expiration` (optional, defaults to 3)

### Response Structure
A successful API response will include:
- `backend_id`: Unique identifier for the consultation
- `medic_id`: Identifier for the medical provider
- `patient_url`: URL for patient access
- `medic_url`: URL for provider access
- `data_url`: Additional data URL (if available)
- `valid_from`: Start of validity period
- `valid_to`: End of validity period

## Connection Testing

### Test Request Format
```php
$testData = [
    'appointment_date' => date('Y-m-d H:i:s'),
    'medic_name' => 'Test Doctor',
    'patient_name' => 'Test Patient',
    'days_before_expiration' => 1
];
```

### Response Status Codes
- `200`: Successful connection and valid request
- `400/422`: Successful connection but invalid data (expected during testing)
- `401`: Authentication failed (invalid token)
- `405`: Method not allowed (wrong HTTP method)

### Validation Rules
The API enforces validation on:
- Required fields must be present
- `appointment_date` must be a valid date/time
- `medic_name` and `patient_name` cannot be empty
- `days_before_expiration` must be a positive integer

## Troubleshooting

### Common Issues
1. **Connection Failed**
   - Verify the base URL is correct and accessible
   - Ensure `/api` is properly appended to the base URL
   - Check network/firewall access to the API server

2. **Authentication Failed**
   - Verify the API token is valid and properly formatted
   - Check the token hasn't expired
   - Ensure the token is being sent in the Authorization header

3. **SSL/TLS Issues**
   - For development: SSL verification can be disabled
   - For production: Ensure valid SSL certificates are installed

### Testing Tools
1. **Standalone Test Script**: `test_connection.php`
   - Tests basic connectivity
   - Validates authentication
   - Simulates a video consultation request

2. **Settings Page Test**
   - Available in the OpenEMR telehealth settings
   - Tests connection with current configuration
   - Shows detailed error messages

## Security Considerations

### API Token Security
- Store tokens securely in environment variables
- Never commit tokens to version control
- Use separate tokens for development and production

### SSL/TLS Requirements
- Production environments should use HTTPS
- Valid SSL certificates required for client connections
- Internal API calls may use HTTP in containerized environments

## SSL Certificate Handling

### Development/Testing Environments
When working with development or testing environments that use self-signed certificates:

1. The connection test will automatically disable SSL verification
2. This is done by setting the following cURL options:
   ```php
   curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
   curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
   ```

### Production Environments
For production environments:

1. Always use properly signed SSL certificates
2. Enable SSL verification in the code
3. Ensure the server's SSL certificate is valid and trusted
4. Consider implementing certificate pinning for additional security

### Common SSL Issues
1. Self-signed certificate errors
   - Solution for dev/test: Disable SSL verification
   - Solution for prod: Use a valid SSL certificate
2. Certificate chain issues
   - Ensure the complete certificate chain is installed on the server
3. Certificate expiration
   - Monitor certificate expiration dates
   - Set up automatic renewal

## Integration Examples

### Creating a Video Consultation
```