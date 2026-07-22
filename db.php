<?php
// db.php
$db_file = __DIR__ . '/db_sgd_firmas.sqlite';

try {
    $pdo = new PDO("sqlite:" . $db_file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1. Tabla estructural (Cabecera del Documento)
    $pdo->exec("CREATE TABLE IF NOT EXISTS documentos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        codigo TEXT UNIQUE,
        nombre_original TEXT,
        codigo_lote TEXT NULL,
        fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Índice para búsquedas rápidas por lote
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_codigo_lote ON documentos(codigo_lote)");

    // 2. Tabla transaccional (Historial correlativo de versiones)
    $pdo->exec("CREATE TABLE IF NOT EXISTS documento_versiones (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        codigo_documento TEXT,
        ruta_pdf TEXT,
        version_nro INTEGER DEFAULT 0,
        tipo_firma TEXT NULL,
        cargo TEXT NULL,
        fecha_firma DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // 3. Tabla para controlar los tokens de firma pendientes (incluye one_by_one)
    $pdo->exec("CREATE TABLE IF NOT EXISTS firmas_pendientes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        token_transaccion TEXT UNIQUE,
        codigo_documento TEXT,
        tipo_firma TEXT,
        cargo TEXT,
        signaturestyle INTEGER DEFAULT 1,
        one_by_one INTEGER DEFAULT 0,
        mapa_lote TEXT NULL,
        fecha_registro DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {
    die(json_encode(['status' => 'error', 'message' => 'Error de BD: ' . $e->getMessage()]));
}

// Helper para obtener la URL base del servidor de manera dinámica
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$baseUrl  = $protocol . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
