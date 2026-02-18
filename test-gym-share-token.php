<?php

/**
 * Gym Share Token - Quick Test Script
 * 
 * This script tests the gym share token public endpoints
 * 
 * Usage:
 *   php test-gym-share-token.php
 * 
 * Environment Variables:
 *   GYM_SHARE_SECRET - The shared secret (required)
 *   BASE_URL - Base URL of the API (default: http://localhost/api)
 *   TEST_DNI - DNI to test with (default: 12345678)
 *   TEST_TEMPLATE_ID - Template assignment ID to test (default: 1)
 */

// Configuration
$baseUrl = getenv('BASE_URL') ?: 'http://localhost/api';
$secret = getenv('GYM_SHARE_SECRET');
$testDni = getenv('TEST_DNI') ?: '12345678';
$testTemplateId = getenv('TEST_TEMPLATE_ID') ?: 1;

if (empty($secret)) {
    echo "❌ ERROR: GYM_SHARE_SECRET environment variable is not set\n";
    echo "   Set it with: export GYM_SHARE_SECRET='your-secret' (Linux/Mac)\n";
    echo "   Or: \$env:GYM_SHARE_SECRET='your-secret' (Windows PowerShell)\n";
    exit(1);
}

function generateToken($dni, $secret) {
    $ts = time();
    $payload = "$dni.$ts";
    $signature = hash_hmac('sha256', $payload, $secret);
    return "$payload.$signature";
}

function generateExpiredToken($dni, $secret) {
    $ts = time() - 200; // 200 seconds ago
    $payload = "$dni.$ts";
    $signature = hash_hmac('sha256', $payload, $secret);
    return "$payload.$signature";
}

function testEndpoint($name, $url, $expectedStatus) {
    echo "\n";
    echo "========================================\n";
    echo "TEST: $name\n";
    echo "========================================\n";
    echo "URL: $url\n";
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For local testing
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $status = $httpCode == $expectedStatus ? '✓' : '✗';
    $color = $httpCode == $expectedStatus ? "\033[0;32m" : "\033[0;31m";
    $reset = "\033[0m";
    
    echo "{$color}{$status} Status: $httpCode (Expected: $expectedStatus){$reset}\n";
    
    if ($response) {
        $json = json_decode($response, true);
        if ($json) {
            echo "Response: " . json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        } else {
            echo "Response: " . substr($response, 0, 200) . "...\n";
        }
    }
    
    return $httpCode == $expectedStatus;
}

echo "\n";
echo "========================================\n";
echo "Gym Share Token - Test Suite\n";
echo "========================================\n";
echo "Base URL: $baseUrl\n";
echo "Test DNI: $testDni\n";
echo "Template ID: $testTemplateId\n";
echo "\n";

$token = generateToken($testDni, $secret);
$expiredToken = generateExpiredToken($testDni, $secret);

$results = [];

// Test 1: My Templates with valid token
$results[] = testEndpoint(
    'My Templates (Valid Token)',
    "$baseUrl/public/student/my-templates?token=$token",
    200
);

// Test 2: Template Details with valid token
$results[] = testEndpoint(
    'Template Details (Valid Token)',
    "$baseUrl/public/student/template/$testTemplateId/details?token=$token",
    200
);

// Test 3: Weekly Calendar with valid token
$results[] = testEndpoint(
    'Weekly Calendar (Valid Token)',
    "$baseUrl/public/student/my-weekly-calendar?token=$token",
    200
);

// Test 4: Invalid token format
$results[] = testEndpoint(
    'Invalid Token Format',
    "$baseUrl/public/student/my-templates?token=invalid-token",
    401
);

// Test 5: Expired token
$results[] = testEndpoint(
    'Expired Token',
    "$baseUrl/public/student/my-templates?token=$expiredToken",
    401
);

// Test 6: No token provided
$results[] = testEndpoint(
    'No Token Provided',
    "$baseUrl/public/student/my-templates",
    401
);

// Test 7: Wrong signature
$wrongToken = "$testDni." . time() . ".wrongsignature";
$results[] = testEndpoint(
    'Invalid Signature',
    "$baseUrl/public/student/my-templates?token=$wrongToken",
    401
);

// Summary
echo "\n";
echo "========================================\n";
echo "Test Summary\n";
echo "========================================\n";

$passed = count(array_filter($results));
$total = count($results);
$color = $passed == $total ? "\033[0;32m" : "\033[0;33m";
$reset = "\033[0m";

echo "{$color}Passed: $passed / $total{$reset}\n";

if ($passed == $total) {
    echo "\n✓ All tests passed!\n";
    exit(0);
} else {
    echo "\n✗ Some tests failed. Please review the output above.\n";
    exit(1);
}
