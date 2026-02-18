#!/bin/bash

# Gym Share Token - Manual Test Script
# This script tests the public student template endpoints with share tokens

# Configuration
BASE_URL="${BASE_URL:-https://villamitre.loca.lt/api}"
DNI="${TEST_DNI:-12345678}"
TEMPLATE_ID="${TEST_TEMPLATE_ID:-1}"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}Gym Share Token - Test Suite${NC}"
echo -e "${BLUE}========================================${NC}"
echo ""

# Function to generate a test token (requires PHP)
generate_token() {
    echo -e "${YELLOW}Generating test token...${NC}"
    
    TOKEN=$(php -r "
        \$dni = '$DNI';
        \$ts = time();
        \$secret = getenv('GYM_SHARE_SECRET');
        if (empty(\$secret)) {
            echo 'ERROR: GYM_SHARE_SECRET not set';
            exit(1);
        }
        \$payload = \"\$dni.\$ts\";
        \$signature = hash_hmac('sha256', \$payload, \$secret);
        echo \"\$payload.\$signature\";
    " 2>&1)
    
    if [[ $TOKEN == ERROR* ]]; then
        echo -e "${RED}❌ Failed to generate token: $TOKEN${NC}"
        echo -e "${YELLOW}Make sure GYM_SHARE_SECRET is set in your environment${NC}"
        echo -e "${YELLOW}Example: export GYM_SHARE_SECRET='your-secret'${NC}"
        exit 1
    fi
    
    echo -e "${GREEN}✓ Token generated: ${TOKEN:0:30}...${NC}"
    echo ""
}

# Function to test an endpoint
test_endpoint() {
    local name="$1"
    local url="$2"
    local expected_status="$3"
    
    echo -e "${BLUE}Testing: $name${NC}"
    echo -e "URL: $url"
    
    response=$(curl -s -w "\n%{http_code}" "$url" \
        -H "Accept: application/json")
    
    http_code=$(echo "$response" | tail -n1)
    body=$(echo "$response" | head -n-1)
    
    if [ "$http_code" == "$expected_status" ]; then
        echo -e "${GREEN}✓ Status: $http_code (Expected: $expected_status)${NC}"
        echo -e "Response: ${body:0:100}..."
    else
        echo -e "${RED}✗ Status: $http_code (Expected: $expected_status)${NC}"
        echo -e "Response: $body"
    fi
    echo ""
}

# Generate valid token
generate_token

# Test 1: My Templates with valid token
echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}TEST 1: My Templates (Valid Token)${NC}"
echo -e "${BLUE}========================================${NC}"
test_endpoint \
    "GET /api/public/student/my-templates" \
    "$BASE_URL/public/student/my-templates?token=$TOKEN" \
    "200"

# Test 2: Template Details with valid token
echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}TEST 2: Template Details (Valid Token)${NC}"
echo -e "${BLUE}========================================${NC}"
test_endpoint \
    "GET /api/public/student/template/{id}/details" \
    "$BASE_URL/public/student/template/$TEMPLATE_ID/details?token=$TOKEN" \
    "200"

# Test 3: Weekly Calendar with valid token
echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}TEST 3: Weekly Calendar (Valid Token)${NC}"
echo -e "${BLUE}========================================${NC}"
test_endpoint \
    "GET /api/public/student/my-weekly-calendar" \
    "$BASE_URL/public/student/my-weekly-calendar?token=$TOKEN" \
    "200"

# Test 4: Invalid token format
echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}TEST 4: Invalid Token Format${NC}"
echo -e "${BLUE}========================================${NC}"
test_endpoint \
    "GET /api/public/student/my-templates (invalid token)" \
    "$BASE_URL/public/student/my-templates?token=invalid-token" \
    "401"

# Test 5: Expired token
echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}TEST 5: Expired Token${NC}"
echo -e "${BLUE}========================================${NC}"

OLD_TS=$(($(date +%s) - 200))
OLD_TOKEN=$(php -r "
    \$dni = '$DNI';
    \$ts = $OLD_TS;
    \$secret = getenv('GYM_SHARE_SECRET');
    \$payload = \"\$dni.\$ts\";
    \$signature = hash_hmac('sha256', \$payload, \$secret);
    echo \"\$payload.\$signature\";
")

test_endpoint \
    "GET /api/public/student/my-templates (expired)" \
    "$BASE_URL/public/student/my-templates?token=$OLD_TOKEN" \
    "401"

# Test 6: No token provided
echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}TEST 6: No Token Provided${NC}"
echo -e "${BLUE}========================================${NC}"
test_endpoint \
    "GET /api/public/student/my-templates (no token)" \
    "$BASE_URL/public/student/my-templates" \
    "401"

# Test 7: Wrong signature
echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}TEST 7: Invalid Signature${NC}"
echo -e "${BLUE}========================================${NC}"

WRONG_TOKEN="$DNI.$(date +%s).wrongsignature"

test_endpoint \
    "GET /api/public/student/my-templates (wrong signature)" \
    "$BASE_URL/public/student/my-templates?token=$WRONG_TOKEN" \
    "401"

echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}Test Suite Completed${NC}"
echo -e "${GREEN}========================================${NC}"
