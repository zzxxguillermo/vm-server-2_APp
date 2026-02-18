# Gym Share Token - PowerShell Test Script
# This script tests the public student template endpoints with share tokens

# Configuration
$BaseUrl = if ($env:BASE_URL) { $env:BASE_URL } else { "http://localhost/api" }
$TestDni = if ($env:TEST_DNI) { $env:TEST_DNI } else { "12345678" }
$TemplateId = if ($env:TEST_TEMPLATE_ID) { $env:TEST_TEMPLATE_ID } else { 1 }
$Secret = $env:GYM_SHARE_SECRET

# Check if secret is set
if (-not $Secret) {
    Write-Host "❌ ERROR: GYM_SHARE_SECRET environment variable is not set" -ForegroundColor Red
    Write-Host "   Set it with: `$env:GYM_SHARE_SECRET='your-secret'" -ForegroundColor Yellow
    exit 1
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Blue
Write-Host "Gym Share Token - Test Suite" -ForegroundColor Blue
Write-Host "========================================" -ForegroundColor Blue
Write-Host "Base URL: $BaseUrl"
Write-Host "Test DNI: $TestDni"
Write-Host "Template ID: $TemplateId"
Write-Host ""

# Function to generate HMAC token
function New-GymShareToken {
    param(
        [string]$Dni,
        [int]$Timestamp,
        [string]$Secret
    )
    
    $payload = "$Dni.$Timestamp"
    $hmac = New-Object System.Security.Cryptography.HMACSHA256
    $hmac.Key = [System.Text.Encoding]::UTF8.GetBytes($Secret)
    $hash = $hmac.ComputeHash([System.Text.Encoding]::UTF8.GetBytes($payload))
    $signature = [BitConverter]::ToString($hash).Replace("-", "").ToLower()
    
    return "$payload.$signature"
}

# Function to test an endpoint
function Test-Endpoint {
    param(
        [string]$Name,
        [string]$Url,
        [int]$ExpectedStatus
    )
    
    Write-Host ""
    Write-Host "========================================" -ForegroundColor Blue
    Write-Host "TEST: $Name" -ForegroundColor Blue
    Write-Host "========================================" -ForegroundColor Blue
    Write-Host "URL: $Url"
    
    try {
        $response = Invoke-WebRequest -Uri $Url -Method Get -Headers @{ "Accept" = "application/json" } -SkipCertificateCheck -ErrorAction Stop
        $statusCode = $response.StatusCode
        $body = $response.Content
    }
    catch {
        $statusCode = $_.Exception.Response.StatusCode.value__
        $body = $_.Exception.Response
    }
    
    if ($statusCode -eq $ExpectedStatus) {
        Write-Host "✓ Status: $statusCode (Expected: $ExpectedStatus)" -ForegroundColor Green
    }
    else {
        Write-Host "✗ Status: $statusCode (Expected: $ExpectedStatus)" -ForegroundColor Red
    }
    
    if ($body) {
        $preview = $body.ToString().Substring(0, [Math]::Min(200, $body.Length))
        Write-Host "Response: $preview..."
    }
    
    Write-Host ""
    
    return $statusCode -eq $ExpectedStatus
}

# Generate tokens
$timestamp = [int][double]::Parse((Get-Date -UFormat %s))
$validToken = New-GymShareToken -Dni $TestDni -Timestamp $timestamp -Secret $Secret

$expiredTimestamp = $timestamp - 200
$expiredToken = New-GymShareToken -Dni $TestDni -Timestamp $expiredTimestamp -Secret $Secret

$results = @()

# Test 1: My Templates with valid token
$results += Test-Endpoint `
    -Name "My Templates (Valid Token)" `
    -Url "$BaseUrl/public/student/my-templates?token=$validToken" `
    -ExpectedStatus 200

# Test 2: Template Details with valid token
$results += Test-Endpoint `
    -Name "Template Details (Valid Token)" `
    -Url "$BaseUrl/public/student/template/$TemplateId/details?token=$validToken" `
    -ExpectedStatus 200

# Test 3: Weekly Calendar with valid token
$results += Test-Endpoint `
    -Name "Weekly Calendar (Valid Token)" `
    -Url "$BaseUrl/public/student/my-weekly-calendar?token=$validToken" `
    -ExpectedStatus 200

# Test 4: Invalid token format
$results += Test-Endpoint `
    -Name "Invalid Token Format" `
    -Url "$BaseUrl/public/student/my-templates?token=invalid-token" `
    -ExpectedStatus 401

# Test 5: Expired token
$results += Test-Endpoint `
    -Name "Expired Token" `
    -Url "$BaseUrl/public/student/my-templates?token=$expiredToken" `
    -ExpectedStatus 401

# Test 6: No token provided
$results += Test-Endpoint `
    -Name "No Token Provided" `
    -Url "$BaseUrl/public/student/my-templates" `
    -ExpectedStatus 401

# Test 7: Wrong signature
$wrongToken = "$TestDni.$timestamp.wrongsignature"
$results += Test-Endpoint `
    -Name "Invalid Signature" `
    -Url "$BaseUrl/public/student/my-templates?token=$wrongToken" `
    -ExpectedStatus 401

# Summary
Write-Host ""
Write-Host "========================================" -ForegroundColor Green
Write-Host "Test Summary" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Green

$passed = ($results | Where-Object { $_ -eq $true }).Count
$total = $results.Count

if ($passed -eq $total) {
    Write-Host "Passed: $passed / $total" -ForegroundColor Green
    Write-Host ""
    Write-Host "✓ All tests passed!" -ForegroundColor Green
    exit 0
}
else {
    Write-Host "Passed: $passed / $total" -ForegroundColor Yellow
    Write-Host ""
    Write-Host "✗ Some tests failed. Please review the output above." -ForegroundColor Yellow
    exit 1
}
