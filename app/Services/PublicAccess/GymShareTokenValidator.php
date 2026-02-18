<?php

namespace App\Services\PublicAccess;

class GymShareTokenValidator
{
    /**
     * @throws GymShareTokenException
     */
    public function validate(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new GymShareTokenException('invalid_format');
        }

        [$dni, $tsString, $signature] = $parts;

        if (!preg_match('/^\d{7,9}$/', $dni)) {
            throw new GymShareTokenException('invalid_dni');
        }

        if (!ctype_digit($tsString)) {
            throw new GymShareTokenException('invalid_ts');
        }

        $ts = (int) $tsString;
        $now = time();

        $ttl = config('services.gym_share_token.ttl_seconds', 120);
        if ($ttl <= 0) {
            $ttl = 120;
        }

        if (($now - $ts) > $ttl) {
            throw new GymShareTokenException('expired');
        }

        if (($ts - $now) > 30) {
            throw new GymShareTokenException('future_ts');
        }

        $secret = config('services.gym_share_token.secret');
        if (empty($secret)) {
            // Misconfiguration: treat as server error upstream
            throw new GymShareTokenException('missing_secret');
        }

        $payload = $dni . '.' . $tsString;
        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        if (!hash_equals($expectedSignature, $signature)) {
            throw new GymShareTokenException('invalid_signature');
        }

        return [
            'dni' => $dni,
            'ts' => $ts,
        ];
    }
}
