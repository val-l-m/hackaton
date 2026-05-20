<?php
/**
 * ============================================================================
 * FríoSeguro API Backend
 * PHP 8.3 + SQLite + OOP
 * ============================================================================
 */

declare(strict_types=1);

/* ============================================================================
 | HEADERS
 ============================================================================ */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/* ============================================================================
 | DATABASE
 ============================================================================ */

final class Database
{
    private static ?PDO $pdo = null;

    public static function get(): PDO
    {
        if (self::$pdo === null) {

            $path = __DIR__ . '/frioseguro.db';

            self::$pdo = new PDO("sqlite:$path");

            self::$pdo->setAttribute(
                PDO::ATTR_ERRMODE,
                PDO::ERRMODE_EXCEPTION
            );

            self::$pdo->setAttribute(
                PDO::ATTR_DEFAULT_FETCH_MODE,
                PDO::FETCH_ASSOC
            );

            self::$pdo->exec("
                PRAGMA foreign_keys = ON;
                PRAGMA journal_mode = WAL;
            ");

            self::migrate(self::$pdo);
        }

        return self::$pdo;
    }

    private static function migrate(PDO $db): void
    {
        $db->exec("

        CREATE TABLE IF NOT EXISTS viajes (

            id TEXT PRIMARY KEY,

            vehiculo_id TEXT NOT NULL,

            medicamento TEXT NOT NULL,

            origen TEXT NOT NULL,

            destino TEXT NOT NULL,

            temp_min REAL DEFAULT 2.0,

            temp_max REAL DEFAULT 8.0,

            estado TEXT DEFAULT 'activo',

            created_at INTEGER DEFAULT (unixepoch())
        );

        CREATE TABLE IF NOT EXISTS telemetria_serial (

            id INTEGER PRIMARY KEY AUTOINCREMENT,

            viaje_id TEXT NOT NULL,

            temperatura_actual REAL NOT NULL,

            sensor_puerta INTEGER DEFAULT 0,

            latitud_actual REAL NOT NULL,

            longitud_actual REAL NOT NULL,

            timestamp_lectura_real INTEGER NOT NULL,

            sincronizado_nube INTEGER DEFAULT 1,

            lote_comprometido INTEGER DEFAULT 0,

            created_at INTEGER DEFAULT (unixepoch()),

            FOREIGN KEY(viaje_id) REFERENCES viajes(id)
        );

        CREATE TABLE IF NOT EXISTS alertas (

            id INTEGER PRIMARY KEY AUTOINCREMENT,

            viaje_id TEXT NOT NULL,

            tipo TEXT NOT NULL,

            descripcion TEXT NOT NULL,

            latitud REAL,

            longitud REAL,

            valor REAL,

            resuelta INTEGER DEFAULT 0,

            timestamp INTEGER DEFAULT (unixepoch()),

            FOREIGN KEY(viaje_id) REFERENCES viajes(id)
        );

        CREATE TABLE IF NOT EXISTS usuarios (

            id INTEGER PRIMARY KEY AUTOINCREMENT,

            nombre TEXT NOT NULL,

            email TEXT UNIQUE NOT NULL,

            password_hash TEXT NOT NULL,

            telefono TEXT,

            rol TEXT DEFAULT 'operador',

            estado TEXT DEFAULT 'activo',

            created_at INTEGER DEFAULT (unixepoch())
        );

        CREATE TABLE IF NOT EXISTS vehiculos (

            id TEXT PRIMARY KEY,

            placas TEXT UNIQUE NOT NULL,

            modelo TEXT NOT NULL,

            marca TEXT NOT NULL,

            anio INTEGER,

            conductor TEXT,

            capacidad REAL,

            estado TEXT DEFAULT 'activo',

            created_at INTEGER DEFAULT (unixepoch())
        );

            CREATE TABLE IF NOT EXISTS analisis_ia_riesgo (
                id                          INTEGER PRIMARY KEY AUTOINCREMENT,
                id_viaje                    TEXT,
                vehiculo                    TEXT,
                medicamento                 TEXT,
                modelo_ollama               TEXT,
                origen                      TEXT    NOT NULL DEFAULT 'ollama',
                nivel_riesgo                TEXT,
                titulo_alerta               TEXT,
                mensaje_dashboard           TEXT,
                resumen_operador            TEXT,
                explicacion_tecnica         TEXT,
                prediccion_estado           TEXT,
                prediccion_tiempo           TEXT,
                prediccion_motivo           TEXT,
                posibles_causas             TEXT,
                acciones_recomendadas       TEXT,
                resumen_auditoria           TEXT,
                confianza                   TEXT,
                requiere_revision           INTEGER NOT NULL DEFAULT 1,
                indice_danio_termico        REAL    NOT NULL DEFAULT 0,
                temperatura_actual          REAL    NOT NULL DEFAULT 0,
                temperatura_maxima          REAL    NOT NULL DEFAULT 0,
                tiempo_fuera_de_rango_minutos INTEGER NOT NULL DEFAULT 0,
                datos_entrada               TEXT,
                respuesta_completa          TEXT,
                creado_en                   INTEGER NOT NULL DEFAULT (unixepoch())
            );

            CREATE INDEX IF NOT EXISTS idx_ia_viaje
                ON analisis_ia_riesgo(id_viaje, creado_en);

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

            CREATE TABLE IF NOT EXISTS configuracion (
                clave          TEXT PRIMARY KEY,
                valor          TEXT,
                actualizado_en INTEGER DEFAULT (unixepoch())
            );

            CREATE TABLE IF NOT EXISTS medicamentos (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                nombre      TEXT    NOT NULL UNIQUE,
                descripcion TEXT,
                temp_min    REAL    NOT NULL DEFAULT 2.0,
                temp_max    REAL    NOT NULL DEFAULT 8.0,
                activo      INTEGER NOT NULL DEFAULT 1,
                created_at  INTEGER NOT NULL DEFAULT (unixepoch())
            );
        ");

        // Seed de medicamentos comunes si la tabla está vacía
        $count = Database::get()->query("SELECT COUNT(*) FROM medicamentos")->fetchColumn();
        if ((int)$count === 0) {
            $seed = [
                ['Insulina',                  'Hormona para control de glucemia',                   2.0,  8.0],
                ['Vacunas (general)',          'Vacunas biológicas de uso general',                 2.0,  8.0],
                ['Plasma sanguíneo',           'Hemoderivado para transfusiones',                   1.0,  6.0],
                ['Oncológicos (quimio)',        'Medicamentos para tratamiento de cáncer',           2.0,  8.0],
                ['Antibióticos líquidos',      'Suspensiones y soluciones antibióticas',            2.0,  8.0],
                ['Eritropoyetina (EPO)',        'Factor estimulante de eritropoyesis',               2.0,  8.0],
                ['Hormona de crecimiento',     'Somatropina para déficit de GH',                    2.0,  8.0],
                ['Interferón',                 'Inmunomodulador biológico',                         2.0,  8.0],
                ['Trastuzumab (Herceptin)',    'Anticuerpo monoclonal onco-hematología',            2.0,  8.0],
                ['Rituximab',                  'Anticuerpo anti-CD20 para linfomas',                2.0,  8.0],
                ['Factores de coagulación',   'Concentrados FVIII / FIX para hemofilia',           2.0,  8.0],
                ['Inmunoglobulinas IV',        'Inmunoglobulinas para inmunodeficiencias',          2.0,  8.0],
                ['Semen/óvulos criogénicos',  'Material reproductivo en nitrógeno líquido',      -196.0, -150.0],
                ['Tejidos para trasplante',   'Hueso, piel y tendones preservados',               -80.0,  -18.0],
                ['Reagentes de laboratorio',  'Soluciones enzimáticas y kits diagnósticos',        2.0,  8.0],
            ];
            $stmt = Database::get()->prepare(
                "INSERT INTO medicamentos (nombre, descripcion, temp_min, temp_max) VALUES (?,?,?,?)"
            );
            foreach ($seed as $row) {
                $stmt->execute($row);
            }
        }
    }
}

/* ============================================================================
 | CONFIG REPO
 ============================================================================ */

final class ConfigRepo
{
    // Valores por defecto — cargados desde config.local.php si existe
    private static function defaults(): array
    {
        static $d = null;
        if ($d === null) {
            $d = ['sms_activo' => '1'];
            $f = __DIR__ . '/config.local.php';
            if (file_exists($f)) $d = array_merge($d, (array)(require $f));
        }
        return $d;
    }

    private PDO $db;
    public function __construct() { $this->db = Database::get(); }

    public function get(string $clave, string $default = ''): string
    {
        $s = $this->db->prepare("SELECT valor FROM configuracion WHERE clave = ?");
        $s->execute([$clave]);
        $r = $s->fetch();
        if ($r) return (string)$r['valor'];
        return self::defaults()[$clave] ?? $default;
    }

    public function set(string $clave, string $valor): void
    {
        $s = $this->db->prepare(
            "INSERT OR REPLACE INTO configuracion (clave, valor, actualizado_en) VALUES (?, ?, unixepoch())"
        );
        $s->execute([$clave, $valor]);
    }

    public function all(): array
    {
        return $this->db->query("SELECT clave, valor FROM configuracion")->fetchAll();
    }

    public function toMap(): array
    {
        $map = [];
        foreach ($this->all() as $r) $map[$r['clave']] = $r['valor'];
        return $map;
    }
}

/* ============================================================================
 | NOTIFICADOR  (Email SMTP + WhatsApp CallMeBot)
 ============================================================================ */

final class Notificador
{
    private ConfigRepo $cfg;
    public function __construct() { $this->cfg = new ConfigRepo(); }

    // Normaliza cualquier número mexicano a formato E.164 (+521XXXXXXXXXX)
    private function normalizarNumero(string $num): string
    {
        $n = preg_replace('/\D/', '', $num);
        // Quitar doble código: 5252... → 52...
        if (str_starts_with($n, '5252')) $n = substr($n, 2);
        // Agregar +52 si solo son 10 dígitos
        if (strlen($n) === 10) $n = '52' . $n;
        // Algunos números mexicanos móviles necesitan 521 (no solo 52)
        if (strlen($n) === 12 && str_starts_with($n, '52') && !str_starts_with($n, '521')) {
            $n = '521' . substr($n, 2);
        }
        return '+' . $n;
    }

    // ── SMS via Twilio (cURL — funciona en XAMPP) ────────────────────
    private function twilioSMS(string $to, string $body): array
    {
        $sid   = trim($this->cfg->get('twilio_sid'));
        $token = trim($this->cfg->get('twilio_token'));
        $from  = trim($this->cfg->get('twilio_sms_from'));

        if ($sid === '' || $token === '' || $from === '') {
            return ['ok' => false, 'error' => 'Configura el Account SID, Auth Token y numero Twilio primero.'];
        }

        $toNorm = $this->normalizarNumero($to);

        $url = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_USERPWD        => "$sid:$token",
            CURLOPT_POSTFIELDS     => http_build_query([
                'To'   => $toNorm,
                'From' => $from,
                'Body' => $body,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($res === false || $err !== '') {
            return ['ok' => false, 'error' => "Error de conexion: $err"];
        }

        $json = json_decode($res, true);
        if (isset($json['sid'])) {
            return ['ok' => true, 'to' => $toNorm];
        }

        $msg = $json['message'] ?? $json['detail'] ?? substr($res, 0, 300);
        return ['ok' => false, 'error' => "Twilio ($code): $msg"];
    }

    public function enviarSMS(string $mensaje): bool
    {
        $to = trim($this->cfg->get('alerta_sms'));
        if ($to === '') return false;
        return $this->twilioSMS($to, $mensaje)['ok'];
    }

    public function probarSMS(string $mensaje): array
    {
        $to = trim($this->cfg->get('alerta_sms'));
        if ($to === '') return ['ok' => false, 'error' => 'Ingresa un numero de celular en la configuracion.'];
        $r = $this->twilioSMS($to, $mensaje);
        if ($r['ok']) $r['mensaje'] = 'SMS enviado a ' . $r['to'] . '. Revisa tu telefono.';
        return $r;
    }

    public function alertar(string $tipo, string $descripcion, float $valor, string $viajeId, array $extra = []): void
    {
        if ($this->cfg->get('sms_activo', '1') !== '0') {
            $this->enviarSMS($this->formatoAlerta($tipo, $descripcion, $valor, $viajeId, $extra));
        }
    }

    private function formatoAlerta(string $tipo, string $desc, float $valor, string $viajeId, array $extra): string
    {
        $lineas = [
            "FRIOSEGURO - ALERTA",
            "Tipo: $tipo",
            "Detalle: $desc",
        ];
        if ($valor !== 0.0)                    $lineas[] = "Valor: {$valor}°C";
        if (!empty($extra['medicamento']))      $lineas[] = "Medicamento: {$extra['medicamento']}";
        if (!empty($extra['vehiculo']))         $lineas[] = "Vehiculo: {$extra['vehiculo']}";
        if (!empty($extra['origen']))           $lineas[] = "Ruta: {$extra['origen']} -> {$extra['destino']}";
        if (!empty($extra['lat']))              $lineas[] = "GPS: {$extra['lat']}, {$extra['lng']}";
        $lineas[] = "Viaje: $viajeId";
        $lineas[] = "Hora: " . date('d/m/Y H:i:s');
        return implode("\n", $lineas);
    }
}

/* ============================================================================
 | HELPERS
 ============================================================================ */

function jsonBody(): array
{
    $raw = file_get_contents('php://input');

    $data = json_decode($raw ?: '{}', true);

    if (json_last_error() !== JSON_ERROR_NONE) {

        respond([
            'error' => 'JSON inválido'
        ], 400);
    }

    return $data ?? [];
}

function respond(array $data, int $code = 200): never
{
    http_response_code($code);

    echo json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
    );

    exit;
}

function required(array $data, array $fields): void
{
    foreach ($fields as $field) {

        if (!isset($data[$field])) {

            respond([
                'error' => "Campo requerido: $field"
            ], 422);
        }
    }
}

/* ============================================================================
 | REPOSITORIES
 ============================================================================ */

final class ViajeRepo
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::get();
    }

    public function create(array $data): string
    {
        $id = $data['id']
            ?? ('VJ-' . time());

        $stmt = $this->db->prepare("

            INSERT INTO viajes (

                id,
                vehiculo_id,
                medicamento,
                origen,
                destino,
                temp_min,
                temp_max

            ) VALUES (?, ?, ?, ?, ?, ?, ?)

        ");

        $stmt->execute([

            $id,

            $data['vehiculo_id'] ?? 'SIN-VEHICULO',

            $data['medicamento'] ?? 'Sin medicamento',

            $data['origen'] ?? 'Origen',

            $data['destino'] ?? 'Destino',

            $data['temp_min'] ?? 2,

            $data['temp_max'] ?? 8
        ]);

        return $id;
    }

    public function find(string $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM viajes
            WHERE id = ?
        ");

        $stmt->execute([$id]);

        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function updateEstado(
        string $id,
        string $estado
    ): void {

        $stmt = $this->db->prepare("
            UPDATE viajes
            SET estado = ?
            WHERE id = ?
        ");

        $stmt->execute([
            $estado,
            $id
        ]);
    }
}

final class TelemetriaRepo
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::get();
    }

    public function insert(array $d): int
    {
        $stmt = $this->db->prepare("

            INSERT INTO telemetria_serial (

                viaje_id,
                temperatura_actual,
                sensor_puerta,
                latitud_actual,
                longitud_actual,
                timestamp_lectura_real,
                sincronizado_nube

            ) VALUES (?, ?, ?, ?, ?, ?, 1)

        ");

        $stmt->execute([

            $d['viaje_id'],

            $d['temperatura_actual'],

            $d['sensor_puerta'] ?? 0,

            $d['latitud_actual'],

            $d['longitud_actual'],

            $d['timestamp_lectura_real'] ?? time()
        ]);

        return (int)$this->db->lastInsertId();
    }

    public function getByViaje(string $viajeId): array
    {
        $stmt = $this->db->prepare("

            SELECT *
            FROM telemetria_serial
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

    public function __construct()
    {
        $this->db = Database::get();
    }

    public function insert(array $a): void
    {
        $stmt = $this->db->prepare("

            INSERT INTO alertas (

                viaje_id,
                tipo,
                descripcion,
                latitud,
                longitud,
                valor,
                timestamp

            ) VALUES (?, ?, ?, ?, ?, ?, ?)

        ");

        $stmt->execute([

            $a['viaje_id'],

            $a['tipo'],

            $a['descripcion'],

            $a['latitud'] ?? null,

            $a['longitud'] ?? null,

            $a['valor'] ?? null,

            $a['timestamp'] ?? time()
        ]);
    }

    public function recent(): array
    {
        return $this->db
            ->query("
                SELECT *
                FROM alertas
                ORDER BY timestamp DESC
                LIMIT 20
            ")
            ->fetchAll();
    }
}

final class UsuarioRepo
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::get();
    }

    public function all(): array
    {
        return $this->db
            ->query("
                SELECT
                    id,
                    nombre,
                    email,
                    telefono,
                    rol,
                    estado,
                    created_at
                FROM usuarios
                ORDER BY id DESC
            ")
            ->fetchAll();
    }

    public function create(array $d): int
    {
        $stmt = $this->db->prepare("

            INSERT INTO usuarios (

                nombre,
                email,
                password_hash,
                telefono,
                rol

            ) VALUES (?, ?, ?, ?, ?)

        ");

        $stmt->execute([

            $d['nombre'],

            $d['email'],

            password_hash(
                $d['password'],
                PASSWORD_BCRYPT
            ),

            $d['telefono'] ?? '',

            $d['rol'] ?? 'operador'
        ]);

        return (int)$this->db->lastInsertId();
    }

    public function update(
        int $id,
        array $d
    ): bool {

        $stmt = $this->db->prepare("

            UPDATE usuarios

            SET
                nombre = ?,
                email = ?,
                telefono = ?,
                rol = ?,
                estado = ?

            WHERE id = ?

        ");

        return $stmt->execute([

            $d['nombre'],

            $d['email'],

            $d['telefono'],

            $d['rol'],

            $d['estado'],

            $id
        ]);
    }

    public function delete(int $id): bool
    {
        return $this->db
            ->prepare("
                DELETE FROM usuarios
                WHERE id = ?
            ")
            ->execute([$id]);
    }
}

/* ============================================================================
 | MEDICAMENTO REPO
 ============================================================================ */

final class MedicamentoRepo
{
    private PDO $db;
    public function __construct() { $this->db = Database::get(); }

    public function all(): array
    {
        return $this->db
            ->query("SELECT * FROM medicamentos ORDER BY nombre ASC")
            ->fetchAll();
    }

    public function find(int $id): array|false
    {
        $stmt = $this->db->prepare("SELECT * FROM medicamentos WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public function create(array $d): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO medicamentos (nombre, descripcion, temp_min, temp_max, activo)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            trim($d['nombre']),
            trim($d['descripcion'] ?? ''),
            (float)$d['temp_min'],
            (float)$d['temp_max'],
            isset($d['activo']) ? (int)$d['activo'] : 1,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function update(int $id, array $d): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE medicamentos
             SET nombre = ?, descripcion = ?, temp_min = ?, temp_max = ?, activo = ?
             WHERE id = ?"
        );
        return $stmt->execute([
            trim($d['nombre']),
            trim($d['descripcion'] ?? ''),
            (float)$d['temp_min'],
            (float)$d['temp_max'],
            isset($d['activo']) ? (int)$d['activo'] : 1,
            $id,
        ]);
    }

    public function delete(int $id): bool
    {
        return $this->db
            ->prepare("DELETE FROM medicamentos WHERE id = ?")
            ->execute([$id]);
    }
}

/* ============================================================================
 | VEHICULO REPO
 ============================================================================ */

final class VehiculoRepo
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::get();
    }

    public function all(): array
    {
        return $this->db
            ->query("
                SELECT *
                FROM vehiculos
                ORDER BY created_at DESC
            ")
            ->fetchAll();
    }

    public function create(array $d): string
    {
        $id = uniqid('VH-');

        $stmt = $this->db->prepare("

            INSERT INTO vehiculos (

                id,
                placas,
                modelo,
                marca,
                anio,
                conductor,
                capacidad

            ) VALUES (?, ?, ?, ?, ?, ?, ?)

        ");

        $stmt->execute([

            $id,

            $d['placas'],

            $d['modelo'],

            $d['marca'],

            $d['anio'],

            $d['conductor'],

            $d['capacidad']
        ]);

        return $id;
    }

    public function update(
        string $id,
        array $d
    ): bool {

        $stmt = $this->db->prepare("

            UPDATE vehiculos

            SET
                placas = ?,
                modelo = ?,
                marca = ?,
                anio = ?,
                conductor = ?,
                capacidad = ?,
                estado = ?

            WHERE id = ?

        ");

        return $stmt->execute([

            $d['placas'],

            $d['modelo'],

            $d['marca'],

            $d['anio'],

            $d['conductor'],

            $d['capacidad'],

            $d['estado'],

            $id
        ]);
    }

    public function delete(string $id): bool
    {
        return $this->db
            ->prepare("
                DELETE FROM vehiculos
                WHERE id = ?
            ")
            ->execute([$id]);
    }
}

/* ============================================================================
 | CONTROLLERS
 ============================================================================ */

function ctrlDashboard(): array
{
    $db = Database::get();

    $viajes = $db->query("
        SELECT COUNT(*) c
        FROM viajes
    ")->fetch()['c'];

    $vehiculos = $db->query("
        SELECT COUNT(*) c
        FROM vehiculos
    ")->fetch()['c'];

    $usuarios = $db->query("
        SELECT COUNT(*) c
        FROM usuarios
    ")->fetch()['c'];

    $alertas = $db->query("
        SELECT COUNT(*) c
        FROM alertas
        WHERE resuelta = 0
    ")->fetch()['c'];

    return [

        'viajes' => (int)$viajes,

        'vehiculos' => (int)$vehiculos,

        'usuarios' => (int)$usuarios,

        'alertas' => (int)$alertas,

        'server_time' => date('Y-m-d H:i:s')
    ];
}

/** GET /api/viajes-activos */
function ctrlViajesActivos(): array
{
    $db  = Database::get();
    $now = time();

    $stmt = $db->prepare("
        SELECT
            v.id            AS viaje_id,
            v.vehiculo_id,
            v.medicamento,
            v.origen,
            v.destino,
            v.temp_min,
            v.temp_max,
            v.estado        AS viaje_estado,
            v.created_at    AS viaje_created,
            vh.placas,
            vh.marca,
            vh.modelo,
            vh.conductor,
            vh.estado       AS vehiculo_estado,
            t.temperatura_actual,
            t.latitud_actual,
            t.longitud_actual,
            t.sensor_puerta,
            t.timestamp_lectura_real AS ultima_lectura,
            t.sincronizado_nube,
            t.lote_comprometido
        FROM viajes v
        LEFT JOIN vehiculos vh ON vh.id = v.vehiculo_id
        LEFT JOIN telemetria_serial t
            ON  t.viaje_id = v.id
            AND t.id = (
                SELECT MAX(t2.id)
                FROM telemetria_serial t2
                WHERE t2.viaje_id = v.id
            )
        WHERE v.estado = 'activo'
        ORDER BY COALESCE(t.timestamp_lectura_real, 0) DESC
    ");
    $stmt->execute();
    $viajes = $stmt->fetchAll();

    foreach ($viajes as &$row) {
        $ultima  = (int)($row['ultima_lectura'] ?? 0);
        $difSeg  = $ultima > 0 ? ($now - $ultima) : PHP_INT_MAX;
        $difMin  = $difSeg / 60;

        if ($ultima === 0) {
            $row['estado_conexion'] = 'sin_datos';
            $row['estado_label']    = 'Sin datos';
        } elseif ($difMin < 2) {
            $row['estado_conexion'] = 'en_ruta';
            $row['estado_label']    = 'En Ruta';
        } elseif ($difMin < 10) {
            $row['estado_conexion'] = 'detenido';
            $row['estado_label']    = 'Detenido';
        } else {
            $row['estado_conexion'] = 'sin_senal';
            $row['estado_label']    = 'Sin Señal';
        }

        $row['minutos_sin_conexion'] = $ultima > 0 ? (int)round($difMin) : null;
        // cast numerics for clean JSON
        foreach (['temperatura_actual','latitud_actual','longitud_actual','temp_min','temp_max'] as $k) {
            if (isset($row[$k])) $row[$k] = (float)$row[$k];
        }
        $row['sensor_puerta']     = (int)($row['sensor_puerta'] ?? 0);
        $row['sincronizado_nube'] = (int)($row['sincronizado_nube'] ?? 0);
        $row['lote_comprometido'] = (int)($row['lote_comprometido'] ?? 0);
    }
    unset($row);

    return [
        'viajes'    => $viajes,
        'total'     => count($viajes),
        'timestamp' => $now,
    ];
}

/** GET /api/viajes-historial */
function ctrlViajesHistorial(): array
{
    $db  = Database::get();
    $now = time();

    $stmt = $db->prepare("
        SELECT
            v.id            AS viaje_id,
            v.vehiculo_id,
            v.medicamento,
            v.origen,
            v.destino,
            v.temp_min,
            v.temp_max,
            v.estado        AS viaje_estado,
            v.created_at    AS viaje_created,
            vh.placas,
            vh.marca,
            vh.modelo,
            vh.conductor,
            COUNT(t.id)                                           AS total_lecturas,
            MIN(t.temperatura_actual)                             AS temp_minima,
            MAX(t.temperatura_actual)                             AS temp_maxima,
            ROUND(AVG(t.temperatura_actual), 2)                   AS temp_promedio,
            MIN(t.timestamp_lectura_real)                         AS primera_lectura,
            MAX(t.timestamp_lectura_real)                         AS ultima_lectura,
            SUM(CASE WHEN t.lote_comprometido = 1 THEN 1 ELSE 0 END) AS lecturas_criticas
        FROM viajes v
        LEFT JOIN vehiculos vh ON vh.id = v.vehiculo_id
        LEFT JOIN telemetria_serial t ON t.viaje_id = v.id
        WHERE v.estado != 'activo'
        GROUP BY v.id
        ORDER BY v.created_at DESC
        LIMIT 100
    ");
    $stmt->execute();
    $viajes = $stmt->fetchAll();

    foreach ($viajes as &$row) {
        foreach (['temp_min','temp_max','temp_minima','temp_maxima','temp_promedio'] as $k) {
            if ($row[$k] !== null) $row[$k] = round((float)$row[$k], 2);
        }
        $row['total_lecturas']    = (int)($row['total_lecturas'] ?? 0);
        $row['lecturas_criticas'] = (int)($row['lecturas_criticas'] ?? 0);
    }
    unset($row);

    return [
        'viajes'    => $viajes,
        'total'     => count($viajes),
        'timestamp' => $now,
    ];
}

/* ============================================================================
 | ROUTER
 ============================================================================ */

// ══════════════════════════════════════════════════════════════════════════════
//  CONFIGURACIÓN IA
// ══════════════════════════════════════════════════════════════════════════════
const OLLAMA_URL     = 'http://localhost:11434/api/generate';
const OLLAMA_MODEL   = 'gemma3:4b';  // modelo instalado localmente
const OLLAMA_TIMEOUT = 35;           // segundos máximo de espera

// ══════════════════════════════════════════════════════════════════════════════
//  MOTOR MATEMÁTICO DE MÉTRICAS TÉRMICAS
// ══════════════════════════════════════════════════════════════════════════════
final class ThermalMetrics
{
    private const SEG_POR_LECTURA = 5;

    public static function calculate(array $tel, array $viaje): array
    {
        if (empty($tel)) return self::empty();

        $tempMin = (float)($viaje['temp_min'] ?? 2.0);
        $tempMax = (float)($viaje['temp_max'] ?? 8.0);
        $temps   = array_column($tel, 'temperatura_actual');

        $tempActual  = (float)end($temps);
        $tempMaxima  = (float)max($temps);
        $tempMinima  = (float)min($temps);
        $tempPromedio = round(array_sum($temps) / count($temps), 2);

        $lecturasFuera    = 0;
        $eventosPuerta    = 0;
        $lectsSync        = 0;
        $coordCritico     = null;
        $cantCriticos     = 0;

        foreach ($tel as $t) {
            $temp = (float)$t['temperatura_actual'];
            if ($temp > $tempMax || $temp < $tempMin) {
                $lecturasFuera++;
                $cantCriticos++;
                if ($coordCritico === null) {
                    $coordCritico = [
                        'latitud'     => $t['latitud_actual'],
                        'longitud'    => $t['longitud_actual'],
                        'descripcion' => 'Primera lectura fuera de rango',
                    ];
                }
            }
            if ((int)$t['sensor_puerta'])   $eventosPuerta++;
            if ((int)$t['sincronizado_nube']) $lectsSync++;
        }

        $tiempoFuera = (int)round($lecturasFuera * self::SEG_POR_LECTURA / 60);
        $tiempoSync  = (int)round($lectsSync     * self::SEG_POR_LECTURA / 60);

        $tendenciaData  = self::calcTendencia($tel);
        $tiempoEstimado = self::calcTiempoEstimado($tempActual, $tempMax, $tendenciaData['valor']);
        $indiceDanio    = self::calcIndiceDanio($tel, $tempMin, $tempMax);
        $estadoCalc     = self::clasificar($indiceDanio, $tempActual, $tempMax, $tiempoFuera, $eventosPuerta > 0);

        return [
            'temperatura_actual'                => round($tempActual, 2),
            'temperatura_maxima'                => round($tempMaxima, 2),
            'temperatura_minima'                => round($tempMinima, 2),
            'temperatura_promedio'              => $tempPromedio,
            'tiempo_fuera_de_rango_minutos'     => $tiempoFuera,
            'tendencia_temperatura'             => $tendenciaData['texto'],
            'tiempo_estimado_para_superar_limite' => $tiempoEstimado,
            'indice_danio_termico'              => round($indiceDanio, 2),
            'estado_calculado_por_sistema'      => $estadoCalc,
            'coordenada_evento_critico'         => $coordCritico,
            'cantidad_eventos_criticos'         => $cantCriticos,
            'tiempo_sin_senal_minutos'          => $tiempoSync,
            'eventos_puerta'                    => $eventosPuerta,
            'estado_conexion'                   => $lectsSync > 0 ? 'intermitente' : 'estable',
            'datos_sincronizados'               => $lectsSync > 0,
        ];
    }

    private static function calcTendencia(array $tel): array
    {
        $n = count($tel);
        if ($n < 2) return ['valor' => 0.0, 'texto' => '0.00 °C/min'];

        $ventana  = array_slice($tel, -min(12, $n));
        $primero  = (float)$ventana[0]['temperatura_actual'];
        $ultimo   = (float)end($ventana)['temperatura_actual'];
        $minutos  = (count($ventana) - 1) * self::SEG_POR_LECTURA / 60;

        if ($minutos < 0.001) return ['valor' => 0.0, 'texto' => '0.00 °C/min'];

        $t = ($ultimo - $primero) / $minutos;
        return ['valor' => $t, 'texto' => ($t >= 0 ? '+' : '') . number_format($t, 2) . ' °C/min'];
    }

    private static function calcTiempoEstimado(float $actual, float $max, float $tendencia): string
    {
        if ($actual > $max)    return 'ya superado';
        if ($tendencia <= 0)   return 'sin riesgo inmediato por tendencia actual';
        if (($max - $actual) < 0.01) return 'menos de 1 minuto';

        $min = ($max - $actual) / $tendencia;
        return $min < 1 ? 'menos de 1 minuto' : round($min) . ' minutos';
    }

    private static function calcIndiceDanio(array $tel, float $min, float $max, float $sens = 2.0): float
    {
        $minPorLect = self::SEG_POR_LECTURA / 60;
        $total = 0.0;
        foreach ($tel as $t) {
            $temp = (float)$t['temperatura_actual'];
            if ($temp > $max)      $total += ($temp - $max)  * $minPorLect * $sens;
            elseif ($temp < $min)  $total += ($min  - $temp) * $minPorLect * $sens;
        }
        return $total;
    }

    private static function clasificar(float $danio, float $actual, float $max, int $tFuera, bool $puerta): string
    {
        $nivel = match(true) {
            $danio <= 0  => 0,
            $danio <= 30 => 1,
            $danio <= 60 => 3,
            $danio <= 90 => 4,
            default      => 5,
        };
        if ($actual > $max)                        $nivel = max($nivel, 3);
        if ($actual > $max && $tFuera > 10)        $nivel = max($nivel, 4);
        if ($actual > $max && $tFuera > 20)        $nivel = max($nivel, 5);
        if ($puerta  && $actual > $max)            $nivel = min($nivel + 1, 5);

        return ['seguro','vigilancia','preventivo','alto','critico','comprometido'][$nivel];
    }

    private static function empty(): array
    {
        return [
            'temperatura_actual' => 0, 'temperatura_maxima' => 0, 'temperatura_minima' => 0,
            'temperatura_promedio' => 0, 'tiempo_fuera_de_rango_minutos' => 0,
            'tendencia_temperatura' => '0.00 °C/min',
            'tiempo_estimado_para_superar_limite' => 'datos insuficientes',
            'indice_danio_termico' => 0, 'estado_calculado_por_sistema' => 'seguro',
            'coordenada_evento_critico' => null, 'cantidad_eventos_criticos' => 0,
            'tiempo_sin_senal_minutos' => 0, 'eventos_puerta' => 0,
            'estado_conexion' => 'estable', 'datos_sincronizados' => false,
        ];
    }
}

// ══════════════════════════════════════════════════════════════════════════════
//  CLIENTE OLLAMA
// ══════════════════════════════════════════════════════════════════════════════
final class OllamaClient
{
    private const NIVELES_VALIDOS = ['seguro','vigilancia','preventivo','alto','critico','comprometido'];
    private const CAMPOS_REQUERIDOS = [
        'nivel_riesgo','titulo_alerta','mensaje_dashboard','resumen_operador',
        'explicacion_tecnica','prediccion','posibles_causas','acciones_recomendadas',
        'resumen_auditoria','confianza','requiere_revision',
    ];

    public static function analyze(array $datosJson, string $model = OLLAMA_MODEL): array
    {
        $json    = json_encode($datosJson, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $prompt  = self::buildPrompt($json);
        $payload = json_encode([
            'model'   => $model,
            'prompt'  => $prompt,
            'stream'  => false,
            'format'  => 'json',
            'options' => ['temperature' => 0.2],
        ]);

        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\n",
                'content'       => $payload,
                'timeout'       => OLLAMA_TIMEOUT,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents(OLLAMA_URL, false, $ctx);

        if ($raw === false) {
            return ['ok' => false, 'error' => 'Ollama no disponible (conexión rechazada)'];
        }

        $resp = json_decode($raw, true);
        if (!isset($resp['response'])) {
            return ['ok' => false, 'error' => 'Respuesta inesperada de Ollama'];
        }

        $analisis = json_decode($resp['response'], true);
        if (!is_array($analisis)) {
            // Intentar extraer JSON si hay texto extra
            preg_match('/\{.*\}/s', $resp['response'], $m);
            $analisis = $m ? json_decode($m[0], true) : null;
        }

        if (!is_array($analisis)) {
            return ['ok' => false, 'error' => 'JSON inválido en respuesta de Ollama'];
        }

        return ['ok' => true, 'analisis' => self::validate($analisis), 'modelo' => $model];
    }

    private static function validate(array $a): array
    {
        // Completar campos faltantes con valores seguros
        foreach (self::CAMPOS_REQUERIDOS as $campo) {
            if (!array_key_exists($campo, $a)) {
                $a[$campo] = match($campo) {
                    'posibles_causas', 'acciones_recomendadas' => [],
                    'prediccion'       => ['estado' => '', 'tiempo_estimado' => '', 'motivo' => ''],
                    'requiere_revision' => true,
                    default             => '',
                };
            }
        }

        // Validar nivel_riesgo
        if (!in_array($a['nivel_riesgo'], self::NIVELES_VALIDOS, true)) {
            $a['nivel_riesgo'] = 'vigilancia';
        }

        // Asegurar que prediccion tenga sus subclaves
        if (!is_array($a['prediccion'])) $a['prediccion'] = [];
        $a['prediccion'] += ['estado' => '', 'tiempo_estimado' => '', 'motivo' => ''];

        // Asegurar arrays
        if (!is_array($a['posibles_causas']))       $a['posibles_causas'] = [];
        if (!is_array($a['acciones_recomendadas'])) $a['acciones_recomendadas'] = [];

        return $a;
    }

    private static function buildPrompt(string $datosJson): string
    {
        return <<<PROMPT
Eres un asistente experto en monitoreo logístico de cadena de frío para insumos médicos.
Analiza los datos de telemetría de un lote médico transportado en una ruta con posible pérdida de señal.

Reglas obligatorias:
- No inventes datos.
- Usa únicamente la información proporcionada.
- No tomes decisiones médicas definitivas.
- No digas que el medicamento puede aplicarse a pacientes.
- No sustituyes a una autoridad sanitaria.
- Tu análisis es logístico, preventivo y de auditoría.
- Si el lote supera el rango permitido, recomienda revisión o validación antes de entrega.
- Si faltan datos, indícalo claramente.
- Devuelve únicamente JSON válido.
- No agregues texto antes ni después del JSON.
- No uses Markdown. No uses explicaciones fuera del JSON.

Clasifica el riesgo usando únicamente uno de estos valores:
seguro, vigilancia, preventivo, alto, critico, comprometido

Datos del lote:
{$datosJson}

Devuelve exactamente esta estructura JSON:
{
  "nivel_riesgo": "",
  "titulo_alerta": "",
  "mensaje_dashboard": "",
  "resumen_operador": "",
  "explicacion_tecnica": "",
  "prediccion": { "estado": "", "tiempo_estimado": "", "motivo": "" },
  "posibles_causas": [],
  "acciones_recomendadas": [],
  "resumen_auditoria": "",
  "confianza": "",
  "requiere_revision": true
}
PROMPT;
    }

    public static function fallback(string $estadoCalc, string $motivo = ''): array
    {
        return [
            'nivel_riesgo'         => $estadoCalc,
            'titulo_alerta'        => 'IA local no disponible',
            'mensaje_dashboard'    => 'Se usó la predicción matemática base porque Ollama no respondió correctamente.',
            'resumen_operador'     => 'El sistema calculó el riesgo con métricas internas, pero no fue posible generar análisis IA.',
            'explicacion_tecnica'  => 'La conexión con Ollama falló o devolvió una respuesta inválida. ' . $motivo,
            'prediccion'           => ['estado' => $estadoCalc, 'tiempo_estimado' => 'según métricas', 'motivo' => 'fallback matemático'],
            'posibles_causas'      => [],
            'acciones_recomendadas' => ['Verificar que Ollama esté encendido', 'Revisar el modelo configurado', 'Intentar nuevamente el análisis'],
            'resumen_auditoria'    => 'No se pudo generar análisis IA. Se conservó el cálculo matemático base.',
            'confianza'            => 'media',
            'requiere_revision'    => true,
        ];
    }
}

// ══════════════════════════════════════════════════════════════════════════════
//  REPOSITORIO ANÁLISIS IA
// ══════════════════════════════════════════════════════════════════════════════
final class IaAnalisisRepo
{
    private PDO $db;

    public function __construct() { $this->db = Database::get(); }

    public function save(string $viajeId, string $vehiculo, string $medicamento,
                         ?string $modelo, string $origen, array $analisis, array $metricas,
                         array $datosEntrada): int
    {
        $pred = $analisis['prediccion'] ?? [];
        $stmt = $this->db->prepare("
            INSERT INTO analisis_ia_riesgo
              (id_viaje, vehiculo, medicamento, modelo_ollama, origen,
               nivel_riesgo, titulo_alerta, mensaje_dashboard, resumen_operador,
               explicacion_tecnica, prediccion_estado, prediccion_tiempo, prediccion_motivo,
               posibles_causas, acciones_recomendadas, resumen_auditoria, confianza,
               requiere_revision, indice_danio_termico, temperatura_actual, temperatura_maxima,
               tiempo_fuera_de_rango_minutos, datos_entrada, respuesta_completa)
            VALUES
              (:id_viaje, :vehiculo, :medicamento, :modelo, :origen,
               :nivel_riesgo, :titulo_alerta, :mensaje_dashboard, :resumen_operador,
               :explicacion_tecnica, :pred_estado, :pred_tiempo, :pred_motivo,
               :causas, :acciones, :auditoria, :confianza,
               :revision, :danio, :temp_actual, :temp_max,
               :t_fuera, :datos_entrada, :respuesta_completa)
        ");
        $stmt->execute([
            ':id_viaje'          => $viajeId,
            ':vehiculo'          => $vehiculo,
            ':medicamento'       => $medicamento,
            ':modelo'            => $modelo,
            ':origen'            => $origen,
            ':nivel_riesgo'      => $analisis['nivel_riesgo'] ?? 'vigilancia',
            ':titulo_alerta'     => $analisis['titulo_alerta'] ?? '',
            ':mensaje_dashboard' => $analisis['mensaje_dashboard'] ?? '',
            ':resumen_operador'  => $analisis['resumen_operador'] ?? '',
            ':explicacion_tecnica' => $analisis['explicacion_tecnica'] ?? '',
            ':pred_estado'       => $pred['estado'] ?? '',
            ':pred_tiempo'       => $pred['tiempo_estimado'] ?? '',
            ':pred_motivo'       => $pred['motivo'] ?? '',
            ':causas'            => json_encode($analisis['posibles_causas'] ?? []),
            ':acciones'          => json_encode($analisis['acciones_recomendadas'] ?? []),
            ':auditoria'         => $analisis['resumen_auditoria'] ?? '',
            ':confianza'         => $analisis['confianza'] ?? 'media',
            ':revision'          => (int)($analisis['requiere_revision'] ?? 1),
            ':danio'             => $metricas['indice_danio_termico'] ?? 0,
            ':temp_actual'       => $metricas['temperatura_actual'] ?? 0,
            ':temp_max'          => $metricas['temperatura_maxima'] ?? 0,
            ':t_fuera'           => $metricas['tiempo_fuera_de_rango_minutos'] ?? 0,
            ':datos_entrada'     => json_encode($datosEntrada),
            ':respuesta_completa' => json_encode($analisis),
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function getLatest(string $viajeId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM analisis_ia_riesgo WHERE id_viaje = ? ORDER BY creado_en DESC LIMIT 1'
        );
        $stmt->execute([$viajeId]);
        $row = $stmt->fetch();
        if (!$row) return null;

        $row['posibles_causas']       = json_decode($row['posibles_causas']       ?? '[]', true) ?: [];
        $row['acciones_recomendadas'] = json_decode($row['acciones_recomendadas'] ?? '[]', true) ?: [];
        $row['datos_entrada']         = json_decode($row['datos_entrada']         ?? '{}', true) ?: [];
        $row['creado_en_fmt']         = date('Y-m-d H:i:s', (int)$row['creado_en']);
        return $row;
    }
}

// ══════════════════════════════════════════════════════════════════════════════
//  CONTROLADORES IA
// ══════════════════════════════════════════════════════════════════════════════

/** POST /api/ia/analizar-riesgo */
function ctrlIaAnalizarRiesgo(): array
{
    $body    = jsonBody();
    $viajeId = trim((string)($body['id_viaje'] ?? $body['id_lote'] ?? $body['vehiculo'] ?? ''));

    if ($viajeId === '') {
        http_response_code(400);
        return ['error' => 'Se requiere id_viaje, id_lote o vehiculo'];
    }

    // Obtener viaje y telemetría
    $viajeRepo = new ViajeRepo();
    $viaje     = $viajeRepo->find($viajeId);

    if ($viaje === null) {
        // Buscar por vehiculo_id si no se encontró como id de viaje
        $db   = Database::get();
        $stmt = $db->prepare("SELECT * FROM viajes WHERE vehiculo_id = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$viajeId]);
        $viaje = $stmt->fetch() ?: null;
    }

    if ($viaje === null) {
        http_response_code(404);
        return ['error' => 'Viaje/lote no encontrado', 'buscado' => $viajeId];
    }

    $telemetria = (new TelemetriaRepo())->getByViaje($viaje['id']);

    if (count($telemetria) < 2) {
        http_response_code(422);
        return ['error' => 'Datos insuficientes para análisis (mínimo 2 lecturas)', 'lecturas' => count($telemetria)];
    }

    // 1. Calcular métricas matemáticas base
    $metricas = ThermalMetrics::calculate($telemetria, $viaje);

    // 2. Construir JSON de entrada para Ollama
    $datosEntrada = [
        'id_viaje'                    => $viaje['id'],
        'vehiculo'                    => $viaje['vehiculo_id'],
        'medicamento'                 => $viaje['medicamento'],
        'origen'                      => $viaje['origen'],
        'destino'                     => $viaje['destino'],
        'rango_temperatura_permitido' => ['min' => $viaje['temp_min'], 'max' => $viaje['temp_max']],
        'sensibilidad'                => 'alta',
        'total_lecturas'              => count($telemetria),
        'ultimas_lecturas'            => array_slice(
            array_map(fn($t) => [
                'temp'     => $t['temperatura_actual'],
                'puerta'   => $t['sensor_puerta'],
                'lat'      => $t['latitud_actual'],
                'lng'      => $t['longitud_actual'],
                'offline'  => $t['sincronizado_nube'],
                'ts'       => $t['timestamp_lectura_real'],
            ], $telemetria),
            -10
        ),
    ] + $metricas;

    if ($metricas['datos_sincronizados']) {
        $datosEntrada['observaciones'][] = 'El evento ocurrió durante una zona sin señal.';
        $datosEntrada['observaciones'][] = 'Los datos fueron reconstruidos después de recuperar conexión.';
    }
    if ($metricas['tiempo_fuera_de_rango_minutos'] > 0) {
        $datosEntrada['observaciones'][] = 'El lote estuvo fuera del rango térmico permitido.';
    }

    // 3. Llamar a Ollama
    $modelo  = $body['modelo'] ?? OLLAMA_MODEL;
    $ollamaR = OllamaClient::analyze($datosEntrada, $modelo);

    $origen  = 'ollama';
    $warning = null;

    if (!$ollamaR['ok']) {
        $analisis = OllamaClient::fallback($metricas['estado_calculado_por_sistema'], $ollamaR['error']);
        $origen   = 'fallback_matematico';
        $modelo   = null;
        $warning  = $ollamaR['error'];
    } else {
        $analisis = $ollamaR['analisis'];
        $modelo   = $ollamaR['modelo'];
    }

    // 4. Guardar en BD
    (new IaAnalisisRepo())->save(
        $viaje['id'], $viaje['vehiculo_id'], $viaje['medicamento'],
        $modelo, $origen, $analisis, $metricas, $datosEntrada
    );

    $result = [
        'ok'           => true,
        'origen'       => $origen,
        'modelo'       => $modelo,
        'datos_entrada' => $datosEntrada,
        'analisis'     => $analisis,
        'creado_en'    => date('Y-m-d H:i:s'),
    ];

    if ($warning !== null) $result['warning'] = $warning;

    return $result;
}

/** GET /api/ia/ultimo-analisis?id_viaje=... */
function ctrlIaUltimoAnalisis(): array
{
    $viajeId = trim((string)($_GET['id_viaje'] ?? $_GET['vehiculo'] ?? ''));
    if ($viajeId === '') {
        http_response_code(400);
        return ['error' => 'Parámetro id_viaje requerido'];
    }

    $row = (new IaAnalisisRepo())->getLatest($viajeId);
    if ($row === null) {
        return ['ok' => false, 'mensaje' => 'No hay análisis IA disponible para este viaje.'];
    }

    return ['ok' => true, 'analisis' => $row];
}

// ══════════════════════════════════════════════════════════════════════════════
//  ROUTER
// ══════════════════════════════════════════════════════════════════════════════
$method = $_SERVER['REQUEST_METHOD'];

$uri = parse_url(
    $_SERVER['REQUEST_URI'],
    PHP_URL_PATH
);

$uri = preg_replace('#^/api#', '', $uri);

$uri = rtrim($uri, '/');

if ($uri === '') {
    $uri = '/';
}

try {

    /* =========================
       DASHBOARD
    ========================= */

    if (
        $method === 'GET' &&
        $uri === '/dashboard'
    ) {

        respond(ctrlDashboard());
    }

    /* =========================
       VIAJES ACTIVOS
    ========================= */

    if ($method === 'GET' && $uri === '/viajes-activos') {
        respond(ctrlViajesActivos());
    }

    if ($method === 'GET' && $uri === '/viajes-historial') {
        respond(ctrlViajesHistorial());
    }

    /* =========================
       VIAJES
    ========================= */

    if (
        $method === 'POST' &&
        $uri === '/viaje'
    ) {

        $id = (new ViajeRepo())
            ->create(jsonBody());

        respond([
            'ok' => true,
            'id' => $id
        ]);
    }

    if (
        $method === 'GET' &&
        str_starts_with($uri, '/viaje/')
    ) {

        $id = basename($uri);

        $viaje = (new ViajeRepo())
            ->find($id);

        if (!$viaje) {

            respond([
                'error' => 'Viaje no encontrado'
            ], 404);
        }

        respond([
            'viaje' => $viaje,
            'telemetria' =>
                (new TelemetriaRepo())
                    ->getByViaje($id)
        ]);
    }

    /* =========================
       TELEMETRIA
    ========================= */

    if (
        $method === 'POST' &&
        $uri === '/telemetria'
    ) {

        $data = jsonBody();

        required($data, [

            'viaje_id',
            'temperatura_actual',
            'latitud_actual',
            'longitud_actual'
        ]);

        $id = (new TelemetriaRepo())->insert($data);

        // Detectar anomalías y notificar
        $viaje = (new ViajeRepo())->find($data['viaje_id']);
        if ($viaje) {
            $tMin  = (float)($viaje['temp_min'] ?? 2.0);
            $tMax  = (float)($viaje['temp_max'] ?? 8.0);
            $tAct  = (float)$data['temperatura_actual'];
            $extra = [
                'medicamento' => $viaje['medicamento'] ?? '',
                'vehiculo'    => $viaje['vehiculo_id'] ?? '',
                'origen'      => $viaje['origen']      ?? '',
                'destino'     => $viaje['destino']     ?? '',
                'lat'         => $data['latitud_actual']  ?? '',
                'lng'         => $data['longitud_actual'] ?? '',
            ];
            $notif = new Notificador();

            if ($tAct > $tMax + 1.5) {
                (new AlertaRepo())->insert([
                    'viaje_id'    => $data['viaje_id'],
                    'tipo'        => 'temperatura_critica',
                    'descripcion' => "Temperatura critica: {$tAct}C (max: {$tMax}C)",
                    'latitud'     => $data['latitud_actual']  ?? null,
                    'longitud'    => $data['longitud_actual'] ?? null,
                    'valor'       => $tAct,
                    'timestamp'   => $data['timestamp_lectura_real'] ?? time(),
                ]);
                $notif->alertar('Temperatura Critica', "Temperatura {$tAct}C supera el maximo permitido de {$tMax}C", $tAct, $data['viaje_id'], $extra);

            } elseif ($tAct < $tMin - 1.5) {
                (new AlertaRepo())->insert([
                    'viaje_id'    => $data['viaje_id'],
                    'tipo'        => 'temperatura_riesgo',
                    'descripcion' => "Temperatura baja: {$tAct}C (min: {$tMin}C)",
                    'latitud'     => $data['latitud_actual']  ?? null,
                    'longitud'    => $data['longitud_actual'] ?? null,
                    'valor'       => $tAct,
                    'timestamp'   => $data['timestamp_lectura_real'] ?? time(),
                ]);
                $notif->alertar('Temperatura Baja', "Temperatura {$tAct}C esta bajo el minimo permitido de {$tMin}C", $tAct, $data['viaje_id'], $extra);
            }

            if (!empty($data['puerta_abierta'])) {
                $notif->alertar('Puerta Abierta', 'La puerta del vehiculo refrigerado esta abierta', 0.0, $data['viaje_id'], $extra);
            }
        }

        respond(['ok' => true, 'id' => $id]);
    }

    /* =========================
       ALERTAS
    ========================= */

    if (
        $method === 'GET' &&
        $uri === '/alertas'
    ) {

        respond([
            'alertas' =>
                (new AlertaRepo())
                    ->recent()
        ]);
    }

    /* =========================
       USUARIOS
    ========================= */

    if (
        $method === 'GET' &&
        $uri === '/usuarios'
    ) {

        respond([
            'usuarios' =>
                (new UsuarioRepo())
                    ->all()
        ]);
    }

    if (
        $method === 'POST' &&
        $uri === '/usuarios'
    ) {

        $data = jsonBody();

        required($data, [
            'nombre',
            'email',
            'password'
        ]);

        $id = (new UsuarioRepo())
            ->create($data);

        respond([
            'ok' => true,
            'id' => $id
        ]);
    }

    if (
        $method === 'PUT' &&
        str_starts_with($uri, '/usuarios/')
    ) {

        $id = (int)basename($uri);

        $ok = (new UsuarioRepo())
            ->update($id, jsonBody());

        respond([
            'ok' => $ok
        ]);
    }

    if (
        $method === 'DELETE' &&
        str_starts_with($uri, '/usuarios/')
    ) {

        $id = (int)basename($uri);

        $ok = (new UsuarioRepo())
            ->delete($id);

        respond([
            'ok' => $ok
        ]);
    }

    /* =========================
       MEDICAMENTOS
    ========================= */

    if ($method === 'GET' && $uri === '/medicamentos') {
        respond(['medicamentos' => (new MedicamentoRepo())->all()]);
    }

    if ($method === 'POST' && $uri === '/medicamentos') {
        $data = jsonBody();
        required($data, ['nombre', 'temp_min', 'temp_max']);
        $id = (new MedicamentoRepo())->create($data);
        respond(['ok' => true, 'id' => $id]);
    }

    if ($method === 'PUT' && str_starts_with($uri, '/medicamentos/')) {
        $id = basename($uri);
        $ok = (new MedicamentoRepo())->update((int)$id, jsonBody());
        respond(['ok' => $ok]);
    }

    if ($method === 'DELETE' && str_starts_with($uri, '/medicamentos/')) {
        $id = basename($uri);
        $ok = (new MedicamentoRepo())->delete((int)$id);
        respond(['ok' => $ok]);
    }

    /* =========================
       VEHICULOS
    ========================= */

    if (
        $method === 'GET' &&
        $uri === '/vehiculos'
    ) {

        respond([
            'vehiculos' =>
                (new VehiculoRepo())
                    ->all()
        ]);
    }

    if (
        $method === 'POST' &&
        $uri === '/vehiculos'
    ) {

        $data = jsonBody();

        required($data, [
            'placas',
            'modelo',
            'marca'
        ]);

        $id = (new VehiculoRepo())
            ->create($data);

        respond([
            'ok' => true,
            'id' => $id
        ]);
    }

    if (
        $method === 'PUT' &&
        str_starts_with($uri, '/vehiculos/')
    ) {

        $id = basename($uri);

        $ok = (new VehiculoRepo())
            ->update($id, jsonBody());

        respond([
            'ok' => $ok
        ]);
    }

    if (
        $method === 'DELETE' &&
        str_starts_with($uri, '/vehiculos/')
    ) {

        $id = basename($uri);

        $ok = (new VehiculoRepo())
            ->delete($id);

        respond([
            'ok' => $ok
        ]);
    }

    /* =========================
       IA
    ========================= */

    if ($method === 'POST' && $uri === '/ia/analizar-riesgo') {
        respond(ctrlIaAnalizarRiesgo());
    }

    if ($method === 'GET' && $uri === '/ia/ultimo-analisis') {
        respond(ctrlIaUltimoAnalisis());
    }

    /* =========================
       CONFIGURACIÓN
    ========================= */

    if ($method === 'GET' && $uri === '/config') {
        $map = (new ConfigRepo())->toMap();
        unset($map['smtp_pass'], $map['callmebot_apikey']);
        respond($map);
    }

    if ($method === 'POST' && $uri === '/config') {
        $data = jsonBody();
        $cfg  = new ConfigRepo();
        $permitidos = [
            'alerta_sms','sms_activo',
            'twilio_sid','twilio_token','twilio_sms_from',
            'idioma','nombre_sistema',
        ];
        foreach ($permitidos as $k) {
            if (array_key_exists($k, $data)) $cfg->set($k, (string)$data[$k]);
        }
        respond(['ok' => true]);
    }

    if ($method === 'POST' && $uri === '/config/test-sms') {
        $msg    = "FRIOSEGURO - Prueba\nSistema de alertas activo.\n" . date('d/m/Y H:i:s');
        $result = (new Notificador())->probarSMS($msg);
        respond([
            'ok'     => $result['ok'],
            'mensaje' => $result['mensaje'] ?? $result['error'] ?? 'Error desconocido.',
        ]);
    }

    /* =========================
       404
    ========================= */

    respond([
        'error' => 'Endpoint no encontrado',
        'uri' => $uri
    ], 404);

} catch (Throwable $e) {

    respond([

        'error' => $e->getMessage(),

        'file' => basename(
            $e->getFile()
        ),

        'line' => $e->getLine()
    ], 500);
}
