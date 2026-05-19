<?php

include "conexion.php";

/*
RECIBIR DATOS
*/

$viaje_id = $_POST['viaje_id'];
$temperatura_actual = $_POST['temperatura_actual'];
$sensor_puerta = $_POST['sensor_puerta'];
$latitud_actual = $_POST['latitud_actual'];
$longitud_actual = $_POST['longitud_actual'];

/*
ESTE TIMESTAMP
VIENE DEL MICROCONTROLADOR
NO DEL SERVIDOR
*/

$timestamp_lectura_real = $_POST['timestamp_lectura_real'];

$sql = "INSERT INTO lecturas_transporte (

    viaje_id,
    temperatura_actual,
    sensor_puerta,
    latitud_actual,
    longitud_actual,
    timestamp_lectura_real

) VALUES (?, ?, ?, ?, ?, ?)";

$stmt = $conn->prepare($sql);

$stmt->bind_param(
    "sdidds",

    $viaje_id,
    $temperatura_actual,
    $sensor_puerta,
    $latitud_actual,
    $longitud_actual,
    $timestamp_lectura_real
);

if ($stmt->execute()) {

    echo json_encode([
        "success" => true,
        "mensaje" => "Lectura insertada"
    ]);

} else {

    echo json_encode([
        "success" => false,
        "error" => $stmt->error
    ]);
}

$stmt->close();
$conn->close();

?>