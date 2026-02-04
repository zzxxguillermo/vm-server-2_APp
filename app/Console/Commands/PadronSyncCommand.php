<?php

namespace App\Console\Commands;

use App\Models\SocioPadron;
use App\Models\SyncState;
use App\Services\VmServerPadronClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Arr;

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

            // Punto 3: Normalizar formato de $since para vmServer (Y-m-d\TH:i:sZ, Z literal)
            if (!empty($since)) {
                $sinceRaw = $since;
                $since = Carbon::parse($since)->utc()->format('Y-m-d\TH:i:s') . 'Z';
                $this->line("[LOG] RAW (sin normalizar): {$sinceRaw}");
                $this->line("[LOG] NORMALIZADO: {$since}");
            }

            $this->info("🔄 Iniciando sincronización de socios desde vmServer");
            $this->info("  • Desde: {$since}");
            $this->info("  • Por página: {$perPage}");
            $this->newLine();

            $page = 1;
            $totalUpserted = 0;
            $totalProcessed = 0;
            $lastServerTime = null;

            $currentPage = 0;
            $lastPage = 0;
            do {
                $this->info("📄 Obteniendo página {$page}...");

                // Punto 4: Armar params para vmServer
                $params = [
                    'updated_since' => $since,
                    'page' => $page,
                    'per_page' => $perPage,
                ];

                $this->line('[LOG] Params enviados a client.fetchSocios(): ' . json_encode($params));

                $response = $this->client->fetchSocios($params);

                // (2) Logs seguros post-fetch (sin concatenar arrays)
                $data = is_array($response) ? ($response['data'] ?? null) : null;
                $first = (is_array($data) && isset($data[0]) && is_array($data[0])) ? $data[0] : null;
                logger()->info('PadronSync: fetchSocios response summary', [
                    'response_type' => gettype($response),
                    'response_keys' => is_array($response) ? array_slice(array_keys($response), 0, 20) : null,
                    'data_type' => gettype($data),
                    'data_count' => is_array($data) ? count($data) : null,
                    'first_item_keys' => is_array($first) ? array_slice(array_keys($first), 0, 40) : null,
                ]);

                // Si no hay data, evitar fallos raros
                if (!is_array($data)) {
                    logger()->warning('PadronSync: response[data] no es array', [
                        'data' => $data,
                    ]);
                    break;
                }

                $rows = [];
                foreach ($data as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $rows[] = $this->mapItemToRow($item);
                }

                $this->upsertSocios($rows);

                $currentPage = (int) ($response['pagination']['current_page'] ?? $page);
                $lastPage = (int) ($response['pagination']['last_page'] ?? $page);

                $page++;
            } while ($currentPage < $lastPage);

            // Guardar last sync (mismo formato que vmServer espera)
            $lastServerTime = $response['server_time'] ?? null;

            $syncTime = $lastServerTime ?? now('UTC')->format('Y-m-d\TH:i:s') . 'Z';
            $syncTime = Carbon::parse($syncTime)->utc()->format('Y-m-d\TH:i:s') . 'Z';
            SyncState::setValue('padron_last_sync_at', $syncTime);

            $this->newLine();
            $this->info("✅ Sincronización completada");
            $this->info("  • Total procesados: {$totalProcessed}");
            $this->info("  • Total upsertados: {$totalUpserted}");
            $this->info("  • Último sync: {$syncTime}");

            return 0;
        } catch (\Throwable $e) {
            // (1) Catch con file/line + primeros 10 frames del trace
            $this->error("❌ Error en la sincronización: " . $e->getMessage());
            logger()->error('PadronSync: exception', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace_top10' => array_slice($e->getTrace(), 0, 10),
            ]);
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
    protected function upsertSocios(array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        // (3) Detector antes de persistir: encuentra el primer valor no escalar
        foreach ($rows as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($row as $k => $v) {
                if (is_array($v) || is_object($v)) {
                    $preview = is_object($v)
                        ? ('object:' . get_class($v))
                        : substr(json_encode($v, JSON_UNESCAPED_UNICODE), 0, 500);
                    logger()->error('PadronSync: non-scalar value detected before upsert', [
                        'row_index' => $i,
                        'dni' => $row['dni'] ?? null,
                        'sid' => $row['sid'] ?? null,
                        'key' => $k,
                        'type' => gettype($v),
                        'preview' => $preview,
                    ]);
                    // Tirar excepción para cortar y ubicar EXACTO el culpable
                    throw new \RuntimeException("Non-scalar: {$k} row {$i} dni=" . ($row['dni'] ?? 'null') . " sid=" . ($row['sid'] ?? 'null'));
                }
            }
        }

        // (4) Fix robusto: si querés que NO corte sino que convierta automáticamente,
        // descomentá este bloque y comentá el throw de arriba.
        //
        // foreach ($rows as $i => $row) {
        //     foreach ($row as $k => $v) {
        //         if (is_array($v) || is_object($v)) {
        //             $rows[$i][$k] = json_encode($v, JSON_UNESCAPED_UNICODE);
        //         }
        //     }
        // }

        // separa por sid vs dni
        $rowsWithSid = array_values(array_filter($rows, fn ($r) => !empty($r['sid'] ?? null)));
        $rowsWithoutSid = array_values(array_filter($rows, fn ($r) => empty($r['sid'] ?? null) && !empty($r['dni'] ?? null)));

        $cols = $this->getUpsertableColumns();

        if (!empty($rowsWithSid)) {
            SocioPadron::upsert($rowsWithSid, ['sid'], $cols);
        }

        if (!empty($rowsWithoutSid)) {
            SocioPadron::upsert($rowsWithoutSid, ['dni'], $cols);
        }
    }

    /**
     * Mapear item de la API a fila de la tabla
     */
    protected function mapItemToRow(array $item): array
    {
        // Asegurar que raw siempre sea string JSON
        $raw = $item['raw'] ?? $item;
        if (is_array($raw) || is_object($raw)) {
            $raw = json_encode($raw, JSON_UNESCAPED_UNICODE);
        }

        // Normalizar hab_controles: guardar original en hab_controles_raw, valor normalizado en hab_controles
        $habControlsRaw = $item['hab_controles'] ?? null;
        $habControls = $this->normalizeHabControles($habControlsRaw, $item['dni'] ?? null, $item['sid'] ?? null);

        $habControlsRawJson = null;
        if ($habControlsRaw !== null) {
            if (is_array($habControlsRaw) || is_object($habControlsRaw)) {
                $habControlsRawJson = json_encode($habControlsRaw, JSON_UNESCAPED_UNICODE);
            } else {
                $habControlsRawJson = is_string($habControlsRaw) ? $habControlsRaw : json_encode($habControlsRaw);
            }
        }

        return [
            'dni' => $item['dni'] ?? null,
            'sid' => $item['sid'] ?? null,
            'apynom' => $item['apynom'] ?? null,
            'barcode' => $item['barcode'] ?? null,
            'saldo' => $item['saldo'] ?? null,
            'semaforo' => $item['semaforo'] ?? null,
            'ult_impago' => $item['ult_impago'] ?? null,
            'acceso_full' => $item['acceso_full'] ?? null,
            'hab_controles' => $habControls,
            'hab_controles_raw' => $habControlsRawJson,
            'raw' => $raw,
        ];
    }

    /**
     * Normalizar hab_controles: null o [] => 0, string/int => int, otro => 0
     */
    protected function normalizeHabControles($value, ?string $dni, ?string $sid): int
    {
        // Si es null o array vacío => 0
        if ($value === null || (is_array($value) && empty($value))) {
            if ($value === null || empty($value)) {
                logger()->warning('PadronSync: hab_controles normalized to 0 (null or empty array)', [
                    'original_type' => gettype($value),
                    'dni' => $dni,
                    'sid' => $sid,
                ]);
            }
            return 0;
        }

        // Si es array no vacío o object => 0
        if (is_array($value) || is_object($value)) {
            logger()->warning('PadronSync: hab_controles normalized to 0 (non-empty array/object)', [
                'original_type' => gettype($value),
                'dni' => $dni,
                'sid' => $sid,
            ]);
            return 0;
        }

        // Si es string o number => castealo a int
        if (is_string($value) || is_numeric($value)) {
            return (int) $value;
        }

        // Otro tipo raro => 0
        logger()->warning('PadronSync: hab_controles normalized to 0 (unknown type)', [
            'original_type' => gettype($value),
            'original_value' => $value,
            'dni' => $dni,
            'sid' => $sid,
        ]);
        return 0;
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
