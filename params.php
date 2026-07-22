<?php
// params.php
require_once __DIR__ . '/db.php';

function obtener_token()
{
    $data = array(
        'client_id' => 'Wl16Z5dE1DIwMTQ1Njg2NTQ4p9dnf72Rng', // CREDENCIALES DE RED SALUD SAN ROMAN
        'client_secret' => '7PXbFjOEEXJAo2cLOYaXH-1RYcwz6iebwBg', // CREDENCIALES DE RED SALUD SAN ROMAN
    );

    $postData = http_build_query($data);

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, 'https://apps.firmaperu.gob.pe/admin/api/security/generate-token');
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($curl);

    if ($response === false) {
        curl_close($curl);
        http_response_code(500);
        echo 'Error al generar token: ' . curl_error($curl);
        exit;
    }

    curl_close($curl);
    return $response;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // FirmaPerú envía automáticamente el 'param_token' en el cuerpo del POST
    if (!isset($_POST['param_token'])) {
        http_response_code(400);
        echo 'Falta el parámetro param_token';
        exit;
    }

    $token_transaccion = $_POST['param_token'];

    // 1. BUSCAR LOS DATOS DE LA FIRMA GUARDADOS PREVIAMENTE EN LA BD
    $stmtFirma = $pdo->prepare("SELECT * FROM firmas_pendientes WHERE token_transaccion = ?");
    $stmtFirma->execute([$token_transaccion]);
    $pendiente = $stmtFirma->fetch(PDO::FETCH_ASSOC);

    if (!$pendiente) {
        http_response_code(404);
        echo 'Transacción o intención de firma no encontrada';
        exit;
    }

    $codigo_doc = $pendiente['codigo_documento'];
    // 2. OBTENER LA ÚLTIMA VERSIÓN FÍSICA ASOCIADA A ESE CÓDIGO (EL ÚLTIMO FIRMADO)
    $stmtVersion = $pdo->prepare("SELECT * FROM documento_versiones WHERE codigo_documento = ? ORDER BY version_nro DESC LIMIT 1");
    $stmtVersion->execute([$codigo_doc]);
    $version_act = $stmtVersion->fetch(PDO::FETCH_ASSOC);

    if (!$version_act) {
        http_response_code(404);
        echo 'No se encontró un archivo base para este documento';
        exit;
    }
    $img = ($pendiente['signaturestyle'] == 1)? 'https://rissanroman.gob.pe/firma/img/rssr-logo.png':'https://rissanroman.gob.pe/firma/img/rssr-vertical.png'; 
    
    // 3. CONSTRUIR LOS PARÁMETROS BASE DE FIRMAPERÚ
    $firma_params = array(
        "signatureFormat"        => "PAdES",
        "signatureLevel"         => "B",
        "signaturePackaging"     => "enveloped",
        "documentToSign"         => $baseUrl . $version_act['ruta_pdf'], // Ruta al último PDF (con su nombre UUID)
        "certificateFilter"      => ".*",
        "webTsa"                 => "",
        "userTsa"                => "",
        "passwordTsa"            => "",
        "theme"                  => "claro",
        "visiblePosition"        => true,
        "contactInfo"            => "",
        "signatureReason"        => $pendiente['tipo_firma'], // Razón recuperada de la BD
        "bachtOperation"         => false,
        "oneByOne"               => true,
        "signatureStyle"         => $pendiente['signaturestyle'] ?? 1, // 1 horizontal, 2 vertical
        "imageToStamp"           => $img, // Imagen de sello según estilo,
        //"imageToStamp"           => "https://rissanroman.gob.pe/firma/img/rssr-logo.png",
        //"imageToStamp"           => "https://rissanroman.gob.pe/firma/img/rssr-sign.png",
        "stampTextSize"          => 14,
        "stampWordWrap"          => 37,
        "role"                   => $pendiente['cargo'], // Cargo recuperado de la BD
        "stampPage"              => 1,
        "positionx"              => 20,
        "positiony"              => 20,
        // El documento firmado irá directamente al archivo dedicado pasándole el token por la URL
        "uploadDocumentSigned"   => $baseUrl . "firmado.php?token=" . urlencode($token_transaccion),
        "certificationSignature" => false,
        "token"                  => obtener_token() // Token dinámico de la API oficial
    );

    // Codificamos a JSON
    $json = json_encode($firma_params, JSON_UNESCAPED_UNICODE);

    if ($json === false) {
        http_response_code(500);
        echo 'Error al convertir a JSON';
        exit;
    }

    // Codificamos a Base64 sin modificar
    $base64 = base64_encode($json);

    // Enviamos solo el base64 como texto plano al componente local
    header('Content-Type: text/plain');
    echo $base64;
} else {
    http_response_code(405);
    header('Content-Type: application/x-www-form-urlencoded');
    echo http_build_query(array('error' => 'Método no permitido'));
}
