<?php
// db.php
$db_file = __DIR__ . '/db_sgd_firmas.sqlite';

try {
    $pdo = new PDO("sqlite:" . $db_file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Tabla estructural (Cabecera del Documento)
    $pdo->exec("CREATE TABLE IF NOT EXISTS documentos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        codigo TEXT UNIQUE,
        nombre_original TEXT,
        fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Tabla transaccional (Historial correlativo de versiones)
    $pdo->exec("CREATE TABLE IF NOT EXISTS documento_versiones (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        codigo_documento TEXT,
        ruta_pdf TEXT,
        version_nro INTEGER DEFAULT 0,
        tipo_firma TEXT NULL,
        cargo TEXT NULL,
        fecha_firma DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    // Añadir al final de tu db.php actual para controlar los tokens de firma pendientes
    $pdo->exec("CREATE TABLE IF NOT EXISTS firmas_pendientes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        token_transaccion TEXT UNIQUE,
        codigo_documento TEXT,
        tipo_firma TEXT,
        cargo TEXT,
        fecha_registro DATETIME DEFAULT CURRENT_TIMESTAMP
    )");


    // 2. Intenta añadir la columna (usamos un try/catch por si la columna ya existe en ejecuciones futuras)
    try {
        $pdo->exec("ALTER TABLE firmas_pendientes ADD COLUMN signaturestyle INTEGER DEFAULT 1");
    } catch (PDOException $e) {
        // Si la columna ya existe, SQLite lanzará un error. 
        // Al atraparlo aquí, evitamos que tu aplicación se detenga.
    }
} catch (Exception $e) {
    die(json_encode(['status' => 'error', 'message' => 'Error de BD: ' . $e->getMessage()]));
}

// Helper para obtener la URL base del servidor de manera dinámica
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$baseUrl = $protocol . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/';
