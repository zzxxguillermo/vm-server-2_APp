# Gym Share Token - Quick Start Guide

## What Was Fixed

Your public student endpoints were returning 401 because:
1. Configuration was incomplete (missing `GYM_SHARE_SECRET`)
2. Weekly calendar endpoint was missing from public controller
3. Validator was reading from `env()` instead of `config()`

## Files Changed

✅ **config/services.php** - Added `gym_share_token` configuration
✅ **app/Services/PublicAccess/GymShareTokenValidator.php** - Use config() instead of env()
✅ **app/Http/Controllers/Public/StudentPublicTemplatesController.php** - Added myWeeklyCalendar()
✅ **routes/api.php** - Added public route for weekly calendar

## Quick Setup (2 Steps)

### Step 1: Set the Shared Secret

Add to your `.env` file:

```env
# IMPORTANT: This MUST match the secret used by vmServer
GYM_SHARE_SECRET=your-shared-secret-here
GYM_SHARE_TTL_SECONDS=120
```

**⚠️ Contact vmServer admin to get the correct shared secret!**

### Step 2: Clear Config Cache

```bash
php artisan config:clear
```

## Test It Works

### Option A: Use PHP Test Script (Quickest)

```bash
# Set the secret (use actual secret from vmServer)
export GYM_SHARE_SECRET='your-secret'

# Run tests
php test-gym-share-token.php
```

### Option B: Use PowerShell (Windows)

```powershell
# Set the secret
$env:GYM_SHARE_SECRET='your-secret'

# Run tests
.\test-gym-share-token.ps1
```

### Option C: Manual curl Test

```bash
# 1. Generate a token
php -r "
\$dni = '12345678';
\$ts = time();
\$secret = 'your-secret-here';
\$payload = \"\$dni.\$ts\";
\$signature = hash_hmac('sha256', \$payload, \$secret);
echo \"\$payload.\$signature\";
"

# 2. Use the token (replace TOKEN with output from step 1)
curl "http://localhost/api/public/student/my-templates?token=TOKEN"
```

## Frontend Integration

### Current (Wrong - causes 401):
```typescript
fetch('/api/student/template/9/details', {
  headers: { 'Authorization': `Bearer ${shareToken}` }
});
```

### Fixed (Correct):
```typescript
// Use /api/public/* endpoints with token as query param
fetch(`/api/public/student/template/9/details?token=${shareToken}`);
```

## Public Endpoints Available

All three endpoints now work with share tokens:

```
GET /api/public/student/my-templates?token={shareToken}
GET /api/public/student/template/{id}/details?token={shareToken}
GET /api/public/student/my-weekly-calendar?token={shareToken}
```

## Token Format

Format: `dni.timestamp.signature`

Example: `12345678.1708123456.abc123def456...`

- **dni**: 7-9 digit DNI
- **timestamp**: Unix timestamp
- **signature**: HMAC-SHA256 of "dni.timestamp"
- **TTL**: 120 seconds (configurable)

## Troubleshooting

### "401 Unauthorized"
- Check `GYM_SHARE_SECRET` is set in `.env`
- Verify secret matches vmServer
- Run `php artisan config:clear`

### "500 Internal Server Error"
- `GYM_SHARE_SECRET` not set
- Add to `.env` and clear config

### "404 User Not Found"
- User with that DNI doesn't exist locally
- Backend will try to fetch from vmServer or SocioPadron
- If still fails, user truly doesn't exist

## Next Steps

1. ✅ Set `GYM_SHARE_SECRET` in `.env`
2. ✅ Run `php artisan config:clear`
3. ✅ Test with `php test-gym-share-token.php`
4. ✅ Update frontend to use `/api/public/*` endpoints
5. ✅ Deploy to production

## Documentation

For detailed documentation, see:
- [GYM_SHARE_TOKEN_IMPLEMENTATION.md](GYM_SHARE_TOKEN_IMPLEMENTATION.md)

## Support

If you encounter issues:
1. Check the detailed logs in Laravel
2. Verify `GYM_SHARE_SECRET` matches vmServer
3. Test with the provided test scripts
4. Review [GYM_SHARE_TOKEN_IMPLEMENTATION.md](GYM_SHARE_TOKEN_IMPLEMENTATION.md)
