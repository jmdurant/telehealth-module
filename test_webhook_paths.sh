#!/bin/bash

echo "Testing different webhook URL paths from telehealth backend..."
echo ""

# Test JSON payload
cat > /tmp/webhook_test.json << EOF
{
  "topic": "videoconsultation-finished",
  "vc": {
    "id": "2646bcc8db42933e4f6f65ba2c08ed81e6b7df33",
    "evolution": "Container networking test"
  }
}
EOF

# Test different URL variations
URLS=(
  "http://official-staging-openemr-1/openemr/interface/modules/custom_modules/oe-module-telehealth/api/notifications_simple.php"
  "http://official-staging-openemr-1:80/openemr/interface/modules/custom_modules/oe-module-telehealth/api/notifications_simple.php"
  "http://official-staging-openemr-1/interface/modules/custom_modules/oe-module-telehealth/api/notifications_simple.php"
  "http://172.18.0.2/openemr/interface/modules/custom_modules/oe-module-telehealth/api/notifications_simple.php"
)

for url in "${URLS[@]}"; do
  echo "Testing URL: $url"
  
  response=$(curl -s -X POST \
    -H "Content-Type: application/json" \
    -d @/tmp/webhook_test.json \
    -w "HTTP:%{http_code}" \
    "$url")
  
  http_code="${response##*HTTP:}"
  response_body="${response%HTTP:*}"
  
  echo "  HTTP Code: $http_code"
  echo "  Response: $(echo "$response_body" | head -c 100)..."
  echo "  Status: $([ "$http_code" = "200" ] && echo "✅ SUCCESS" || echo "❌ FAILED")"
  echo ""
done

echo "Test completed!" 