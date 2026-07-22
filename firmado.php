<?php
// firmado.php
require_once __DIR__ . '/db.php';

if (isset($_GET['token']) && !empty($_FILES)) {
    $token = $_GET['token'];

    // 1. Recuperar la información guardada antes de la firma
    $stmtFirma = $pdo->prepare("SELECT * FROM firmas_pendientes WHERE token_transaccion = ?");
    $stmtFirma->execute([$token]);
    $pendiente = $stmtFirma->fetch(PDO::FETCH_ASSOC);

    if ($pendiente) {
        $codigo = $pendiente['codigo_documento'];
        $reason = $pendiente['tipo_firma'];
        $role   = $pendiente['cargo'];

        $fileKey = array_key_first($_FILES);
        $dir_archivos = __DIR__ . '/archivos_sgd/';
        if (!is_dir($dir_archivos)) mkdir($dir_archivos, 0777, true);

        // 2. Generar el nombre de archivo totalmente único (UUID)
        $uuidName = md5(uniqid(mt_rand(), true)) . '.pdf';
        $ruta_nuevo_firmado = 'archivos_sgd/' . $uuidName;

        if (move_uploaded_file($_FILES[$fileKey]['tmp_name'], __DIR__ . '/' . $ruta_nuevo_firmado)) {
            
            // 3. Calcular la versión consecutiva
            $stmtV = $pdo->prepare("SELECT MAX(version_nro) FROM documento_versiones WHERE codigo_documento = ?");
            $stmtV->execute([$codigo]);
            $maxVersion = (int)$stmtV->fetchColumn();
            $nuevaVersionNro = $maxVersion + 1;

            // 4. Insertar el nuevo documento firmado en el historial
            $stmtInsert = $pdo->prepare("INSERT INTO documento_versiones (codigo_documento, ruta_pdf, version_nro, tipo_firma, cargo) VALUES (?, ?, ?, ?, ?)");
            $stmtInsert->execute([$codigo, $ruta_nuevo_firmado, $nuevaVersionNro, $reason, $role]);
            
            // 5. Limpieza opcional: Remover la firma pendiente ya procesada
            $stmtDelete = $pdo->prepare("DELETE FROM firmas_pendientes WHERE token_transaccion = ?");
            $stmtDelete->execute([$token]);

            echo "OK";
        }
    }
    exit;
}