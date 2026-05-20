<?php
// Router para el servidor de desarrollo de PHP
// Uso: php -S localhost:8080 router.php
//
// Redirige /api/* → api.php
// Sirve archivos estáticos directamente (.html, .css, .js, etc.)

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Rutas de API → api.php
if (str_starts_with($uri, '/api')) {
    require __DIR__ . '/api.php';
    return true;
}

// Archivos estáticos existentes → servirlos directamente
$file = __DIR__ . $uri;
if ($uri !== '/' && file_exists($file) && !is_dir($file)) {
    return false; // PHP built-in server sirve el archivo
}

// Raíz → frioseguro.html
if ($uri === '/' || $uri === '') {
    require __DIR__ . '/frioseguro.html';
    return true;
}

http_response_code(404);
echo "Not found: $uri";
