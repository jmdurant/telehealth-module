#!/bin/bash

echo "Testing webhook from telehealth backend to OpenEMR..."
echo "Target URL: http://official-staging-openemr-1/openemr/interface/modules/custom_modules/oe-module-telehealth/api/notifications_simple.php"
echo ""

# Test with actual backend ID from the database
BACKEND_ID="2646bcc8db42933e4f6f65ba2c08ed81e6b7df33"

# Create properly formatted JSON file
cat > /tmp/webhook_test.json << EOF
{
  "topic": "videoconsultation-finished",
  "vc": {
    "id": "$BACKEND_ID",
    "evolution": "Test consultation completed successfully from container networking test."
  }
}
EOF

echo "Test JSON payload:"
cat /tmp/webhook_test.json
echo ""
echo ""

echo "Sending webhook request..."
curl -X POST \
  -H "Content-Type: application/json" \
  -d @/tmp/webhook_test.json \
  -w "HTTP Code: %{http_code}\nTotal Time: %{time_total}s\n" \
  http://official-staging-openemr-1/openemr/interface/modules/custom_modules/oe-module-telehealth/api/notifications_simple.php

echo ""
echo "Test completed!" 