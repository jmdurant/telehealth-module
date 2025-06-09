# Telehealth Module Environment Variables

This document describes the environment variables used by the OpenEMR Telehealth Module for different deployment scenarios.

## 🔧 Required Environment Variables

### Core API Configuration
```yaml
# Internal API communication (container-to-container)
TELEHEALTH_BASE_URL=http://official-staging-telehealth-web-1/api

# API authentication token
TELEHEALTH_API_TOKEN=1|your-api-token-here

# Webhook notification token
NOTIFICATION_TOKEN=your-notification-token
```

### External Client Access URLs
```yaml
# HTTP access URL for clients (includes port when needed)
TELEHEALTH_EXTERNAL_URL=http://localhost:31290

# HTTPS access URL for clients (optional, derived from HTTP if not set)
TELEHEALTH_EXTERNAL_HTTPS_URL=https://localhost:31453
```

## 🌍 Environment-Specific Configurations

### Development/Testing (localhost with ports)
```yaml
services:
  openemr:
    environment:
      - TELEHEALTH_BASE_URL=http://official-staging-telehealth-web-1/api
      - TELEHEALTH_API_TOKEN=1|your-development-token
      - NOTIFICATION_TOKEN=dev-notification-token
      - TELEHEALTH_EXTERNAL_URL=http://localhost:31290
      - TELEHEALTH_EXTERNAL_HTTPS_URL=https://localhost:31453
```

### Staging Environment
```yaml
services:
  openemr:
    environment:
      - TELEHEALTH_BASE_URL=http://telehealth-web/api
      - TELEHEALTH_API_TOKEN=1|your-staging-token
      - NOTIFICATION_TOKEN=staging-notification-token
      - TELEHEALTH_EXTERNAL_URL=https://vc-staging.localhost
      - TELEHEALTH_EXTERNAL_HTTPS_URL=https://vc-staging.localhost
```

### Production Environment
```yaml
services:
  openemr:
    environment:
      - TELEHEALTH_BASE_URL=http://telehealth-web/api
      - TELEHEALTH_API_TOKEN=1|your-production-token
      - NOTIFICATION_TOKEN=production-notification-token
      - TELEHEALTH_EXTERNAL_URL=https://vc.domain.com
      - TELEHEALTH_EXTERNAL_HTTPS_URL=https://vc.domain.com
```

## 🔄 Migration from Hardcoded Values

The module previously used hardcoded ports and URLs. These have been removed in favor of environment variables:

### ❌ Old Approach (Removed)
- Hardcoded port mappings (30290 → 31290, etc.)
- Automatic port detection based on OpenEMR ports
- Fixed localhost URLs

### ✅ New Approach (Current)
- Environment variables with fallbacks
- Explicit external URL configuration
- Support for custom domains and nginx proxies

## 🧪 Testing Your Configuration

Run the test script to verify your environment variables are working:

```bash
php test_environment_status.php
```

This will show:
- Which environment variables are detected
- Whether API connectivity works
- URL transformation results

## 🔧 Fallback Behavior

1. **Environment Variables First**: `getenv('VARIABLE_NAME')`
2. **Database Settings**: Stored in OpenEMR globals table
3. **Auto-Detection**: Basic domain detection (production setups only)

## 📝 Notes

- **Port Visibility**: In production with nginx, ports are typically hidden
- **SSL Termination**: HTTPS is usually handled by the reverse proxy
- **Container Names**: Internal container names remain the same
- **External Access**: Only the external URLs need environment-specific configuration 