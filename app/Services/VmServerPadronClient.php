<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Response;
use RuntimeException;

class VmServerPadronClient
{
    protected string $baseUrl;
    protected string $internalToken;
    protected int $timeout;

    public function __construct()
    {
        $config = config('services.vmserver') ?? [];
        
        $this->baseUrl = $config['base_url'] ?? '';
        $this->internalToken = $config['internal_token'] ?? '';
        $this->timeout = $config['timeout'] ?? 20;
    }

    /**
     * Obtener socios desde vmServer con paginación
     * 
     * @param array $params Parámetros: updated_since, page, per_page
     * @return array Respuesta decodificada
     * @throws RuntimeException Si la respuesta es un error
     */
    public function fetchSocios(array $params): array
    {
        try {
            \Log::info('[VmServerPadronClient] fetchSocios - Params finales antes de enviar:', $params);
            
            $response = Http::baseUrl($this->baseUrl)
                ->timeout($this->timeout)
                ->withHeaders([
                    'X-Internal-Token' => $this->internalToken,
                    'Accept' => 'application/json',
                ])
                ->get('/api/internal/padron/socios', $params);

            \Log::info('[VmServerPadronClient] fetchSocios - Status: ' . $response->status());
            
            if ($response->failed()) {
                \Log::error('[VmServerPadronClient] fetchSocios - Error response body:', ['body' => $response->body()]);
                throw new RuntimeException(
                    "VmServer API error ({$response->status()}): " . $response->body()
                );
            }

            return $response->json();
        } catch (\Exception $e) {
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
