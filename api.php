<?php
/**
 * FríoSeguro — API Backend
 * PHP 8.3 · SQLite · OOP · Batch Processing · Thermal Degradation Algorithm
 *
 * Endpoints:
 *   GET  /api/dashboard        → stats del sistema
 *   GET  /api/alertas          → alertas recientes
 *   GET  /api/viaje/{id}       → telemetría de un viaje
 *   POST /api/telemetria       → registro individual
 *   POST /api/telemetria/batch → sincronización batch (Offline → Online)
 *   POST /api/viaje            → crear viaje
 *
 * Uso rápido (PHP built-in server):
 *   php -S localhost:8080 -t .
 */

declare(strict_types=1);

// ── CORS & Headers ─────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
//  DATABASE
// ══════════════════════════════════════════════════════════════════════════════
final class Database
{
    private static ?PDO $pdo = null;

    public static function get(): PDO
    {
        if (self::$pdo === null) {
            $path = __DIR__ . '/frioseguro.db';
            self::$pdo = new PDO("sqlite:{$path}");
            self::$pdo->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
            self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            self::$pdo->exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;');
            self::migrate(self::$pdo);
        }
        return self::$pdo;
    }

    private static function migrate(PDO $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS viajes (
                id            TEXT    PRIMARY KEY,
                vehiculo_id   TEXT    NOT NULL,
                medicamento   TEXT    NOT NULL,
                origen        TEXT    NOT NULL,
                destino       TEXT    NOT NULL,
                temp_min      REAL    NOT NULL DEFAULT 2.0,
                temp_max      REAL    NOT NULL DEFAULT 8.0,
                estado        TEXT    NOT NULL DEFAULT 'activo'
                                      CHECK(estado IN ('activo','completado','sin_senal','alerta')),
                created_at    INTEGER NOT NULL DEFAULT (unixepoch())
            );

            CREATE TABLE IF NOT EXISTS telemetria_serial (
                id                     INTEGER PRIMARY KEY AUTOINCREMENT,
                viaje_id               TEXT    NOT NULL REFERENCES viajes(id),
                temperatura_actual     REAL    NOT NULL,
                sensor_puerta          INTEGER NOT NULL DEFAULT 0,  -- 0=cerrada 1=abierta
                latitud_actual         REAL    NOT NULL,
                longitud_actual        REAL    NOT NULL,
                timestamp_lectura_real INTEGER NOT NULL,             -- reloj del microcontrolador
                sincronizado_nube      INTEGER NOT NULL DEFAULT 0,   -- BOOLEAN
                lote_comprometido      INTEGER NOT NULL DEFAULT 0,   -- BOOLEAN
                created_at             INTEGER NOT NULL DEFAULT (unixepoch())
            );

            CREATE INDEX IF NOT EXISTS idx_tel_viaje
                ON telemetria_serial(viaje_id, timestamp_lectura_real);

            CREATE TABLE IF NOT EXISTS alertas (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                viaje_id    TEXT    NOT NULL REFERENCES viajes(id),
                tipo        TEXT    NOT NULL
                              CHECK(tipo IN ('temperatura_critica','temperatura_riesgo',
                                             'puerta_abierta','perdida_senal','sync_completado')),
                descripcion TEXT    NOT NULL,
                latitud     REAL,
                longitud    REAL,
                valor       REAL,
                resuelta    INTEGER NOT NULL DEFAULT 0,
                timestamp   INTEGER NOT NULL DEFAULT (unixepoch())
            );
        ");
    }
}

// ══════════════════════════════════════════════════════════════════════════════
//  THERMAL DEGRADATION ALGORITHM
// ══════════════════════════════════════════════════════════════════════════════
final class ThermalAlgorithm
{
    /**
     * Evalúa cronológicamente el historial de telemetría de un viaje
     * y determina si el lote fue comprometido por choque térmico.
     *
     * @param  array $records     Array de filas de telemetria_serial
     * @param  float $tempMin     Umbral mínimo (ej. 2.0 °C)
     * @param  float $tempMax     Umbral máximo (ej. 8.0 °C)
     * @return array{
     *   comprometido: bool,
     *   temp_maxima: float,
     *   temp_minima: float,
     *   minutos_fuera_rango: int,
     *   alertas: array
     * }
     */
    public static function evaluate(
        string $viajeId,
        array  $records,
        float  $tempMin,
        float  $tempMax
    ): array {
        if (empty($records)) {
            return [
                'comprometido'        => false,
                'temp_maxima'         => 0.0,
                'temp_minima'         => 0.0,
                'minutos_fuera_rango' => 0,
                'alertas'             => [],
            ];
        }

        // Ordenar por timestamp del microcontrolador (no del servidor)
        usort($records, static fn($a, $b) =>
            $a['timestamp_lectura_real'] <=> $b['timestamp_lectura_real']
        );

        $maxTemp         = PHP_FLOAT_MIN;
        $minTemp         = PHP_FLOAT_MAX;
        $comprometido    = false;
        $alertas         = [];
        $fueraDeRango    = 0;  // segundos acumulados
        $prevTs          = null;

        foreach ($records as $r) {
            $temp = (float) $r['temperatura_actual'];
            $ts   = (int)   $r['timestamp_lectura_real'];

            if ($temp > $maxTemp) $maxTemp = $temp;
            if ($temp < $minTemp) $minTemp = $temp;

            // Acumular tiempo fuera de rango
            if ($prevTs !== null) {
                $delta = $ts - $prevTs;          // segundos entre lecturas
                if ($temp > $tempMax || $temp < $tempMin) {
                    $fueraDeRango += $delta;
                }
            }
            $prevTs = $ts;

            // Generar alerta por temperatura crítica (> max)
            if ($temp > $tempMax) {
                $comprometido = true;
                $alertas[] = [
                    'viaje_id'    => $viajeId,
                    'tipo'        => 'temperatura_critica',
                    'descripcion' => sprintf('Temp %.1f°C superó límite máximo %.1f°C', $temp, $tempMax),
                    'latitud'     => $r['latitud_actual'],
                    'longitud'    => $r['longitud_actual'],
                    'valor'       => $temp,
                    'timestamp'   => $ts,
                ];
            }
            // Alerta de riesgo (zona de advertencia: entre max-1.5 y max)
            elseif ($temp > ($tempMax - 1.5)) {
                $alertas[] = [
                    'viaje_id'    => $viajeId,
                    'tipo'        => 'temperatura_riesgo',
                    'descripcion' => sprintf('Temp %.1f°C cerca del límite (%.1f°C)', $temp, $tempMax),
                    'latitud'     => $r['latitud_actual'],
                    'longitud'    => $r['longitud_actual'],
                    'valor'       => $temp,
                    'timestamp'   => $ts,
                ];
            }
            // Alerta por puerta abierta
            if ((int) $r['sensor_puerta'] === 1) {
                $alertas[] = [
                    'viaje_id'    => $viajeId,
                    'tipo'        => 'puerta_abierta',
                    'descripcion' => 'Puerta de refrigeración abierta durante el trayecto',
                    'latitud'     => $r['latitud_actual'],
                    'longitud'    => $r['longitud_actual'],
                    'valor'       => $temp,
                    'timestamp'   => $ts,
                ];
            }
        }

        // Deduplicar alertas consecutivas del mismo tipo (ventana de 5 min)
        $alertas = self::deduplicateAlerts($alertas);

        return [
            'comprometido'        => $comprometido,
            'temp_maxima'         => round($maxTemp, 2),
            'temp_minima'         => round($minTemp, 2),
            'minutos_fuera_rango' => (int) round($fueraDeRango / 60),
            'alertas'             => $alertas,
        ];
    }

    /** Elimina alertas repetidas del mismo tipo en ventana de 5 minutos */
    private static function deduplicateAlerts(array $alertas): array
    {
        $result   = [];
        $lastByTp = [];

        foreach ($alertas as $a) {
            $tipo = $a['tipo'];
            $ts   = $a['timestamp'];

            if (!isset($lastByTp[$tipo]) || ($ts - $lastByTp[$tipo]) > 300) {
                $result[]        = $a;
                $lastByTp[$tipo] = $ts;
            }
        }
        return $result;
    }
}

// ══════════════════════════════════════════════════════════════════════════════
//  REPOSITORIES
// ══════════════════════════════════════════════════════════════════════════════
final class ViajeRepo
{
    private PDO $db;

    public function __construct() { $this->db = Database::get(); }

    public function create(array $data): string
    {
        $id = $data['id'] ?? ('VJ-' . date('Y') . '-' . str_pad((string)rand(1,999), 3, '0', STR_PAD_LEFT));
        $stmt = $this->db->prepare("
            INSERT OR IGNORE INTO viajes (id, vehiculo_id, medicamento, origen, destino, temp_min, temp_max)
            VALUES (:id, :vid, :med, :ori, :dest, :tmin, :tmax)
        ");
        $stmt->execute([
            ':id'   => $id,
            ':vid'  => $data['vehiculo_id']  ?? 'V-001',
            ':med'  => $data['medicamento']  ?? 'Desconocido',
            ':ori'  => $data['origen']        ?? 'Central Toluca',
            ':dest' => $data['destino']       ?? 'Destino',
            ':tmin' => (float) ($data['temp_min'] ?? 2.0),
            ':tmax' => (float) ($data['temp_max'] ?? 8.0),
        ]);
        return $id;
    }

    public function find(string $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM viajes WHERE id = ?");
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        return $r ?: null;
    }

    public function updateEstado(string $id, string $estado): void
    {
        $this->db->prepare("UPDATE viajes SET estado = ? WHERE id = ?")
                 ->execute([$estado, $id]);
    }
}

final class TelemetriaRepo
{
    private PDO $db;

    public function __construct() { $this->db = Database::get(); }

    /** Inserta un registro individual (tiempo real) */
    public function insert(array $d): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO telemetria_serial
                (viaje_id, temperatura_actual, sensor_puerta,
                 latitud_actual, longitud_actual, timestamp_lectura_real, sincronizado_nube)
            VALUES (?, ?, ?, ?, ?, ?, 1)
        ");
        $stmt->execute([
            $d['viaje_id'],
            (float) $d['temperatura_actual'],
            (int)   ($d['sensor_puerta'] ?? 0),
            (float) $d['latitud_actual'],
            (float) $d['longitud_actual'],
            (int)   ($d['timestamp_lectura_real'] ?? time()),
        ]);
        return (int) $this->db->lastInsertId();
    }

    /** Inserta múltiples registros en una transacción */
    public function insertBatch(array $records, bool $comprometido): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO telemetria_serial
                (viaje_id, temperatura_actual, sensor_puerta,
                 latitud_actual, longitud_actual, timestamp_lectura_real,
                 sincronizado_nube, lote_comprometido)
            VALUES (?, ?, ?, ?, ?, ?, 1, ?)
        ");
        $inserted = 0;
        foreach ($records as $d) {
            $stmt->execute([
                $d['viaje_id'],
                (float) $d['temperatura_actual'],
                (int)   ($d['sensor_puerta'] ?? 0),
                (float) $d['latitud_actual'],
                (float) $d['longitud_actual'],
                (int)   $d['timestamp_lectura_real'],
                $comprometido ? 1 : 0,
            ]);
            $inserted++;
        }
        return $inserted;
    }

    public function getByViaje(string $viajeId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM telemetria_serial
            WHERE viaje_id = ?
            ORDER BY timestamp_lectura_real ASC
        ");
        $stmt->execute([$viajeId]);
        return $stmt->fetchAll();
    }
}

final class AlertaRepo
{
    private PDO $db;

    public function __construct() { $this->db = Database::get(); }

    public function insertBulk(array $alertas): void
    {
        if (empty($alertas)) return;
        $stmt = $this->db->prepare("
            INSERT INTO alertas (viaje_id, tipo, descripcion, latitud, longitud, valor, timestamp)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($alertas as $a) {
            $stmt->execute([
                $a['viaje_id'],
                $a['tipo'],
                $a['descripcion'],
                $a['latitud']   ?? null,
                $a['longitud']  ?? null,
                $a['valor']     ?? null,
                $a['timestamp'] ?? time(),
            ]);
        }
    }

    public function getRecent(int $limit = 15): array
    {
        $stmt = $this->db->prepare("
            SELECT a.*, v.vehiculo_id, v.medicamento
            FROM   alertas a
            LEFT   JOIN viajes v ON a.viaje_id = v.id
            WHERE  a.resuelta = 0
            ORDER  BY a.timestamp DESC
            LIMIT  ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }
}

// ══════════════════════════════════════════════════════════════════════════════
//  CONTROLLERS
// ══════════════════════════════════════════════════════════════════════════════

/** POST /api/telemetria — registro individual en tiempo real */
function ctrlTelemetriaStore(): array
{
    $data = jsonBody();
    required($data, ['viaje_id', 'temperatura_actual', 'latitud_actual', 'longitud_actual']);

    $repo = new TelemetriaRepo();
    $id   = $repo->insert($data);

    return ['ok' => true, 'id' => $id];
}

/**
 * POST /api/telemetria/batch — sincronización masiva Offline → Online
 * Reconstruye el viaje cronológicamente y ejecuta el algoritmo de degradación.
 */
function ctrlTelemetriaBatch(): array
{
    $body    = jsonBody();
    $records = $body['records'] ?? $body;

    if (empty($records) || !is_array($records)) {
        http_response_code(400);
        return ['error' => 'Se esperaba array de registros'];
    }

    // Ordenar por timestamp real del microcontrolador
    usort($records, static fn($a, $b) =>
        ($a['timestamp_lectura_real'] ?? 0) <=> ($b['timestamp_lectura_real'] ?? 0)
    );

    $viajeId = $records[0]['viaje_id'] ?? null;
    if (!$viajeId) {
        http_response_code(400);
        return ['error' => 'viaje_id requerido'];
    }

    $db        = Database::get();
    $viajeRepo = new ViajeRepo();
    $viaje     = $viajeRepo->find($viajeId);

    // Auto-crear viaje si no existe
    if ($viaje === null) {
        $viajeRepo->create(['id' => $viajeId]);
        $viaje = $viajeRepo->find($viajeId);
    }

    $tempMin = (float) ($viaje['temp_min'] ?? 2.0);
    $tempMax = (float) ($viaje['temp_max'] ?? 8.0);

    // Ejecutar algoritmo de degradación térmica
    $analysis = ThermalAlgorithm::evaluate($viajeId, $records, $tempMin, $tempMax);

    $db->beginTransaction();
    try {
        // Insertar telemetría con flag de lote comprometido
        $telRepo = new TelemetriaRepo();
        $stored  = $telRepo->insertBatch($records, $analysis['comprometido']);

        // Guardar alertas detectadas
        $alertRepo = new AlertaRepo();
        $alertRepo->insertBulk($analysis['alertas']);

        // Registrar evento de sync completado
        $alertRepo->insertBulk([[
            'viaje_id'    => $viajeId,
            'tipo'        => 'sync_completado',
            'descripcion' => sprintf('%d registros sincronizados desde zona sin señal', $stored),
            'latitud'     => null,
            'longitud'    => null,
            'valor'       => (float) $stored,
            'timestamp'   => time(),
        ]]);

        // Actualizar estado del viaje
        $nuevoEstado = $analysis['comprometido'] ? 'alerta' : 'activo';
        $viajeRepo->updateEstado($viajeId, $nuevoEstado);

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    return [
        'ok'       => true,
        'stored'   => $stored,
        'analysis' => [
            'comprometido'        => $analysis['comprometido'],
            'temp_maxima'         => $analysis['temp_maxima'],
            'temp_minima'         => $analysis['temp_minima'],
            'minutos_fuera_rango' => $analysis['minutos_fuera_rango'],
            'alertas_generadas'   => count($analysis['alertas']),
            'detalle_alertas'     => $analysis['alertas'],
        ],
    ];
}

/** GET /api/viaje/{id} — telemetría completa de un viaje */
function ctrlViajeGet(string $id): array
{
    $viaje     = (new ViajeRepo())->find($id);
    $telemetria = (new TelemetriaRepo())->getByViaje($id);

    if ($viaje === null) {
        http_response_code(404);
        return ['error' => 'Viaje no encontrado'];
    }

    return [
        'viaje'      => $viaje,
        'telemetria' => $telemetria,
        'total'      => count($telemetria),
    ];
}

/** POST /api/viaje — crear viaje */
function ctrlViajeCreate(): array
{
    $data = jsonBody();
    $id   = (new ViajeRepo())->create($data);
    return ['ok' => true, 'id' => $id];
}

/** GET /api/alertas — alertas sin resolver */
function ctrlAlertas(): array
{
    return ['alertas' => (new AlertaRepo())->getRecent()];
}

/** GET /api/dashboard — resumen del sistema */
function ctrlDashboard(): array
{
    $db  = Database::get();
    $row = static fn(string $sql, array $p = []) => $db->prepare($sql)->execute($p) ? null : null;

    $q = static function(string $sql, array $params = []) use ($db): mixed {
        $s = $db->prepare($sql);
        $s->execute($params);
        return $s->fetch();
    };

    $viajesActivos    = (int) ($q("SELECT COUNT(*) c FROM viajes WHERE estado IN ('activo','alerta')")['c'] ?? 0);
    $lotesTransito    = (int) ($q("SELECT COUNT(*) c FROM viajes WHERE estado = 'activo'")['c'] ?? 0);
    $vehiculosSinSen  = (int) ($q("SELECT COUNT(*) c FROM viajes WHERE estado = 'sin_senal'")['c'] ?? 0);
    $alertasCrit      = (int) ($q("SELECT COUNT(*) c FROM alertas WHERE tipo='temperatura_critica' AND resuelta=0")['c'] ?? 0);
    $tempMaxRow       = $q("SELECT MAX(temperatura_actual) m FROM telemetria_serial WHERE created_at > (unixepoch() - 86400)");
    $tempMax          = round((float) ($tempMaxRow['m'] ?? 0.0), 1);

    return [
        'viajes_activos'       => $viajesActivos,
        'lotes_transito'       => $lotesTransito,
        'vehiculos_sin_senal'  => $vehiculosSinSen,
        'alertas_criticas'     => $alertasCrit,
        'temp_maxima_24h'      => $tempMax,
        'server_time'          => date('Y-m-d H:i:s'),
    ];
}

// ══════════════════════════════════════════════════════════════════════════════
//  HELPERS
// ══════════════════════════════════════════════════════════════════════════════
function jsonBody(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['error' => 'JSON inválido: ' . json_last_error_msg()]);
        exit;
    }
    return $data ?? [];
}

function required(array $data, array $fields): void
{
    foreach ($fields as $f) {
        if (!isset($data[$f])) {
            http_response_code(422);
            echo json_encode(['error' => "Campo requerido: {$f}"]);
            exit;
        }
    }
}

function respond(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
//  ROUTER
// ══════════════════════════════════════════════════════════════════════════════
$method = $_SERVER['REQUEST_METHOD'];
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// Normaliza prefijo /api
$uri    = preg_replace('#^/api#', '', $uri);
$uri    = rtrim($uri, '/') ?: '/';

try {
    $result = match(true) {
        $method === 'POST' && $uri === '/telemetria'        => ctrlTelemetriaStore(),
        $method === 'POST' && $uri === '/telemetria/batch'  => ctrlTelemetriaBatch(),
        $method === 'GET'  && $uri === '/dashboard'         => ctrlDashboard(),
        $method === 'GET'  && $uri === '/alertas'           => ctrlAlertas(),
        $method === 'POST' && $uri === '/viaje'             => ctrlViajeCreate(),
        $method === 'GET'  && str_starts_with($uri, '/viaje/') => ctrlViajeGet(basename($uri)),
        default => (function() {
            http_response_code(404);
            return ['error' => 'Endpoint no encontrado', 'uri' => $_SERVER['REQUEST_URI']];
        })()
    };

    respond($result);

} catch (\Throwable $e) {
    respond([
        'error'   => $e->getMessage(),
        'file'    => basename($e->getFile()),
        'line'    => $e->getLine(),
    ], 500);
}
