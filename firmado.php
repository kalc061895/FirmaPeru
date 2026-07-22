<?php
// firmado.php
require_once __DIR__ . '/db.php';

// Detectar ejecutable 7z de manera segura
$exe7z = '7z';
if (stristr(PHP_OS, 'WIN')) {
    if (file_exists('C:\Program Files\7-Zip\7z.exe')) {
        $exe7z = '"C:\Program Files\7-Zip\7z.exe"';
    } elseif (file_exists('C:\Program Files (x86)\7-Zip\7z.exe')) {
        $exe7z = '"C:\Program Files (x86)\7-Zip\7z.exe"';
    }
}

if (isset($_GET['token']) && !empty($_FILES)) {
    $token = $_GET['token'];

    $stmtFirma = $pdo->prepare("SELECT * FROM firmas_pendientes WHERE token_transaccion = ?");
    $stmtFirma->execute([$token]);
    $pendiente = $stmtFirma->fetch(PDO::FETCH_ASSOC);

    if ($pendiente) {
        $codigos  = json_decode($pendiente['codigo_documento'], true);
        $mapaLote = json_decode($pendiente['mapa_lote'] ?? '{}', true); // Diccionario [NombreLimpio.pdf => codigo_doc]
        $reason   = $pendiente['tipo_firma'];
        $role     = $pendiente['cargo'];
        $fileKey  = array_key_first($_FILES);

        $dir_archivos = __DIR__ . '/archivos_sgd/';
        if (!is_dir($dir_archivos)) {
            mkdir($dir_archivos, 0777, true);
        }

        // CASO A: RESPUESTA DE LOTE (.7z)
        if (is_array($codigos) && count($codigos) > 1) {

            // 1. Guardar permanentemente el lote firmado de retorno (Auditoría)
            $path_7z_signed = $dir_archivos . 'signed_' . $token . '.7z';
            $dir_extract    = $dir_archivos . 'extract_' . $token . '/';

            if (move_uploaded_file($_FILES[$fileKey]['tmp_name'], $path_7z_signed)) {
                if (!is_dir($dir_extract)) {
                    mkdir($dir_extract, 0777, true);
                }

                // 2. Descomprimir el paquete 7z firmado
                $cmd = "{$exe7z} x " . escapeshellarg($path_7z_signed) . " -o" . escapeshellarg($dir_extract) . " -y 2>&1";
                exec($cmd, $out, $res);

                if ($res === 0) {
                    // Escanear todos los PDFs extraídos
                    $filesExtracted = glob($dir_extract . '*.pdf');

                    foreach ($filesExtracted as $pdfPath) {
                        $nombreArchivoLocal = basename($pdfPath);
                        $codigoDoc = null;

                        // Intentar recuperar el código mediante el diccionario mapeado
                        if (isset($mapaLote[$nombreArchivoLocal])) {
                            $codigoDoc = $mapaLote[$nombreArchivoLocal];
                        } else {
                            // Fallback: Si el nombre contiene el código al inicio (ej: DOC-001_Nombre.pdf)
                            $partes = explode('_', $nombreArchivoLocal);
                            $codigoDoc = $partes[0];
                        }

                        // Verificar si el código existe en la BD
                        $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM documentos WHERE codigo = ?");
                        $stmtCheck->execute([$codigoDoc]);

                        if ($stmtCheck->fetchColumn() > 0) {
                            // Crear un nombre UUID único para la nueva versión del documento
                            $uuidName = md5(uniqid(mt_rand(), true)) . '.pdf';
                            $ruta_destino = '/archivos_sgd/' . $uuidName;

                            if (rename($pdfPath, __DIR__ . '/' . $ruta_destino)) {
                                // Incrementar número de versión e insertar en BD
                                $stmtV = $pdo->prepare("SELECT COALESCE(MAX(version_nro), 0) FROM documento_versiones WHERE codigo_documento = ?");
                                $stmtV->execute([$codigoDoc]);
                                $maxVersion = (int)$stmtV->fetchColumn();

                                $stmtInsert = $pdo->prepare("INSERT INTO documento_versiones (codigo_documento, ruta_pdf, version_nro, tipo_firma, cargo) VALUES (?, ?, ?, ?, ?)");
                                $stmtInsert->execute([$codigoDoc, $ruta_destino, $maxVersion + 1, $reason, $role]);
                            }
                        }
                        @unlink($pdfPath);
                    }

                    // Limpieza profunda de la carpeta temporal antes de eliminarla
                    array_map('unlink', glob("$dir_extract*.*"));
                    @rmdir($dir_extract);

                    // Conservar $path_7z_signed y eliminar solo el input temporal
                    @unlink($dir_archivos . 'input_' . $token . '.7z');
                }
            }
        }
        // CASO B: ARCHIVO INDIVIDUAL
        else {
            $codigo = is_array($codigos) ? $codigos[0] : $pendiente['codigo_documento'];
            $uuidName = md5(uniqid(mt_rand(), true)) . '.pdf';
            $ruta_nuevo_firmado = '/archivos_sgd/' . $uuidName;

            if (move_uploaded_file($_FILES[$fileKey]['tmp_name'], __DIR__ . '/' . $ruta_nuevo_firmado)) {
                $stmtV = $pdo->prepare("SELECT COALESCE(MAX(version_nro), 0) FROM documento_versiones WHERE codigo_documento = ?");
                $stmtV->execute([$codigo]);
                $maxVersion = (int)$stmtV->fetchColumn();

                $stmtInsert = $pdo->prepare("INSERT INTO documento_versiones (codigo_documento, ruta_pdf, version_nro, tipo_firma, cargo) VALUES (?, ?, ?, ?, ?)");
                $stmtInsert->execute([$codigo, $ruta_nuevo_firmado, $maxVersion + 1, $reason, $role]);
            }
        }

        // Eliminar el registro de firma pendiente
        $stmtDelete = $pdo->prepare("DELETE FROM firmas_pendientes WHERE token_transaccion = ?");
        $stmtDelete->execute([$token]);

        echo "OK";
    }
    exit;
}
