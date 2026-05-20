<?php
// ============================================================
//  FríoSeguro — Prueba rápida de correo
//  Abre en el navegador: http://localhost:8080/mail/test_correo.php
// ============================================================

$tipos = ['temperatura_critica','temperatura_riesgo','puerta_abierta','perdida_senal','lote_comprometido'];
$tipo  = $_GET['tipo'] ?? 'temperatura_critica';

$payload = json_encode([
    'tipo'        => $tipo,
    'vehiculo'    => 'ECO-01',
    'valor'       => 12.7,
    'medicamento' => 'Vacuna BCG',
    'viaje_id'    => 'VJ-2026-047',
    'descripcion' => 'Temperatura fuera del rango permitido (2°C – 8°C)',
    'lat'         => 19.292,
    'lng'         => -99.653,
]);

// Llamar al endpoint
$ch = curl_init('http://localhost:8080/mail/enviar_alerta_correo.php');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
]);
$res  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$resultado = json_decode($res, true);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Test Correo — FríoSeguro</title>
<style>
  body{font-family:system-ui,sans-serif;background:#0f172a;color:#f1f5f9;padding:40px;max-width:700px;margin:0 auto}
  h1{font-size:22px;margin-bottom:4px}
  .sub{color:#94a3b8;font-size:14px;margin-bottom:30px}
  .tipos{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:24px}
  .tipos a{padding:6px 14px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;border:1px solid #334155;color:#94a3b8}
  .tipos a.active{background:#3b82f6;border-color:#3b82f6;color:#fff}
  .card{background:#1e293b;border:1px solid #334155;border-radius:10px;padding:20px;margin-bottom:16px}
  .card h2{font-size:14px;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;margin:0 0 12px}
  .ok{color:#22c55e;font-weight:700} .err{color:#ef4444;font-weight:700}
  pre{background:#0f172a;border-radius:6px;padding:12px;font-size:12px;overflow-x:auto;margin:0}
</style>
</head>
<body>
<h1>🧪 Prueba de correo — FríoSeguro</h1>
<p class="sub">Selecciona el tipo de alerta y verifica que el correo se envíe.</p>

<div class="tipos">
<?php foreach($tipos as $t): ?>
  <a href="?tipo=<?=$t?>" class="<?=$t===$tipo?'active':''?>"><?=str_replace('_',' ',$t)?></a>
<?php endforeach; ?>
</div>

<div class="card">
  <h2>Resultado (HTTP <?=$code?>)</h2>
  <?php if($resultado['ok'] ?? false): ?>
    <p class="ok">✅ <?=htmlspecialchars($resultado['mensaje']??'')?></p>
  <?php else: ?>
    <p class="err">❌ <?=htmlspecialchars($resultado['mensaje']??$res??'Sin respuesta')?></p>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Payload enviado</h2>
  <pre><?=htmlspecialchars(json_encode(json_decode($payload),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE))?></pre>
</div>

<div class="card">
  <h2>Respuesta del servidor</h2>
  <pre><?=htmlspecialchars(json_encode($resultado,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)??$res?></pre>
</div>
</body>
</html>
