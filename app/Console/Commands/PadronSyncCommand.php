<?php

namespace App\Console\Commands;

use App\Models\SocioPadron;
use App\Models\SyncState;
use App\Services\VmServerPadronClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class PadronSyncCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'padron:sync {--since=} {--per-page=500}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sincronizar socios desde vmServer hacia tabla socios_padron';

    protected VmServerPadronClient $client;

    public function __construct(VmServerPadronClient $client)
    {
        parent::__construct();
        $this->client = $client;
    }

    public function handle()
    {
        try {
            // Punto 1: Obtener $since sin normalizar
            $since = $this->determineSince();
            $perPage = (int) $this->option('per-page');
            $this->line("[LOG] $since RAW (sin normalizar): {$since}");

            // Punto 3: Normalizar formato de $since para vmServer (Y-m-d\TH:i:s, sin Z)
            if (!empty($since)) {
                $sinceRaw = $since;
                    $since = Carbon::parse($since)->utc()->format('Y-m-d\TH:i:s') . 'Z';
                $this->line("[LOG] $since NORMALIZADO: {$since}");
                $this->line("       Raw: {$sinceRaw}");
            }

            $this->info("🔄 Iniciando sincronización de socios desde vmServer");
            $this->info("  • Desde: {$since}");
            $this->info("  • Por página: {$perPage}");
            $this->newLine();

            $page = 1;
            $totalUpserted = 0;
            $totalProcessed = 0;
            $lastServerTime = null;

            while (true) {
                $this->line("📄 Obteniendo página {$page}...");

                // Punto 4: Armar params para vmServer
                $params = [
                    'updated_since' => $since,
                    'page' => $page,
                    'per_page' => $perPage,
                ];
                $this->line("[LOG] Params enviados a client.fetchSocios(): " . json_encode($params));

                $response = $this->client->fetchSocios($params);

                $items = $response['data'] ?? [];
                $currentPage = $response['pagination']['current_page'] ?? $page;
                $lastPage = $response['pagination']['last_page'] ?? 1;
                $serverTime = $response['server_time'] ?? now()->toIso8601String();

                if (empty($items)) {
                    $this->warn("  ⚠️  Sin resultados en página {$page}");
                    break;
                }

                // Procesar socios
                $upserted = $this->upsertSocios($items);
                $totalUpserted += $upserted;
                $totalProcessed += count($items);

                $this->info("  ✓ Página {$currentPage}: {$upserted}/{count($items)} upsertados");

                $lastServerTime = $serverTime;

                // Verificar si llegamos a la última página
                if ($currentPage >= $lastPage) {
                    break;
                }

                $page++;
            }

            // Actualizar last_sync - normalizar formato para vmServer (Y-m-d\TH:i:s, sin Z)
            $syncTime = $lastServerTime ?? now()->toIso8601String();
                $syncTime = Carbon::parse($syncTime)->utc()->format('Y-m-d\TH:i:s') . 'Z';
            SyncState::setValue('padron_last_sync_at', $syncTime);

            $this->newLine();
            $this->info("✅ Sincronización completada");
            $this->info("  • Total procesados: {$totalProcessed}");
            $this->info("  • Total upsertados: {$totalUpserted}");
            $this->info("  • Último sync: {$syncTime}");

            return 0;
        } catch (\Exception $e) {
            $this->error("❌ Error en la sincronización: " . $e->getMessage());
            return 1;
        }
    }

    /**
     * Determinar la fecha desde la cual sincronizar
     */
    protected function determineSince(): string
    {
        // Punto 1: Leer --since desde CLI
        if ($this->option('since')) {
            $cliSince = $this->option('since');
            $this->line("[LOG] --since de CLI: {$cliSince}");
            return $cliSince;
        }

        // Punto 2: Leer desde SyncState
        $lastSync = SyncState::getValue('padron_last_sync_at');
        if ($lastSync) {
            $this->line("[LOG] last sync de SyncState: {$lastSync}");
            return $lastSync;
        }

        // Default: últimas 24 horas
        $default = now()->subDay()->toIso8601String();
        $this->line("[LOG] usando default (24h atrás): {$default}");
        return $default;
    }

    /**
     * Realizar upsert de socios, separando por sid y dni
     */
    protected function upsertSocios(array $items): int
    {
        $rowsWithSid = [];
        $rowsWithoutSid = [];

        foreach ($items as $item) {
            $row = $this->mapItemToRow($item);

            if (!empty($row['sid'])) {
                $rowsWithSid[] = $row;
            } else {
                $rowsWithoutSid[] = $row;
            }
        }

        $upserted = 0;

        // Upsert por SID
        if (!empty($rowsWithSid)) {
            SocioPadron::upsert(
                $rowsWithSid,
                ['sid'],
                $this->getUpsertableColumns()
            );
            $upserted += count($rowsWithSid);
        }

        // Upsert por DNI
        if (!empty($rowsWithoutSid)) {
            SocioPadron::upsert(
                $rowsWithoutSid,
                ['dni'],
                $this->getUpsertableColumns()
            );
            $upserted += count($rowsWithoutSid);
        }

        return $upserted;
    }

    /**
     * Mapear item de la API a fila de la tabla
     */
    protected function mapItemToRow(array $item): array
    {
        return [
            'dni' => $item['dni'] ?? null,
            'sid' => $item['sid'] ?? null,
            'apynom' => $item['apynom'] ?? $item['apellido'] ?? null,
            'barcode' => $item['barcode'] ?? null,
            'saldo' => isset($item['saldo']) ? (float) $item['saldo'] : null,
            'semaforo' => isset($item['semaforo']) ? (int) $item['semaforo'] : null,
            'ult_impago' => isset($item['ult_impago']) ? (int) $item['ult_impago'] : null,
            'acceso_full' => (bool) ($item['acceso_full'] ?? false),
            'hab_controles' => (bool) ($item['hab_controles'] ?? true),
            'hab_controles_raw' => isset($item['hab_controles_raw']) ? json_encode((array) $item['hab_controles_raw'], JSON_UNESCAPED_UNICODE) : null,
            'raw' => isset($item) ? json_encode($item, JSON_UNESCAPED_UNICODE) : null, // Guardar el objeto completo como JSON string
        ];
    }

    /**
     * Obtener columnas que se pueden actualizar en upsert
     */
    protected function getUpsertableColumns(): array
    {
        return [
            'apynom',
            'barcode',
            'saldo',
            'semaforo',
            'ult_impago',
            'acceso_full',
            'hab_controles',
            'hab_controles_raw',
            'raw',
            'updated_at',
        ];
    }
}
