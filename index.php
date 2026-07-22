<?php
// index.php
require_once __DIR__ . '/db.php';

// Procesar Subidas de Documento Original por POST Ajax
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])) {
    header('Content-Type: application/json');

    if ($_GET['action'] === 'subir') {
        $nombre = $_POST['nombre_archivo'] ?? 'Documento sin título';

        if (!isset($_FILES['archivo_pdf']) || $_FILES['archivo_pdf']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['status' => 'error', 'message' => 'Error al cargar el archivo PDF original.']);
            exit;
        }

        // Generar clave única alfa-numérica de 4 dígitos
        do {
            $codigo = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 4));
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM documentos WHERE codigo = ?");
            $stmt->execute([$codigo]);
        } while ($stmt->fetchColumn() > 0);

        $dir_archivos = __DIR__ . '/archivos_sgd/';
        if (!is_dir($dir_archivos)) mkdir($dir_archivos, 0777, true);

        // El documento original también se enmascara con un UUID por seguridad
        $uuidName = md5(uniqid(mt_rand(), true)) . '.pdf';
        $ruta_destino = 'archivos_sgd/' . $uuidName;

        if (move_uploaded_file($_FILES['archivo_pdf']['tmp_name'], __DIR__ . '/' . $ruta_destino)) {
            $stmt = $pdo->prepare("INSERT INTO documentos (codigo, nombre_original) VALUES (?, ?)");
            $stmt->execute([$codigo, $nombre]);

            $stmt2 = $pdo->prepare("INSERT INTO documento_versiones (codigo_documento, ruta_pdf, version_nro, tipo_firma, cargo) VALUES (?, ?, 0, 'Original', 'Creador del Archivo')");
            $stmt2->execute([$codigo, $ruta_destino]);

            echo json_encode(['status' => 'success', 'codigo' => $codigo]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Error al mover el archivo al almacén.']);
        }
        exit;
    }

    if ($_GET['action'] === 'buscar') {
        $codigo = strtoupper($_POST['codigo'] ?? '');

        $stmt = $pdo->prepare("SELECT * FROM documentos WHERE codigo = ?");
        $stmt->execute([$codigo]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($doc) {
            // CRITICAL: Seleccionamos de forma estricta la ULTIMA versión registrada
            $stmtV = $pdo->prepare("SELECT * FROM documento_versiones WHERE codigo_documento = ? ORDER BY version_nro DESC LIMIT 1");
            $stmtV->execute([$codigo]);
            $ultimaVersion = $stmtV->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'nombre' => $doc['nombre_original'],
                'codigo' => $doc['codigo'],
                'data'   => $ultimaVersion
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'El código ingresado no existe en el sistema.']);
        }
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Firma Digital SGD - Red de Salud San Román</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body {
            background-color: #f4f6f9;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: system-ui, -apple-system, sans-serif;
        }

        .btn-add-floating {
            position: fixed;
            top: 20px;
            right: 20px;
            width: 55px;
            height: 55px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.15);
            z-index: 1050;
        }

        .search-card {
            width: 100%;
            max-width: 440px;
            border: none;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
        }

        .input-code {
            text-transform: uppercase;
            text-align: center;
            font-size: 26px;
            letter-spacing: 6px;
            font-weight: bold;
        }

        .iframe-container {
            width: 100%;
            height: 520px;
            border: 1px solid #ddd;
            border-radius: 8px;
            background-color: #fdfdfd;
        }
    </style>
</head>

<body>

    <button class="btn btn-primary btn-add-floating" data-bs-toggle="modal" data-bs-target="#modalSubirDoc" title="Registrar Documento">
        <i class="fa-solid fa-plus"></i>
    </button>

    <div class="container p-3">
        <div class="card search-card mx-auto">
            <div class="card-body p-4 text-center">
                <h5 class="text-secondary fw-bold mb-1">Firma Digital</h5>
                <h4 class="text-primary fw-bold mb-4">Red San Román - SGD</h4>

                <form id="formBuscarCodigo">
                    <div class="mb-4">
                        <label for="codigo_busqueda" class="form-label text-muted fw-semibold small">INGRESE CÓDIGO DE CONTROL</label>
                        <input type="text" id="codigo_busqueda" class="form-control input-code" maxlength="4" placeholder="XXXX" required autocomplete="off">
                    </div>
                    <button type="submit" class="btn btn-primary w-100 py-2 fw-bold">
                        <i class="fa-solid fa-file-magnifying-glass me-2"></i> Consultar Última Versión
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalSubirDoc" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered ">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-cloud-arrow-up me-2"></i> Registrar PDF en Sistema</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="formSubirDoc" enctype="multipart/form-data">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Descripción o Nombre de Control</label>
                            <input type="text" name="nombre_archivo" class="form-control" placeholder="Ej. Proveído N° 450-2026-RED-SR" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Seleccionar Documento PDF</label>
                            <input type="file" name="archivo_pdf" class="form-control" accept="application/pdf" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                        <button type="submit" class="btn btn-success fw-bold">Subir Documento</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalFirmarDoc" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-xl modal-fullscreen-lg-down">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white d-flex align-items-center">
                    <h5 class="modal-title fw-bold" id="txtTituloDoc">Visualizador</h5>
                    <span id="badgeVersion" class="badge bg-warning text-dark ms-3 fw-bold">Versión</span>
                    <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-3">
                    <iframe id="previewPdf" class="iframe-container mb-3" src=""></iframe>

                    <form id="formEjecutarFirma">
                        <input type="hidden" id="doc_codigo" name="doc_codigo">

                        <div id="wrapperCamposFirma">
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold small text-muted">Razón de Firma (Firma Digital)</label>
                                    <select name="tipo_firma" class="form-select" required>
                                        <option value="" disabled selected>-- Elija la Razón --</option>
                                        <option value="Soy el autor del documento">Soy el autor del documento</option>
                                        <option value="Doy Visto Bueno">Doy Visto Bueno</option>
                                        <option value="En señal de conformidad">En señal de conformidad</option>
                                        <option value="Por encargo recibido">Por encargo recibido</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold small text-muted">Orientación</label>
                                    <select name="signaturestyle" class="form-select" required>

                                        <option value="1" selected>Horizontal </option>
                                        <option value="2" >Vertical </option>

                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold small text-muted">Cargo a Estampar</label>
                                    <input type="text" name="cargo" class="form-control" style="text-transform: uppercase;" placeholder="EJ. JEFE DE UNIDAD" required>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end gap-2 mt-3 pt-2 border-top">
                            <button type="button" id="btnDescargarPdf" class="btn btn-primary">
                                <i class="bi bi-download"></i> Descargar PDF
                            </button>
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                            <button type="submit" class="btn btn-success fw-bold">Firmar con FirmaPerú</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://apps.firmaperu.gob.pe/web/clienteweb/firmaperu.min.js"></script>
    <div id="addComponent" style="display:none;"></div>

    <script>
        const modalSubir = new bootstrap.Modal(document.getElementById('modalSubirDoc'));
        const modalFirmar = new bootstrap.Modal(document.getElementById('modalFirmarDoc'));

        // Callbacks de FirmaPerú (Requeridos obligatoriamente de forma global)
        var jqFirmaPeru = jQuery.noConflict(true);

        function signatureInit() {
            Swal.fire({
                title: 'Lanzando FirmaPerú...',
                text: 'Por favor, ejecute y acepte la firma digital en el software local de RENIEC.',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
        }

        function signatureOk() {
            const codigo = document.getElementById('doc_codigo').value;

            // Re-consultar el estado al backend para verificar la nueva versión guardada
            let formData = new FormData();
            formData.append('codigo', codigo);

            fetch('?action=buscar', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        // Cargar el visor con el nuevo archivo UUID creado sin caché de navegador
                        document.getElementById('previewPdf').src = data.data.ruta_pdf + '?t=' + new Date().getTime();
                        document.getElementById('badgeVersion').innerText = "Versión Actual: " + data.data.version_nro;

                        Swal.fire({
                            icon: 'success',
                            title: '¡Documento Firmado!',
                            text: 'Se guardó con éxito una nueva versión del archivo en el historial.',
                        }).then(() => {
                            //modalFirmar.hide();
                            document.getElementById('formBuscarCodigo').reset();
                        });
                    }
                });
        }

        function signatureCancel() {
            Swal.fire('Operación Cancelada', 'No se ha aplicado ningún cambio.', 'info');
        }

        // CONTROLADOR: Guardar original inicial (Versión 0)
        document.getElementById('formSubirDoc').addEventListener('submit', function(e) {
            e.preventDefault();
            Swal.fire({
                title: 'Procesando archivo...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            fetch('?action=subir', {
                    method: 'POST',
                    body: new FormData(this)
                })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        this.reset();
                        modalSubir.hide();
                        Swal.fire({
                            icon: 'success',
                            title: '¡Registro Exitoso!',
                            html: `<p>Use y distribuya este código único para el firmado correlativo:</p>
                               <h1 class="display-4 fw-bold text-success bg-light p-2 rounded border">${data.codigo}</h1>`,
                            confirmButtonText: 'Entendido',
                            allowOutsideClick: false
                        });
                    } else {
                        Swal.fire('Error', data.message, 'error');
                    }
                });
        });

        // CONTROLADOR: Buscar la última versión guardada bajo el ID de 4 dígitos
        document.getElementById('formBuscarCodigo').addEventListener('submit', function(e) {
            e.preventDefault();
            let formData = new FormData();
            formData.append('codigo', document.getElementById('codigo_busqueda').value);

            fetch('?action=buscar', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        document.getElementById('txtTituloDoc').innerText = data.nombre;
                        document.getElementById('doc_codigo').value = data.codigo;
                        document.getElementById('previewPdf').src = data.data.ruta_pdf + '?t=' + new Date().getTime();
                        document.getElementById('badgeVersion').innerText = "Versión: " + data.data.version_nro;

                        document.getElementById('formEjecutarFirma').reset();
                        modalFirmar.show();
                    } else {
                        Swal.fire('Atención', data.message, 'warning');
                    }
                });
        });

        // CONTROLADOR: Lanzar firma mediante AJAX cruzado a guardar_firma.php
        document.getElementById('formEjecutarFirma').addEventListener('submit', function(e) {
            e.preventDefault();
            fetch('guardar_firma.php', {
                    method: 'POST',
                    body: new FormData(this)
                })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        signatureInit();
                        // Ejecuta la función del script oficial de RENIEC pasándole puerto y el Base64 generado
                        startSignature(data.port, data.param_b64);
                    } else {
                        Swal.fire('Error', 'No se pudieron procesar los parámetros.', 'error');
                    }
                });

        });

        document.getElementById('btnDescargarPdf').onclick = function() {
            const pdfUrl = document.getElementById('previewPdf').src;

            // Creamos un enlace temporal en memoria
            const link = document.createElement('a');
            link.href = pdfUrl;
            const nombreArchivo = document.getElementById('txtTituloDoc').innerText.replace(/\s+/g, '_') + '.pdf';
            link.download = nombreArchivo; // Nombre con el que se descargará
            link.target = '_blank';

            // Simulamos el clic y lo eliminamos
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        };

    </script>
</body>

</html>