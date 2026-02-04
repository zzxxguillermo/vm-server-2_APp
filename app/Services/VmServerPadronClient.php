<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class VmServerPadronClient
{
    protected string $baseUrl;
    protected string $internalToken;
    protected int $timeout;

    public function __construct()
    {
        $config = config('services.vmserver') ?? [];

        // ✅ Normalizar baseUrl (evitar //)
        $this->baseUrl = rtrim((string)($config['base_url'] ?? ''), '/');

        // ✅ Token interno real desde config
        $this->internalToken = (string)($config['internal_token'] ?? '');

        // ✅ Timeout configurable
        $this->timeout = (int)($config['timeout'] ?? 20);
    }

    /**
     * Obtener socios desde vmServer con paginación
     *
     * @param array $params Parámetros: updated_since, page, per_page, dni, sid
     * @return array Respuesta decodificada
     * @throws RuntimeException Si la respuesta es un error
     */
    public function fetchSocios(array $params): array
    {
        try {
            // Filtrar params vacíos (ej: updated_since vacío no se envía)
            $params = array_filter($params, fn($v) => $v !== null && $v !== '');

            $path = '/api/internal/padron/socios';
            $fullUrl = $this->baseUrl . $path . (count($params) ? ('?' . http_build_query($params)) : '');

            // ✅ Validar config mínima antes de pegarle
            if (empty($this->baseUrl)) {
                throw new RuntimeException('VmServerPadronClient: base_url is empty (check config/services.php and .env)');
            }
            if (empty($this->internalToken)) {
                throw new RuntimeException('VmServerPadronClient: internal_token is empty (check config/services.php and .env)');
            }

            // Loguear URL (seguro)
            \Log::info('[HTTP] GET ' . $fullUrl);

            // ✅ Loguear token enmascarado (para verificar que NO está vacío)
            $masked = substr($this->internalToken, 0, 6) . '...' . substr($this->internalToken, -4);
            \Log::info('[HTTP] Using X-Internal-Token (masked)', ['token' => $masked]);

            // ✅ Mandar el token REAL (antes estabas mandando "***MASKED***")
            $response = Http::baseUrl($this->baseUrl)
                ->timeout($this->timeout)
                ->acceptJson()
                ->withHeaders([
                    'X-Internal-Token' => $this->internalToken,
                ])
                ->get($path, $params);

            // Loguear status y headers relevantes
            $responseHeaders = $response->headers();
            \Log::info('[HTTP] Status ' . $response->status(), [
                'content_type' => $responseHeaders['content-type'][0] ?? null,
                'server' => $responseHeaders['server'][0] ?? null,
            ]);

            // Loguear body truncado (ojo: no debería incluir secretos)
            $body = (string) $response->body();
            \Log::info('[HTTP] Body snippet (total ' . strlen($body) . ' chars)', [
                'snippet' => substr($body, 0, 1500),
            ]);

            if (!$response->successful()) {
                \Log::error('[HTTP] Error response', [
                    'status' => $response->status(),
                    'snippet' => substr($body, 0, 1000),
                ]);

                throw new RuntimeException(
                    "VmServer API error ({$response->status()}): " . substr($body, 0, 500)
                );
            }

            $json = $response->json();

            // Loguear resumen de respuesta
            $data = is_array($json) ? ($json['data'] ?? null) : null;
            \Log::info('[RESPONSE] Summary', [
                'data_count' => is_array($data) ? count($data) : 0,
                'response_keys' => is_array($json) ? array_keys($json) : null,
                'pagination' => $json['pagination'] ?? null,
                'server_time' => $json['server_time'] ?? null,
            ]);

            return is_array($json) ? $json : [];
        } catch (\Exception $e) {
            \Log::error('[EXCEPTION] fetchSocios failed', [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);

            throw new RuntimeException(
                "Error fetching socios from vmServer: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Obtener un socio por DNI
     */
    public function fetchSocioByDni(string $dni): ?array
    {
        $response = $this->fetchSocios(['dni' => $dni, 'per_page' => 1]);

        $items = $response['data'] ?? [];
        return !empty($items) ? $items[0] : null;
    }

    /**
     * Obtener un socio por SID
     */
    public function fetchSocioBySid(string $sid): ?array
    {
        $response = $this->fetchSocios(['sid' => $sid, 'per_page' => 1]);

        $items = $response['data'] ?? [];
        return !empty($items) ? $items[0] : null;
    }
}
