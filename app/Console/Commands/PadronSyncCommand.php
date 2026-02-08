<?php

namespace App\Console\Commands;

use App\Models\SocioPadron;
use App\Models\SyncState;
use App\Services\VmServerPadronClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class PadronSyncCommand extends Command
{
    protected $signature = 'padron:sync {--since=} {--per-page=500} {--all}';
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
            $perPage = (int) $this->option('per-page');

            // ✅ --all para traer TODO (sin updated_since)
            $forceAll = (bool) $this->option('all');
            if ($forceAll) {
                $since = null;
                $this->line("[SYNC] --all usado: NO se enviará updated_since (traer todo)");
            } else {
                $since = $this->determineSince();
                $this->line("[SYNC] since RAW: {$since}");

                if (!empty($since)) {
                    $since = Carbon::parse($since)->utc()->format('Y-m-d\TH:i:s') . 'Z';
                    $this->line("[SYNC] since NORMALIZADO: {$since}");
                } else {
                    $since = null;
                    $this->line("[SYNC] since is empty: updated_since will NOT be sent");
                }
            }

            $this->info("🔄 Iniciando sincronización de socios desde vmServer");
            $this->info("  • Desde: " . ($since ?? 'N/A (no filter)'));
            $this->info("  • Por página (pedido): {$perPage}");
            $this->newLine();

            $page = 1;
            $totalUpserted = 0;
            $totalProcessed = 0;

            // ✅ NUEVO: control de loop robusto
            $shouldContinue = true;

            // para guardar el último server_time al final
            $lastServerTime = null;

            while ($shouldContinue) {
                $this->info("📄 Obteniendo página {$page}...");

                $params = [
                    'page' => $page,
                    'per_page' => $perPage,
                ];
                if (!empty($since)) {
                    $params['updated_since'] = $since;
                }

                logger()->info('[SYNC] Fetching page', [
                    'page' => $page,
                    'since' => $since,
                    'per_page' => $perPage,
                    'force_all' => $forceAll,
                ]);

                $response = $this->client->fetchSocios($params);

                $data = is_array($response) ? ($response['data'] ?? null) : null;
                $pagination = is_array($response) ? ($response['pagination'] ?? []) : [];
                $serverTime = is_array($response) ? ($response['server_time'] ?? null) : null;
                $lastServerTime = $serverTime ?? $lastServerTime;

                $dataCount = is_array($data) ? count($data) : 0;

                $this->line(
                    "[SYNC] data_count={$dataCount} current_page=" . ($pagination['current_page'] ?? '?') .
                    " last_page=" . ($pagination['last_page'] ?? '?')
                );

                // Warn si el server fuerza otro per_page (ej 50)
                $serverPerPage = (int) ($pagination['per_page'] ?? $perPage);
                if (!empty($pagination) && isset($pagination['per_page']) && (int)$pagination['per_page'] !== (int)$perPage) {
                    $this->warn("[SYNC] ⚠️ vmServer está usando per_page={$pagination['per_page']} (vos pediste {$perPage})");
                }

                logger()->info('PadronSync: fetchSocios response summary', [
                    'page' => $page,
                    'data_count' => $dataCount,
                    'pagination' => $pagination,
                    'server_time' => $serverTime,
                ]);

                // Si no hay data, cortar
                if (!is_array($data) || empty($data)) {
                    $this->line("[SYNC] data is empty or not array on page {$page} -> STOP");
                    logger()->warning('PadronSync: data empty or invalid', [
                        'page' => $page,
                        'data_type' => gettype($data),
                        'data_count' => $dataCount,
                    ]);
                    break;
                }

                // Map
                $rows = [];
                foreach ($data as $item) {
                    if (!is_array($item)) continue;
                    $rows[] = $this->mapItemToRow($item);
                }

                $totalProcessed += count($rows);
                $this->upsertSocios($rows);
                $totalUpserted += count($rows);

                /**
                 * ✅ DECISIÓN DE PAGINADO:
                 * - Si viene last_page, usamos eso.
                 * - Si NO viene, fallback: si dataCount == per_page, asumimos que hay más páginas.
                 */
                $hasLastPage = is_array($pagination) && array_key_exists('last_page', $pagination) && $pagination['last_page'] !== null;

                if ($hasLastPage) {
                    $currentPage = (int) ($pagination['current_page'] ?? $page);
                    $lastPage = (int) ($pagination['last_page'] ?? $page);
                    $shouldContinue = $currentPage < $lastPage;

                    $this->line("[SYNC] pagination_mode=last_page current={$currentPage} last={$lastPage} continue=" . ($shouldContinue ? 'yes' : 'no'));
                } else {
                    // fallback por conteo
                    $shouldContinue = ($dataCount === $serverPerPage);
                    $this->line("[SYNC] pagination_mode=count_fallback per_page_used={$serverPerPage} continue=" . ($shouldContinue ? 'yes' : 'no'));
                }

                $page++;
            }

            // Guardar last sync
            $syncTime = $lastServerTime ?? now('UTC')->format('Y-m-d\TH:i:s') . 'Z';
            $syncTime = Carbon::parse($syncTime)->utc()->format('Y-m-d\TH:i:s') . 'Z';
            SyncState::setValue('padron_last_sync_at', $syncTime);

            $this->newLine();
            $this->info("✅ Sincronización completada");
            $this->info("  • Total procesados: {$totalProcessed}");
            $this->info("  • Total upsertados: {$totalUpserted}");
            $this->info("  • Último sync: {$syncTime}");

            logger()->info('[SYNC] Sync completed', [
                'total_processed' => $totalProcessed,
                'total_upserted' => $totalUpserted,
                'last_sync_at' => $syncTime,
                'force_all' => $forceAll,
            ]);

            return 0;
        } catch (\Throwable $e) {
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

    protected function determineSince(): string
    {
        if ($this->option('since')) {
            $cliSince = $this->option('since');
            $this->line("[LOG] --since de CLI: {$cliSince}");
            return $cliSince;
        }

        $lastSync = SyncState::getValue('padron_last_sync_at');
        if ($lastSync) {
            $this->line("[LOG] last sync de SyncState: {$lastSync}");
            return $lastSync;
        }

        $default = now()->subDay()->toIso8601String();
        $this->line("[LOG] usando default (24h atrás): {$default}");
        return $default;
    }

    protected function upsertSocios(array $rows): void
    {
        if (empty($rows)) return;

        // Detector antes de persistir: encuentra el primer valor no escalar
        foreach ($rows as $i => $row) {
            if (!is_array($row)) continue;
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

                    throw new \RuntimeException("Non-scalar: {$k} row {$i} dni=" . ($row['dni'] ?? 'null') . " sid=" . ($row['sid'] ?? 'null'));
                }
            }
        }

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

    protected function mapItemToRow(array $item): array
    {
        $raw = $item['raw'] ?? $item;
        if (is_array($raw) || is_object($raw)) {
            $raw = json_encode($raw, JSON_UNESCAPED_UNICODE);
        }

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

    protected function normalizeHabControles($value, ?string $dni, ?string $sid): int
    {
        if ($value === null || (is_array($value) && empty($value))) {
            logger()->warning('PadronSync: hab_controles normalized to 0 (null or empty array)', [
                'original_type' => gettype($value),
                'dni' => $dni,
                'sid' => $sid,
            ]);
            return 0;
        }

        if (is_array($value) || is_object($value)) {
            logger()->warning('PadronSync: hab_controles normalized to 0 (array/object)', [
                'original_type' => gettype($value),
                'dni' => $dni,
                'sid' => $sid,
            ]);
            return 0;
        }

        if (is_string($value) || is_numeric($value)) {
            return (int) $value;
        }

        logger()->warning('PadronSync: hab_controles normalized to 0 (unknown type)', [
            'original_type' => gettype($value),
            'original_value' => $value,
            'dni' => $dni,
            'sid' => $sid,
        ]);
        return 0;
    }

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
