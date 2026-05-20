<?php
// ============================================================
//  FríoSeguro — Endpoint de alertas por correo
//  Recibe POST con JSON: { tipo, vehiculo, valor, medicamento,
//                          viaje_id, descripcion, lat, lng }
//  Devuelve JSON: { ok: bool, mensaje: string }
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { responder(false, 'Solo POST'); }

// ── Cargar PHPMailer ─────────────────────────────────────────
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';
require_once __DIR__ . '/PHPMailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// ── Cargar configuración ─────────────────────────────────────
$cfg = require __DIR__ . '/config_correo.php';

// ── Leer body JSON ───────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);
if (!$body || !isset($body['tipo'])) {
    responder(false, 'JSON inválido o falta campo tipo');
}

$tipo        = $body['tipo']        ?? 'desconocida';
$vehiculo    = $body['vehiculo']    ?? '—';
$valor       = $body['valor']       ?? null;
$medicamento = $body['medicamento'] ?? '—';
$viaje_id    = $body['viaje_id']    ?? '—';
$descripcion = $body['descripcion'] ?? '';
$lat         = $body['lat']         ?? null;
$lng         = $body['lng']         ?? null;

// ── Control anti-spam ────────────────────────────────────────
$lockFile = sys_get_temp_dir() . '/frioseguro_alerta_' . md5($tipo . '_' . $vehiculo) . '.lock';
if (file_exists($lockFile)) {
    $diff = time() - (int)file_get_contents($lockFile);
    if ($diff < $cfg['cooldown_segundos']) {
        responder(true, "Alerta reciente ($diff s). Cooldown activo, correo no enviado.");
    }
}
file_put_contents($lockFile, time());

// ── Construir contenido del correo ───────────────────────────
$fechaHora = date('d/m/Y H:i:s');
$asunto    = asuntoAlerta($tipo, $vehiculo);
$htmlBody  = htmlAlerta($tipo, $vehiculo, $valor, $medicamento, $viaje_id, $descripcion, $fechaHora, $lat, $lng);
$textBody  = textAlerta($tipo, $vehiculo, $valor, $medicamento, $viaje_id, $descripcion, $fechaHora);

// ── Enviar con PHPMailer ─────────────────────────────────────
$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host        = $cfg['smtp_host'];
    $mail->Port        = $cfg['smtp_port'];
    $mail->SMTPSecure  = $cfg['smtp_segurity'];
    $mail->SMTPAuth    = true;
    $mail->Username    = $cfg['smtp_user'];
    $mail->Password    = $cfg['smtp_pass'];
    $mail->CharSet     = 'UTF-8';
    $mail->setFrom($cfg['smtp_from'], $cfg['smtp_nombre']);
    $mail->Subject     = $asunto;
    $mail->isHTML(true);
    $mail->Body        = $htmlBody;
    $mail->AltBody     = $textBody;

    // Agregar destinatarios de la config + opcionalmente del body
    foreach ($cfg['destinatarios'] as $dest) {
        $mail->addAddress($dest['correo'], $dest['nombre']);
    }
    // Si el frontend envía un correo extra (chofer del viaje), se agrega también
    if (!empty($body['correo_extra'])) {
        $mail->addAddress($body['correo_extra']);
    }

    $mail->send();
    responder(true, "Correo enviado a " . count($mail->getToAddresses()) . " destinatario(s).");

} catch (Exception $e) {
    responder(false, 'Error al enviar: ' . $mail->ErrorInfo);
}

// ════════════════════════════════════════════════════════════
//  FUNCIONES AUXILIARES
// ════════════════════════════════════════════════════════════

function responder(bool $ok, string $msg): never {
    echo json_encode(['ok' => $ok, 'mensaje' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function asuntoAlerta(string $tipo, string $vehiculo): string {
    $etiquetas = [
        'temperatura_critica' => '🔴 CRÍTICO',
        'temperatura_riesgo'  => '🟡 RIESGO',
        'puerta_abierta'      => '🚨 PUERTA ABIERTA',
        'perdida_senal'       => '📡 SIN SEÑAL',
        'lote_comprometido'   => '⚠️ LOTE COMPROMETIDO',
    ];
    $etiq = $etiquetas[$tipo] ?? ('⚠️ ' . strtoupper($tipo));
    return "[FríoSeguro] $etiq — Vehículo $vehiculo";
}

function textAlerta(string $tipo, string $vehiculo, $valor, string $med,
                    string $viaje, string $desc, string $fecha): string {
    $val = $valor !== null ? number_format((float)$valor, 1) . '°C' : '—';
    return "FríoSeguro — Alerta de $tipo\n"
         . "Vehículo: $vehiculo\n"
         . "Temperatura: $val\n"
         . "Medicamento: $med\n"
         . "Viaje: $viaje\n"
         . "Descripción: $desc\n"
         . "Fecha/Hora: $fecha\n"
         . "Se requiere atención inmediata.";
}

function htmlAlerta(string $tipo, string $vehiculo, $valor, string $med,
                    string $viaje, string $desc, string $fecha, $lat, $lng): string {

    $val    = $valor !== null ? number_format((float)$valor, 1) . '°C' : '—';
    $colores = [
        'temperatura_critica' => ['bg' => '#ef4444', 'light' => '#fef2f2', 'border' => '#fecaca'],
        'temperatura_riesgo'  => ['bg' => '#f59e0b', 'light' => '#fffbeb', 'border' => '#fde68a'],
        'puerta_abierta'      => ['bg' => '#f97316', 'light' => '#fff7ed', 'border' => '#fed7aa'],
        'perdida_senal'       => ['bg' => '#3b82f6', 'light' => '#eff6ff', 'border' => '#bfdbfe'],
        'lote_comprometido'   => ['bg' => '#8b5cf6', 'light' => '#f5f3ff', 'border' => '#ddd6fe'],
    ];
    $c      = $colores[$tipo] ?? ['bg' => '#6b7280', 'light' => '#f9fafb', 'border' => '#e5e7eb'];
    $titulo = strtoupper(str_replace('_', ' ', $tipo));

    $mapaHtml = '';
    if ($lat !== null && $lng !== null) {
        $mapaUrl  = "https://maps.google.com/maps?q={$lat},{$lng}";
        $mapaHtml = "<tr><td style='padding:8px 0;color:#6b7280;font-size:13px'>Ubicación GPS</td>"
                  . "<td style='padding:8px 0;font-size:13px'>"
                  . "<a href='$mapaUrl' style='color:{$c['bg']};font-weight:600'>Ver en Google Maps</a>"
                  . "</td></tr>";
    }

    $accion = match($tipo) {
        'temperatura_critica' => 'Detenga la unidad y revise el sistema de refrigeración de inmediato.',
        'temperatura_riesgo'  => 'Monitoree la temperatura y prepare un protocolo de contingencia.',
        'puerta_abierta'      => 'Verifique que la puerta del compartimento esté correctamente cerrada.',
        'perdida_senal'       => 'El vehículo está fuera de cobertura. Se sincronizarán los datos al recuperar señal.',
        'lote_comprometido'   => 'El lote puede haber sido dañado. Realice una inspección del medicamento.',
        default               => 'Revise el estado del vehículo y el medicamento.',
    };

    return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:'Segoe UI',Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:32px 0">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08)">

  <!-- Cabecera -->
  <tr><td style="background:{$c['bg']};padding:28px 32px;text-align:center">
    <div style="font-size:13px;color:rgba(255,255,255,.8);letter-spacing:2px;text-transform:uppercase;margin-bottom:6px">Sistema de Monitoreo</div>
    <div style="font-size:24px;font-weight:700;color:#fff;letter-spacing:.5px">FríoSeguro</div>
    <div style="display:inline-block;background:rgba(255,255,255,.2);color:#fff;font-size:12px;font-weight:600;padding:4px 14px;border-radius:20px;margin-top:10px;letter-spacing:1px">$titulo</div>
  </td></tr>

  <!-- Alerta principal -->
  <tr><td style="padding:24px 32px 0">
    <div style="background:{$c['light']};border:1px solid {$c['border']};border-left:4px solid {$c['bg']};border-radius:8px;padding:16px 20px">
      <div style="font-size:14px;color:{$c['bg']};font-weight:700;margin-bottom:4px">⚠ Alerta detectada</div>
      <div style="font-size:14px;color:#374151">$desc</div>
    </div>
  </td></tr>

  <!-- Datos -->
  <tr><td style="padding:20px 32px">
    <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse">
      <tr style="border-bottom:1px solid #f3f4f6">
        <td style="padding:10px 0;color:#6b7280;font-size:13px;width:40%">Vehículo</td>
        <td style="padding:10px 0;font-size:13px;font-weight:600;color:#111827">$vehiculo</td>
      </tr>
      <tr style="border-bottom:1px solid #f3f4f6">
        <td style="padding:10px 0;color:#6b7280;font-size:13px">Temperatura detectada</td>
        <td style="padding:10px 0;font-size:20px;font-weight:700;color:{$c['bg']}">$val</td>
      </tr>
      <tr style="border-bottom:1px solid #f3f4f6">
        <td style="padding:10px 0;color:#6b7280;font-size:13px">Medicamento / Lote</td>
        <td style="padding:10px 0;font-size:13px;color:#111827">$med</td>
      </tr>
      <tr style="border-bottom:1px solid #f3f4f6">
        <td style="padding:10px 0;color:#6b7280;font-size:13px">ID de Viaje</td>
        <td style="padding:10px 0;font-size:13px;color:#111827;font-family:monospace">$viaje</td>
      </tr>
      <tr style="border-bottom:1px solid #f3f4f6">
        <td style="padding:10px 0;color:#6b7280;font-size:13px">Fecha y hora</td>
        <td style="padding:10px 0;font-size:13px;color:#111827">$fecha</td>
      </tr>
      $mapaHtml
    </table>
  </td></tr>

  <!-- Acción requerida -->
  <tr><td style="padding:0 32px 24px">
    <div style="background:#0f172a;border-radius:8px;padding:18px 20px">
      <div style="font-size:12px;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;margin-bottom:6px">Acción requerida</div>
      <div style="font-size:14px;color:#f1f5f9;line-height:1.6">$accion</div>
    </div>
  </td></tr>

  <!-- Pie -->
  <tr><td style="background:#f8fafc;padding:16px 32px;border-top:1px solid #e2e8f0;text-align:center">
    <div style="font-size:11px;color:#94a3b8">Este es un mensaje automático de <strong>FríoSeguro</strong>. No responder a este correo.</div>
  </td></tr>

</table>
</td></tr>
</table>
</body></html>
HTML;
}
