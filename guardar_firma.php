<?php
// guardar_firma.php
session_start();
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$codigo = strtoupper($_POST['doc_codigo'] ?? '');
$tipo_firma = strip_tags($_POST['tipo_firma'] ?? 'Firma');
$cargo = strtoupper(strip_tags($_POST['cargo'] ?? 'Personal'));
$signaturestyle = (int)($_POST['signaturestyle'] ?? 1); // 1 horizontal, 2 vertical
if (empty($codigo)) {
    echo json_encode(['status' => 'error', 'message' => 'Falta el código de documento']);
    exit;
}

// Generamos el identificador único de la transacción
$id_transaccion = uniqid('firma_', true);

// Registramos la intención de firma en la base de datos antes de llamar al applet
$stmt = $pdo->prepare("INSERT INTO firmas_pendientes (token_transaccion, codigo_documento, tipo_firma, cargo, signaturestyle) VALUES (?, ?, ?, ?, ?)");
$stmt->execute([$id_transaccion, $codigo, $tipo_firma, $cargo, $signaturestyle]);

// La URL ya no lleva parámetros expuestos en el GET
$param_url = $baseUrl . 'params.php';

$param_temp = [
    'param_url'          => $param_url,
    'param_token'        => $id_transaccion, // Usamos el token oficial del protocolo para identificar la transacción
    'document_extension' => 'pdf'
];

echo json_encode([
    'status'    => 'success',
    'param_b64' => base64_encode(json_encode($param_temp)),
    'port'      => '48596'
]);
