# Gym Share Token Implementation Guide

## Overview

This document explains the public student template access system using share tokens from vmServer.

## Architecture

### Token Flow
```
┌─────────────┐      1. Request share token      ┌──────────────┐
│   Frontend  │ ──────────────────────────────> │   vmServer   │
│             │ <────────────────────────────── │              │
└─────────────┘   2. Returns: dni.ts.sig        └──────────────┘
       │
       │ 3. Call /api/public/student/...?token=dni.ts.sig
       │
       ▼
┌─────────────────────────────────────────────────────────┐
│  vm-gym-api (this backend)                              │
│  ┌───────────────────────────────────────────────────┐  │
│  │  StudentPublicTemplatesController                 │  │
│  │  1. Validate token (HMAC signature + TTL)         │  │
│  │  2. Extract DNI from token                        │  │
│  │  3. Resolve/materialize user by DNI               │  │
│  │  4. Delegate to protected controller logic        │  │
│  └───────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────┘
```

## Components

### 1. Token Format: `dni.timestamp.signature`

Example: `12345678.1708123456.abc123def456...`

**Parts:**
- `dni`: 7-9 digit DNI number
- `timestamp`: Unix timestamp when token was generated
- `signature`: HMAC-SHA256 of "dni.timestamp" with shared secret

**Security:**
- HMAC signature prevents tampering
- Timestamp prevents replay attacks (120 second TTL)
- Shared secret known only to vmServer and vm-gym-api

### 2. Configuration

#### `.env` (REQUIRED - ADD THESE)
```env
# Gym Share Token Configuration (for public student access)
# IMPORTANT: This secret MUST match the secret used by vmServer
GYM_SHARE_SECRET=your-shared-secret-here-must-match-vmserver
GYM_SHARE_TTL_SECONDS=120
```

#### `config/services.php` (Already Updated)
```php
'gym_share_token' => [
    'secret' => env('GYM_SHARE_SECRET'),
    'ttl_seconds' => (int) env('GYM_SHARE_TTL_SECONDS', 120),
],
```

### 3. Routes

#### Public Routes (No Auth Required)
```
GET /api/public/student/my-templates?token={shareToken}
GET /api/public/student/template/{templateAssignmentId}/details?token={shareToken}
GET /api/public/student/my-weekly-calendar?token={shareToken}
```

#### Protected Routes (Auth Required - Existing)
```
GET /api/student/my-templates
GET /api/student/template/{templateAssignmentId}/details
GET /api/student/my-weekly-calendar
```

### 4. Controllers

**`StudentPublicTemplatesController`** handles all public access:
- Validates share token using `GymShareTokenValidator`
- Resolves user by DNI (from local DB, vmServer, or SocioPadron)
- Sets auth context and delegates to `Student\AssignmentController`
- Returns identical response shape as protected endpoints

**`GymShareTokenValidator`** validates tokens:
- Parses `dni.ts.sig` format
- Verifies HMAC signature
- Checks TTL (not expired, not from future)
- Throws `GymShareTokenException` on validation failure

## Response Contracts

All endpoints return consistent JSON structure:

### Success (200)
```json
{
  "message": "Success message",
  "data": {
    // Same structure as protected endpoints
  }
}
```

### Error (401/404/422/500)
```json
{
  "ok": false,
  "message": "Error description"
}
```

## Implementation Summary

### ✅ What Was Implemented

1. **Configuration Centralization**
   - Moved from `env()` to `config()` in validator
   - Added `gym_share_token` config in `services.php`

2. **Missing Endpoint Added**
   - Added `myWeeklyCalendar()` to `StudentPublicTemplatesController`
   - Added public route for weekly calendar

3. **Consistent Token Handling**
   - All three public endpoints use same validation flow
   - Same error responses across all endpoints

### 🔧 What Remains (Configuration)

1. **Set Shared Secret in `.env`**
   ```env
   GYM_SHARE_SECRET=<obtain-from-vmserver-admin>
   ```
   ⚠️ **CRITICAL**: This MUST match vmServer's secret!

2. **Optional TTL Override**
   ```env
   GYM_SHARE_TTL_SECONDS=120  # Default is 120
   ```

## Testing

### Prerequisites
1. Set `GYM_SHARE_SECRET` in `.env`
2. Ensure user with DNI exists (or can be materialized)
3. Generate valid token from vmServer or manually

### Manual Token Generation (Testing Only)

**For local testing**, generate a token:
```php
$dni = '12345678';
$ts = time();
$secret = config('services.gym_share_token.secret');
$payload = "$dni.$ts";
$signature = hash_hmac('sha256', $payload, $secret);
$token = "$payload.$signature";
```

### Test Cases

#### 1. Valid Token - My Templates
```bash
curl -X GET "https://your-domain/api/public/student/my-templates?token=12345678.1708123456.abc123..." \
  -H "Accept: application/json"
```

**Expected:** 200 OK with templates data

#### 2. Valid Token - Template Details
```bash
curl -X GET "https://your-domain/api/public/student/template/9/details?token=12345678.1708123456.abc123..." \
  -H "Accept: application/json"
```

**Expected:** 200 OK with template details

#### 3. Valid Token - Weekly Calendar
```bash
curl -X GET "https://your-domain/api/public/student/my-weekly-calendar?token=12345678.1708123456.abc123..." \
  -H "Accept: application/json"
```

**Expected:** 200 OK with weekly calendar

#### 4. Invalid Token Format
```bash
curl -X GET "https://your-domain/api/public/student/my-templates?token=invalid" \
  -H "Accept: application/json"
```

**Expected:** 401 Unauthorized
```json
{"ok": false, "message": "Unauthorized"}
```

#### 5. Expired Token
Generate token with old timestamp (>120 seconds ago):
```bash
curl -X GET "https://your-domain/api/public/student/my-templates?token=12345678.1234567890.sig..." \
  -H "Accept: application/json"
```

**Expected:** 401 Unauthorized

#### 6. Invalid Signature
```bash
curl -X GET "https://your-domain/api/public/student/my-templates?token=12345678.1708123456.wrongsig" \
  -H "Accept: application/json"
```

**Expected:** 401 Unauthorized

#### 7. No Token Provided
```bash
curl -X GET "https://your-domain/api/public/student/my-templates" \
  -H "Accept: application/json"
```

**Expected:** 401 Unauthorized (unless bypass mode enabled)

#### 8. User Not Found
Use valid token with non-existent DNI:
```bash
curl -X GET "https://your-domain/api/public/student/my-templates?token=99999999.1708123456.validsig..." \
  -H "Accept: application/json"
```

**Expected:** 404 Not Found
```json
{"ok": false, "message": "User not found"}
```

#### 9. Missing Secret (Server Misconfiguration)
With `GYM_SHARE_SECRET` not set in `.env`:

**Expected:** 500 Internal Server Error
```json
{"ok": false, "message": "Internal server error"}
```

## Frontend Integration

### Required Changes

**BEFORE (Wrong - causes 401):**
```typescript
// Frontend calling protected endpoint
const response = await fetch('/api/student/template/9/details', {
  headers: {
    'Authorization': `Bearer ${shareToken}` // Wrong!
  }
});
```

**AFTER (Correct):**
```typescript
// 1. Get share token from vmServer
const tokenResponse = await fetch(
  'https://appvillamitre.surtekbb.com/api/gym/share-token',
  { credentials: 'include' }
);
const { token } = await tokenResponse.json();

// 2. Use PUBLIC endpoint with token as query param
const response = await fetch(
  `/api/public/student/template/9/details?token=${token}`,
  {
    headers: { 'Accept': 'application/json' }
  }
);
```

### Endpoint Mapping

| Protected Endpoint (auth:sanctum) | Public Endpoint (share-token) |
|-----------------------------------|-------------------------------|
| `/api/student/my-templates` | `/api/public/student/my-templates?token=...` |
| `/api/student/template/{id}/details` | `/api/public/student/template/{id}/details?token=...` |
| `/api/student/my-weekly-calendar` | `/api/public/student/my-weekly-calendar?token=...` |

## Security Considerations

### ✅ Security Features
1. **HMAC Signature**: Prevents token tampering
2. **Short TTL**: 120 second expiration limits exposure
3. **Timestamp Validation**: Prevents replay attacks
4. **Rate Limiting**: 30 requests/minute per IP
5. **Shared Secret**: Known only to authorized services

### ⚠️ Important Notes
1. **Token in URL**: Share tokens appear in query params and server logs
   - Acceptable because TTL is very short (120s)
   - Logs should be monitored/rotated appropriately
   
2. **No Authorization**: Token only authenticates identity (DNI)
   - Additional authorization checks happen in controller
   - Students can only access their own assignments

3. **Secret Management**:
   - Never commit `GYM_SHARE_SECRET` to version control
   - Use environment variables or secret management service
   - Rotate secret periodically (coordinate with vmServer)

## Troubleshooting

### Issue: 401 Unauthorized

**Possible Causes:**
1. `GYM_SHARE_SECRET` not set in `.env`
2. Secret mismatch between vmServer and vm-gym-api
3. Token expired (>120 seconds old)
4. Invalid token format
5. Wrong HMAC signature

**Solution:**
```bash
# Check if secret is set
php artisan tinker
> config('services.gym_share_token.secret')

# Verify it matches vmServer secret
# If not, update .env and clear config cache:
php artisan config:clear
```

### Issue: 404 User Not Found

**Possible Causes:**
1. User doesn't exist in local database
2. vmServer is unreachable
3. User not in SocioPadron

**Solution:**
- Check if user exists: `User::where('dni', '12345678')->first()`
- Verify vmServer connection: `config('services.vmserver.base_url')`
- Check SocioPadron: `SocioPadron::where('dni', '12345678')->first()`

### Issue: 500 Internal Server Error

**Most Common Cause:** `GYM_SHARE_SECRET` not configured

**Solution:**
```env
# Add to .env
GYM_SHARE_SECRET=your-secret-here
```

Then clear config cache:
```bash
php artisan config:clear
```

## Development vs Production

### Development
```env
GYM_SHARE_SECRET=dev-secret-12345
GYM_SHARE_TTL_SECONDS=300  # Longer for testing
```

### Production
```env
GYM_SHARE_SECRET=<strong-production-secret>
GYM_SHARE_TTL_SECONDS=120  # Stricter TTL
```

## Files Modified

1. ✅ `config/services.php` - Added gym_share_token config
2. ✅ `app/Services/PublicAccess/GymShareTokenValidator.php` - Use config() instead of env()
3. ✅ `app/Http/Controllers/Public/StudentPublicTemplatesController.php` - Added myWeeklyCalendar()
4. ✅ `routes/api.php` - Added public weekly calendar route
5. ⚠️ `.env` - **YOU MUST ADD** GYM_SHARE_SECRET

## Next Steps

1. **Configure Secret** (REQUIRED)
   ```env
   GYM_SHARE_SECRET=<obtain-from-vmserver>
   ```

2. **Test Locally**
   - Generate test token
   - Run manual curl tests
   - Verify 200 responses

3. **Update Frontend**
   - Change from `/api/student/*` to `/api/public/student/*`
   - Pass token as query param, not Bearer header
   - Handle 401/404 errors appropriately

4. **Deploy**
   - Update production `.env` with shared secret
   - Clear config cache: `php artisan config:clear`
   - Monitor logs for token validation errors

## Conclusion

The implementation is **complete** except for configuration. Once `GYM_SHARE_SECRET` is set in `.env` to match vmServer's secret, the public student endpoints will work correctly with share tokens.

**No middleware refactoring was needed** - the existing controller-based validation approach is clean, isolated, and works well.
