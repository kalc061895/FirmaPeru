<?php
// guardar_firma.php
session_start();
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// Helper para sanitizar nombres de archivo (limpia tildes, espacios y caracteres especiales)
function sanitizarNombreArchivo($nombre)
{
    $info = pathinfo($nombre);
    $filename = $info['filename'];
    $extension = isset($info['extension']) ? '.' . strtolower($info['extension']) : '.pdf';

    $unwanted = [
        'A' => 'Á|À|Â|Ã|Ä',
        'a' => 'á|à|â|ã|ä',
        'E' => 'É|È|Ê|Ë',
        'e' => 'é|è|ê|ë',
        'I' => 'Í|Ì|Î|Ï',
        'i' => 'í|ì|î|ï',
        'O' => 'Ó|Ò|Ô|Õ|Ö',
        'o' => 'ó|ò|ô|õ|ö',
        'U' => 'Ú|Ù|Û|Ü',
        'u' => 'ú|ù|û|ü',
        'N' => 'Ñ',
        'n' => 'ñ'
    ];
    foreach ($unwanted as $replace => $pattern) {
        $filename = preg_replace("/($pattern)/i", $replace, $filename);
    }
    $filename = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $filename);
    $filename = preg_replace('/_+/', '_', trim($filename, '_'));

    return $filename . $extension;
}

// Puede recibir uno o varios códigos (un arreglo o string separado por comas)
$codigos = $_POST['doc_codigos'] ?? [];
if (is_string($codigos)) {
    $codigos = explode(',', $codigos);
}
$codigos = array_filter(array_map('trim', $codigos));

$tipo_firma     = strip_tags($_POST['tipo_firma'] ?? 'Firma');
$cargo          = strtoupper(strip_tags($_POST['cargo'] ?? 'Personal'));
$signaturestyle = (int)($_POST['signaturestyle'] ?? 1);
$oneByOne       = (int)($_POST['one_by_one'] ?? 0);

// 'secuencial' (oneByOne = true) o 'masiva' (oneByOne = false)
$modo_firma = $_POST['modo_firma'] ?? 'masiva';

if (empty($codigos)) {
    echo json_encode(['status' => 'error', 'message' => 'Debe seleccionar al menos un documento']);
    exit;
}

$id_transaccion = uniqid('firma_batch_', true);
$es_lote = count($codigos) > 1;


$mapaLote = []; // Diccionario para guardar en BD: [NombreLimpio.pdf => codigo_documento]

if ($es_lote) {
    // 1. Determinar ejecutable de 7-Zip
    $exe7z = '7z';
    $winPath1 = 'C:\Program Files\7-Zip\7z.exe';
    $winPath2 = 'C:\Program Files (x86)\7-Zip\7z.exe';

    if (stristr(PHP_OS, 'WIN')) {
        if (file_exists($winPath1)) {
            $exe7z = '"' . $winPath1 . '"';
        } elseif (file_exists($winPath2)) {
            $exe7z = '"' . $winPath2 . '"';
        }
    }

    // 2. Definir ruta del paquete .7z temporal
    $temp_7z_path = __DIR__ . '/archivos_sgd/input_' . $id_transaccion . '.7z';
    if (file_exists($temp_7z_path)) {
        @unlink($temp_7z_path);
    }

    // 3. Preparar los archivos físicos y temporales con nombres sanitizados
    $files_to_pack = [];
    $tmp_created_files = [];

    foreach ($codigos as $cod) {
        $stmtV = $pdo->prepare("
            SELECT d.nombre_original, v.ruta_pdf 
            FROM documentos d
            JOIN documento_versiones v ON d.codigo = v.codigo_documento
            WHERE d.codigo = ? 
            ORDER BY v.version_nro DESC LIMIT 1
        ");
        $stmtV->execute([strtoupper($cod)]);
        $docData = $stmtV->fetch(PDO::FETCH_ASSOC);

        if ($docData && file_exists(__DIR__ . '/' . $docData['ruta_pdf'])) {
            $nombreOriginal = $docData['nombre_original'] ?: ($cod . '.pdf');

            // Construir nombre sanitizado legible: CODIGO_NombreOriginalLimpio.pdf
            $nombreLimpio = strtoupper($cod) . '_' . sanitizarNombreArchivo($nombreOriginal);
            if (substr(strtolower($nombreLimpio), -4) !== '.pdf') {
                $nombreLimpio .= '.pdf';
            }

            $tmp_pdf = __DIR__ . "/archivos_sgd/{$nombreLimpio}";
            if (copy(__DIR__ . '/' . $docData['ruta_pdf'], $tmp_pdf)) {
                $files_to_pack[] = escapeshellarg($tmp_pdf);
                $tmp_created_files[] = $tmp_pdf;

                // Mapear el nombre limpio con su código correspondiente
                $mapaLote[$nombreLimpio] = strtoupper($cod);
            }
        }
    }

    if (empty($files_to_pack)) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'No se encontraron los archivos físicos PDF correspondientes al lote.'
        ]);
        exit;
    }

    // 4. Construir y ejecutar el comando de compresión 7z
    $cmd_files = implode(' ', $files_to_pack);
    $cmd = "{$exe7z} a -t7z " . escapeshellarg($temp_7z_path) . " {$cmd_files} 2>&1";

    $output = [];
    $returnCode = -1;
    exec($cmd, $output, $returnCode);

    // 5. Limpiar los archivos PDF temporales generados para la compresión
    foreach ($tmp_created_files as $tmp_file) {
        if (file_exists($tmp_file)) {
            @unlink($tmp_file);
        }
    }

    // 6. Validar compresión exitosa
    if ($returnCode !== 0 || !file_exists($temp_7z_path) || filesize($temp_7z_path) === 0) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'Error al compilar el paquete .7z: ' . implode(" ", $output),
            'comando' => $cmd
        ]);
        exit;
    }

    $ruta_documento_para_firma = '/archivos_sgd/input_' . $id_transaccion . '.7z';
} else {
    // Si es solo 1 documento, flujo directo en PDF
    $codigo = strtoupper($codigos[0]);
    $stmtV = $pdo->prepare("SELECT ruta_pdf FROM documento_versiones WHERE codigo_documento = ? ORDER BY version_nro DESC LIMIT 1");
    $stmtV->execute([$codigo]);
    $ruta_documento_para_firma = $stmtV->fetchColumn();
}

// Guardar lista de códigos y el diccionario del lote en JSON
$codigos_json = json_encode(array_values($codigos));
$mapa_lote_json = json_encode($mapaLote);

$stmt = $pdo->prepare("INSERT INTO firmas_pendientes (token_transaccion, codigo_documento, tipo_firma, cargo, signaturestyle, one_by_one, mapa_lote) VALUES (?, ?, ?, ?, ?, ?, ?)");
$stmt->execute([$id_transaccion, $codigos_json, $tipo_firma, $cargo, $signaturestyle, $oneByOne, $mapa_lote_json]);

$param_url = $baseUrl . '/params.php';

$param_temp = [
    'param_url'          => $param_url,
    'param_token'        => $id_transaccion,
    'document_extension' => $es_lote ? '7z' : 'pdf'
];

echo json_encode([
    'status'    => 'success',
    'param_b64' => base64_encode(json_encode($param_temp)),
    'port'      => '48596'
]);
