# Telehealth Module Communication Troubleshooting

This document explains the communication issues we encountered with the telehealth module and how we resolved them.

## Problem Overview

The telehealth module was experiencing a 404 error when trying to access API endpoints via `https://vc-staging.localhost`. Our investigation revealed several interconnected issues:

1. **API Token**: The API token was incorrect
2. **URL Construction**: The URL wasn't properly constructed with the `/api` path
3. **Hostname Resolution**: The `vc-staging.localhost` hostname was resolving incorrectly inside containers
4. **NPM Configuration**: The Nginx Proxy Manager wasn't properly routing `/api` requests
5. **Network Alias Resolution**: The Docker network alias wasn't accessible from all containers

## Root Causes Identified

### 1. Hostname Resolution Issue

Inside the OpenEMR container, `vc-staging.localhost` resolves to `127.0.0.1` (the container itself), not to the NPM container:

```bash
$ docker exec official-staging-openemr-1 getent hosts vc-staging.localhost
127.0.0.1         vc-staging.localhost  vc-staging.localhost
```

This means when the OpenEMR container makes a request to `https://vc-staging.localhost/api/...`, it's trying to connect to itself, not to the NPM container.

### 2. Network Alias Resolution

Our Docker Compose file defines a network alias for the telehealth web container:

```yaml
web:
    # ... other configuration ...
    networks:
      default:
      frontend:
        aliases:
          - telesalud-frontend
```

After inspecting the Docker network configuration, we discovered:

1. The telehealth web container is connected to multiple networks:
   - `frontend-official-staging`
   - `official-shared-network`
   - `official-staging-network`
   - `official-staging-telehealth_default`

2. The OpenEMR container is also connected to multiple networks:
   - `frontend-official-staging`
   - `official-shared-network`
   - `official-staging-network`

3. The alias `telesalud-frontend` is not found in any of these networks

The issue is that while both containers are on the same networks, the alias defined in the Docker Compose file isn't being properly applied to any of the shared networks.

### 3. NPM Configuration

The NPM configuration for the `/api` location wasn't correctly routing requests to the telehealth container.

## Solutions Tested

### 1. Container-to-Container Communication (Working)

Direct communication between containers using the container name:

```php
$vcApiUrl = 'http://official-staging-telehealth-web-1';
$vcApiPort = '80';
```

**Result**: SUCCESS ✅

```
HTTP code: 200
Success: true
Video ID: 83b1bb226ea5b6e167b5945110d985266058683e
Medic Secret: vbyPwo20iu
```

### 2. Network Alias Approach (Not Working)

Using the Docker network alias:

```php
$vcApiUrl = 'http://telesalud-frontend';
$vcApiPort = '80';
```

**Result**: FAILED ❌ (Connection timeout)

### 3. HTTPS URL via NPM (Not Working)

Using the HTTPS URL through NPM:

```php
$vcApiUrl = 'https://vc-staging.localhost';
$vcApiPort = '443';
```

**Result**: FAILED ❌ (404 Not Found)

```
HTTP code: 404
Raw response: <!DOCTYPE HTML PUBLIC "-//IETF//DTD HTML 2.0//EN">
<html><head>
<title>404 Not Found</title>
</head><body>
<h1>Not Found</h1>
<p>The requested URL was not found on this server.</p>
</body></html>
```

## Working Solution

We implemented the container-to-container communication approach, which is working perfectly:

1. **Updated API Token**: Set to the correct value
2. **URL Construction**: Modified `TelesaludClient.php` to properly handle URL construction
3. **Environment Variables**: Set to use the container URL directly

```php
// Environment Variables
$vcApiUrl = 'http://official-staging-telehealth-web-1';
$vcApiPort = '80';
$vcApiPath = '/api/videoconsultation?';
```

## Future Improvements

### 1. Network Alias Configuration

To use the network alias approach in the future:

1. Ensure the OpenEMR container is connected to the same `frontend` network
2. Update the Docker Compose file to explicitly connect both containers
3. Verify network connectivity between containers

Example Docker Compose configuration:

```yaml
services:
  openemr:
    # ... other configuration ...
    networks:
      - default
      - frontend  # Add this to connect to the frontend network

  telehealth-web:
    # ... other configuration ...
    networks:
      default:
      frontend:
        aliases:
          - telesalud-frontend

networks:
  frontend:
    external: true  # or define it here
```

### 2. NPM Configuration

To use the HTTPS URL through NPM:

1. Configure NPM to route `/api` requests to the telehealth container
2. Add a custom location block in NPM for the `/api` path
3. Use the container's IP address instead of the hostname to avoid DNS resolution issues

## Debugging and Fixing the Network Alias

We've identified that the network alias issue is due to the alias not being properly applied to any of the shared networks. Here's how to debug and fix it:

### Debugging Steps

1. **Check Network Connectivity**:
   ```bash
   docker network ls
   docker network inspect frontend-official-staging
   ```

2. **Verify Container Network Connections**:
   ```bash
   docker inspect official-staging-openemr-1 -f '{{json .NetworkSettings.Networks}}'
   docker inspect official-staging-telehealth-web-1 -f '{{json .NetworkSettings.Networks}}'
   ```

3. **Test DNS Resolution**:
   ```bash
   docker exec official-staging-openemr-1 getent hosts telesalud-frontend
   ```

### Solution: Add Network Alias to Shared Network

Since both containers are already connected to the `frontend-official-staging` network, we can add the alias to the telehealth container on this network:

```bash
# Disconnect and reconnect the telehealth container with the alias
docker network disconnect frontend-official-staging official-staging-telehealth-web-1
docker network connect --alias telesalud-frontend frontend-official-staging official-staging-telehealth-web-1
```

### Permanent Solution: Update Docker Compose

For a permanent solution, update the docker-compose.yml file for the telehealth service:

```yaml
services:
  web:
    # ... other configuration ...
    networks:
      default: {}
      frontend-official-staging:
        aliases:
          - telesalud-frontend
```

This ensures the alias is applied to the correct shared network that both containers are connected to.

## Conclusion

The telehealth module is now successfully connecting to the API using container-to-container communication. This approach is reliable and efficient, bypassing the need for NPM routing or complex network configurations.

For future development, consider implementing proper network configurations to enable the use of network aliases or HTTPS URLs through NPM.
