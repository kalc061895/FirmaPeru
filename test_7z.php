<?php
// test_7z.php - Script de validación de 7-Zip para PHP

header('Content-Type: text/html; charset=utf-8');
echo "<h2>Diagnóstico de Integración 7-Zip / PHP</h2>";

// 1. Validar si exec() está habilitado
echo "<h3>1. Verificación de exec():</h3>";
if (function_exists('exec')) {
    echo "<span style='color:green;'>✔ La función exec() está HABILITADA en php.ini.</span><br>";
} else {
    echo "<span style='color:red;'>✘ La función exec() está DESHABILITADA en php.ini. Debes removerla de disable_functions.</span><br>";
    exit;
}

// 2. Probar si el comando '7z' o '7za' está en el PATH del sistema
echo "<h3>2. Verificación del binario 7z:</h3>";
$output = [];
$return_var = -1;

// Probamos ejecutar 7z
exec('7z 2>&1', $output, $return_var);

if ($return_var === 0) {
    echo "<span style='color:green;'>✔ El comando '7z' está instalado y accesible en el PATH del sistema.</span><br>";
    echo "<pre style='background:#f4f4f4; p-2;'>" . htmlspecialchars(implode("\n", array_slice($output, 0, 5))) . "</pre>";
} else {
    echo "<span style='color:orange;'>⚠ El comando '7z' global falló (Código: $return_var). Probando ruta alternativa...</span><br>";

    // Si estás en Windows con XAMPP/Laragon, podemos probar la ruta directa por defecto:
    $rutaWindows = '"C:\\Program Files (x86)\\7-Zip\\7z.exe"';
    $outputWin = [];
    $returnWin = -1;
    exec("$rutaWindows 2>&1", $outputWin, $returnWin);

    if ($returnWin === 0) {
        echo "<span style='color:green;'>✔ Encontrado en ruta fija de Windows: <code>$rutaWindows</code></span><br>";
        echo "<small>Sugerencia: Usa esta ruta directa en tu código PHP para invocar el comando.</small><br>";
    } else {
        echo "<span style='color:red;'>✘ No se pudo encontrar 7-Zip. Instálalo o agrégalo al PATH de las variables de entorno del sistema.</span><br>";
    }
}

// 3. Prueba de creación real de paquete .7z
echo "<h3>3. Prueba de compresión de archivo real:</h3>";
$dirTest = __DIR__ . '/test_7z_temp/';
if (!is_dir($dirTest)) mkdir($dirTest, 0777, true);

// Crear un archivo TXT de prueba
$fileTest = $dirTest . 'prueba.txt';
file_put_contents($fileTest, 'Contenido de prueba para empaquetado 7z');

$zipTarget = $dirTest . 'archivo_prueba.7z';
if (file_exists($zipTarget)) @unlink($zipTarget);

$exe7z = '7z'; // Fallback por defecto (Linux o Windows con PATH configurado)
$winPath1 = 'C:\Program Files\7-Zip\7z.exe';
$winPath2 = 'C:\Program Files (x86)\7-Zip\7z.exe';

if (stristr(PHP_OS, 'WIN')) {
    if (file_exists($winPath1)) {
        $exe7z = '"' . $winPath1 . '"';
    } elseif (file_exists($winPath2)) {
        $exe7z = '"' . $winPath2 . '"';
    }
}


// Comando de compresión
$cmd = "{$exe7z} a -t7z \"{$zipTarget}\" \"{$fileTest}\" 2>&1";

$outZip = [];
$resZip = -1;
exec($cmd, $outZip, $resZip);

if (file_exists($zipTarget) && filesize($zipTarget) > 0) {
    echo "<span style='color:green;'>✔ ¡PRUEBA EXITOSA! El archivo .7z se creó correctamente en: <code>$zipTarget</code></span><br>";
    // Limpieza
    unlink($fileTest);
    unlink($zipTarget);
    rmdir($dirTest);
} else {
    echo "<span style='color:red;'>✘ FALLÓ la creación del .7z.</span><br>";
    echo "<strong>Comando ejecutado:</strong> <code>$cmd</code><br>";
    echo "<strong>Salida del comando (Error):</strong><pre style='background:#fff0f0; padding:10px; border:1px solid red;'>" . htmlspecialchars(implode("\n", $outZip)) . "</pre>";
}
