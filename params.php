<?php
// params.php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/token.php';

function obtener_token()
{   
    //$data = array(
    //    'client_id' => 'xxxx', // CREDENCIALES 
    //    'client_secret' => 'xxx', // CREDENCIALES 
    //);
    $data = getToken();
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

    $codigos = json_decode($pendiente['codigo_documento'], true);
    $es_lote = is_array($codigos) && count($codigos) > 1;

    if ($es_lote) {
        // Ruta al archivo .7z generado previamente
        $documento_url = $baseUrl . '/archivos_sgd/input_' . $token_transaccion . '.7z';
    } else {
        $codigo_doc = is_array($codigos) ? $codigos[0] : $pendiente['codigo_documento'];
        $stmtVersion = $pdo->prepare("SELECT ruta_pdf FROM documento_versiones WHERE codigo_documento = ? ORDER BY version_nro DESC LIMIT 1");
        $stmtVersion->execute([$codigo_doc]);
        $documento_url = $baseUrl . $stmtVersion->fetchColumn();
    }

    $img = ($pendiente['signaturestyle'] == 1)
        ? 'https://rissanroman.gob.pe/firma/img/rssr-logo.png'
        : 'https://rissanroman.gob.pe/firma/img/rssr-vertical.png';

    $firma_params = array(
        "signatureFormat"        => "PAdES",
        "signatureLevel"         => "B",
        "signaturePackaging"     => "enveloped",
        "documentToSign"         => $documento_url,
        "certificateFilter"      => ".*",
        "webTsa"                 => "",
        "userTsa"                => "",
        "passwordTsa"            => "",
        "theme"                  => "claro",
        "visiblePosition"        => true,
        "contactInfo"            => "",
        "signatureReason"        => $pendiente['tipo_firma'],

        // PARAMETROS DE LOTE DINÁMICOS
        "bachtOperation"         => $es_lote, // True si es lote, False si es único
        //"oneByOne"               => ($pendiente['one_by_one']) ? true : false,
        "oneByOne"               => ($pendiente['one_by_one'])? true:false,

        "signatureStyle"         => $pendiente['signaturestyle'] ?? 1,
        "imageToStamp"           => $img,
        "stampTextSize"          => 14,
        "stampWordWrap"          => 37,
        "role"                   => $pendiente['cargo'],
        "stampPage"              => 1,
        "positionx"              => 20,
        "positiony"              => 20,
        "uploadDocumentSigned"   => $baseUrl . "/firmado.php?token=" . urlencode($token_transaccion),
        "certificationSignature" => false,
        "token"                  => obtener_token()
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
