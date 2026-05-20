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

        ");
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

/* ============================================================================
 | ROUTER
 ============================================================================ */

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

        $id = (new TelemetriaRepo())
            ->insert($data);

        respond([
            'ok' => true,
            'id' => $id
        ]);
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
