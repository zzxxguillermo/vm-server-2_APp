# CRITICAL: Add These Lines to Your .env File

Add these lines to your `.env` file. They should go after the LocalTunnel configuration:

```env
# Gym Share Token Configuration (for public student access)
# IMPORTANT: This secret MUST match the secret used by vmServer
# Contact vmServer admin to obtain the correct shared secret
GYM_SHARE_SECRET=your-shared-secret-here-must-match-vmserver
GYM_SHARE_TTL_SECONDS=120
```

## Example Placement in .env

```env
# LocalTunnel Configuration
SANCTUM_STATEFUL_DOMAINS=villamitre.loca.lt
SESSION_DOMAIN=.villamitre.loca.lt

# Gym Share Token Configuration (for public student access)
# IMPORTANT: This secret MUST match the secret used by vmServer
GYM_SHARE_SECRET=your-shared-secret-here-must-match-vmserver
GYM_SHARE_TTL_SECONDS=120
```

## After Adding

Run this command to clear the config cache:

```bash
php artisan config:clear
```

## Getting the Shared Secret

The `GYM_SHARE_SECRET` value **MUST** be the same secret that vmServer uses to generate tokens.

Contact the vmServer administrator at:
- URL: https://appvillamitre.surtekbb.com
- Endpoint that generates tokens: `/api/gym/share-token`

The secret is likely defined in vmServer's environment/config as something like:
- `GYM_SHARE_SECRET`
- `SHARE_TOKEN_SECRET`
- Or similar

**⚠️ Without the correct secret, token validation will always fail with 401 Unauthorized**

## Security Notes

1. **Never commit the secret to version control**
   - The `.env` file should already be in `.gitignore`
   - If using CI/CD, use encrypted secrets

2. **Use a strong secret in production**
   - Minimum 32 characters
   - Random alphanumeric + special characters
   - Example: `openssl rand -base64 32`

3. **Rotate periodically**
   - Change secret every 3-6 months
   - Coordinate changes with vmServer administrator
   - Both systems must update at the same time
