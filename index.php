<?php
// index.php
require_once __DIR__ . '/db.php';

// Procesar Subidas, Consultas y Búsquedas por AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');

    // 1. SUBIDA MULTIPLE DE DOCUMENTOS + CÓDIGO DE LOTE
    if ($_GET['action'] === 'subir') {
        if (!isset($_FILES['archivos_pdf']) || empty($_FILES['archivos_pdf']['name'][0])) {
            echo json_encode(['status' => 'error', 'message' => 'Debe seleccionar al menos un archivo PDF.']);
            exit;
        }

        $totalArchivos = count($_FILES['archivos_pdf']['name']);
        $registrados = [];
        $dir_archivos = __DIR__ . '/archivos_sgd/';
        if (!is_dir($dir_archivos)) mkdir($dir_archivos, 0777, true);

        // Generar un Código de Lote único
        $codigoLote = 'L-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 4));

        for ($i = 0; $i < $totalArchivos; $i++) {
            if ($_FILES['archivos_pdf']['error'][$i] === UPLOAD_ERR_OK) {
                $nombreOriginal = $_FILES['archivos_pdf']['name'][$i];

                do {
                    $codigoDoc = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 4));
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM documentos WHERE codigo = ?");
                    $stmt->execute([$codigoDoc]);
                } while ($stmt->fetchColumn() > 0);

                $uuidName = md5(uniqid(mt_rand(), true)) . '.pdf';
                $ruta_destino = '/archivos_sgd/' . $uuidName;

                if (move_uploaded_file($_FILES['archivos_pdf']['tmp_name'][$i], __DIR__ . '/' . $ruta_destino)) {
                    $stmt = $pdo->prepare("INSERT INTO documentos (codigo, codigo_lote, nombre_original) VALUES (?, ?, ?)");
                    $stmt->execute([$codigoDoc, $codigoLote, $nombreOriginal]);

                    $stmt2 = $pdo->prepare("INSERT INTO documento_versiones (codigo_documento, ruta_pdf, version_nro, tipo_firma, cargo) VALUES (?, ?, 0, 'Original', 'Creador del Archivo')");
                    $stmt2->execute([$codigoDoc, $ruta_destino]);

                    $registrados[] = [
                        'codigo' => $codigoDoc,
                        'nombre' => $nombreOriginal
                    ];
                }
            }
        }

        echo json_encode([
            'status'      => 'success',
            'codigo_lote' => $codigoLote,
            'docs'        => $registrados
        ]);
        exit;
    }

    // 2. LISTAR / BUSCAR DOCUMENTOS
    if ($_GET['action'] === 'listar') {
        try {
            $busqueda = trim($_POST['busqueda'] ?? '');

            $sql = "SELECT d.codigo, 
                           COALESCE(d.codigo_lote, 'SIN_LOTE') AS codigo_lote,
                           d.nombre_original, 
                           d.fecha_creacion, 
                           COALESCE(MAX(v.version_nro), 0) AS version_actual,
                           (SELECT v2.ruta_pdf FROM documento_versiones v2 WHERE v2.codigo_documento = d.codigo ORDER BY v2.version_nro DESC LIMIT 1) as ruta_pdf
                    FROM documentos d
                    LEFT JOIN documento_versiones v ON d.codigo = v.codigo_documento";

            $params = [];
            if (!empty($busqueda)) {
                $sql .= " WHERE d.codigo LIKE ? OR d.codigo_lote LIKE ? OR d.nombre_original LIKE ?";
                $params[] = "%{$busqueda}%";
                $params[] = "%{$busqueda}%";
                $params[] = "%{$busqueda}%";
            }

            $sql .= " GROUP BY d.codigo, d.codigo_lote, d.nombre_original, d.fecha_creacion ORDER BY d.id DESC LIMIT 200";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['status' => 'success', 'data' => $docs]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Error SQL: ' . $e->getMessage()]);
        }
        exit;
    }

    // 3. OBTENER DETALLE POR CÓDIGOS DE DOCUMENTO O CÓDIGO DE LOTE
    if ($_GET['action'] === 'buscar') {
        try {
            $input = trim($_POST['query'] ?? '');
            $codigos = $_POST['codigos'] ?? [];

            if (is_string($codigos)) $codigos = explode(',', $codigos);
            $codigos = array_filter(array_map('trim', $codigos));

            if (!empty($input) && strpos(strtoupper($input), 'L-') === 0) {
                $stmtLote = $pdo->prepare("SELECT codigo FROM documentos WHERE codigo_lote = ?");
                $stmtLote->execute([strtoupper($input)]);
                $codigos = $stmtLote->fetchAll(PDO::FETCH_COLUMN);
            }

            if (empty($codigos)) {
                echo json_encode(['status' => 'error', 'message' => 'No se encontraron documentos vinculados.']);
                exit;
            }

            $placeholders = implode(',', array_fill(0, count($codigos), '?'));
            $stmt = $pdo->prepare("SELECT d.codigo, d.codigo_lote, d.nombre_original, v.version_nro, v.ruta_pdf 
                                   FROM documentos d 
                                   JOIN documento_versiones v ON d.codigo = v.codigo_documento 
                                   WHERE d.codigo IN ($placeholders) 
                                   AND v.version_nro = (SELECT MAX(version_nro) FROM documento_versiones WHERE codigo_documento = d.codigo)");
            $stmt->execute($codigos);
            $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['status' => 'success', 'data' => $resultados]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Error SQL: ' . $e->getMessage()]);
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
            min-height: 100vh;
            font-family: system-ui, -apple-system, sans-serif;
        }

        .main-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
        }

        .iframe-container {
            width: 100%;
            height: 450px;
            border: 1px solid #ddd;
            border-radius: 8px;
            background-color: #fdfdfd;
        }

        .badge-code {
            font-size: 0.95rem;
            letter-spacing: 0.5px;
        }

        .badge-lote {
            font-size: 0.95rem;
        }

        .switch-container {
            background-color: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 10px 15px;
        }

        /* Estilos para Agrupación por Lote */
        .tr-lote-header {
            background-color: #eef2f7 !important;
            cursor: pointer;
            transition: background-color 0.2s ease;
        }

        .tr-lote-header:hover {
            background-color: #e2e8f0 !important;
        }

        .btn-toggle-lote {
            transition: transform 0.2s ease;
        }

        .btn-toggle-lote.rotated {
            transform: rotate(90deg);
        }

        .child-row {
            background-color: #ffffff;
        }

        .child-indent {
            padding-left: 2rem !important;
        }
    </style>
</head>

<body>

    <div class="container py-4">
        <!-- Cabecera -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="text-primary fw-bold mb-0">Red San Román - SGD</h4>
                <small class="text-secondary fw-semibold">Gestión de Firma Digital por Lotes Agrupados</small>
            </div>
            <button class="btn btn-primary fw-bold" data-bs-toggle="modal" data-bs-target="#modalSubirDoc">
                <i class="fa-solid fa-cloud-arrow-up me-2"></i> Cargar Pack de PDFs
            </button>
        </div>

        <!-- Panel Principal -->
        <div class="card main-card">
            <div class="card-body p-4">

                <!-- Buscador rápido por Lote o Documento -->
                <div class="row g-2 mb-3 align-items-center">
                    <div class="col-md-7">
                        <div class="input-group">
                            <span class="input-group-text bg-white"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                            <input type="text" id="txtBuscar" class="form-control" placeholder="Buscar por Código Doc, Lote (ej: L-9A2F) o Nombre..." onkeyup="filtrarDocumentos()">
                            <button class="btn btn-outline-secondary" type="button" onclick="limpiarBuscador()">Limpiar</button>
                        </div>
                    </div>
                    <div class="col-md-5 text-md-end">
                        <button id="btnFirmarSeleccionados" class="btn btn-success fw-bold d-none" onclick="prepararFirmaLote()">
                            <i class="fa-solid fa-file-signature me-2"></i> Firmar Seleccionados (<span id="cntSeleccionados">0</span>)
                        </button>
                    </div>
                </div>

                <!-- Tabla Agrupada por Lotes -->
                <div class="table-responsive">
                    <table class="table align-middle" id="tablaDocs">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 40px;">
                                    <input type="checkbox" class="form-check-input" id="chkSelectAll" onchange="toggleSelectAll(this)">
                                </th>
                                <th>Lote / Código</th>
                                <th>Detalle Documento / Pack</th>
                                <th>Versión</th>
                                <th>Fecha Carga</th>
                                <th class="text-end">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyDocumentos">
                            <!-- Se puebla dinámicamente agrupado por lotes -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL: Subir Archivos -->
    <div class="modal fade" id="modalSubirDoc" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-cloud-arrow-up me-2"></i> Registrar Pack de PDFs</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="formSubirDoc" enctype="multipart/form-data">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Seleccionar Documentos PDF</label>
                            <input type="file" name="archivos_pdf[]" class="form-control" accept="application/pdf" multiple required>
                            <div class="form-text">Si seleccionas múltiples archivos, se agruparán bajo un mismo <strong>Código de Lote</strong>.</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                        <button type="submit" class="btn btn-success fw-bold">Subir Documentos</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL: Ejecutar Firma -->
    <div class="modal fade" id="modalFirmarDoc" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white d-flex align-items-center">
                    <h5 class="modal-title fw-bold" id="txtTituloDoc">Procesar Firma Digital</h5>
                    <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-3">

                    <div id="contenedorVisor">
                        <iframe id="previewPdf" class="iframe-container mb-3" src=""></iframe>
                    </div>

                    <div id="listaLoteFirmar" class="alert alert-info d-none mb-3">
                        <h6 class="fw-bold"><i class="fa-solid fa-layer-group me-2"></i> Documentos a firmar en esta operación:</h6>
                        <ul id="ulDocsLote" class="mb-0 small"></ul>
                    </div>

                    <form id="formEjecutarFirma">
                        <input type="hidden" id="doc_codigos_hidden" name="doc_codigos">

                        <div id="wrapperCamposFirma">

                            <!-- SWITCH: Firmar Uno por Uno -->
                            <div class="mb-3" id="rowModoFirma">
                                <div class="switch-container d-flex align-items-center justify-content-between">
                                    <div>
                                        <label class="form-check-label fw-bold text-dark mb-0" for="chkOneByOne">
                                            <i class="fa-solid fa-file-signature text-primary me-2"></i> FIRMAR UNO POR UNO
                                        </label>
                                        <div class="small text-muted">
                                            Si está activado, FirmaPerú solicitará posición y confirmación individual por cada PDF.
                                        </div>
                                    </div>
                                    <div class="form-check form-switch fs-4 mb-0">
                                        <input class="form-check-input" type="checkbox" id="chkOneByOne" name="one_by_one" value="1">
                                    </div>
                                </div>
                            </div>

                            <div class="row g-2">
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold small text-muted">Razón de Firma</label>
                                    <select name="tipo_firma" class="form-select" required>
                                        <option value="" disabled selected>-- Elija la Razón --</option>
                                        <option value="Soy el autor del documento">Soy el autor del documento</option>
                                        <option value="Doy Visto Bueno">Doy Visto Bueno</option>
                                        <option value="En señal de conformidad">En señal de conformidad</option>
                                        <option value="Por encargo recibido">Por encargo recibido</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold small text-muted">Orientación Estampa</label>
                                    <select name="signaturestyle" class="form-select" required>
                                        <option value="1" selected>Horizontal</option>
                                        <option value="2">Vertical</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold small text-muted">Cargo a Estampar</label>
                                    <input type="text" name="cargo" class="form-control" style="text-transform: uppercase;" placeholder="EJ. JEFE DE UNIDAD" required>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end gap-2 mt-3 pt-2 border-top">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                            <button type="submit" class="btn btn-success fw-bold">
                                <i class="fa-solid fa-pen-nib me-2"></i> Firmar con FirmaPerú
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://apps.firmaperu.gob.pe/web/clienteweb/firmaperu.min.js"></script>

    <script>
        const modalSubir = new bootstrap.Modal(document.getElementById('modalSubirDoc'));
        const modalFirmar = new bootstrap.Modal(document.getElementById('modalFirmarDoc'));
        var jqFirmaPeru = jQuery.noConflict(true);
        let timerBusqueda = null;

        document.addEventListener('DOMContentLoaded', () => cargarDocumentos());

        function cargarDocumentos(busqueda = '') {
            let formData = new FormData();
            formData.append('busqueda', busqueda);

            fetch('?action=listar', {
                    method: 'POST',
                    body: formData
                })
                .then(async res => {
                    const text = await res.text();
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        throw new Error("Respuesta no válida del servidor.");
                    }
                })
                .then(res => {
                    if (res.status === 'success') {
                        const tbody = document.getElementById('tbodyDocumentos');
                        tbody.innerHTML = '';

                        if (res.data.length === 0) {
                            tbody.innerHTML = `<tr><td colspan="6" class="text-center text-muted py-4">No se encontraron registros.</td></tr>`;
                            actualizarSeleccion();
                            return;
                        }

                        // Agrupar la data por Código de Lote
                        const lotes = {};
                        res.data.forEach(doc => {
                            const keyLote = doc.codigo_lote !== 'SIN_LOTE' ? doc.codigo_lote : 'SIN_LOTE';
                            if (!lotes[keyLote]) {
                                lotes[keyLote] = [];
                            }
                            lotes[keyLote].push(doc);
                        });

                        let loteIndex = 0;
                        for (const [codigoLote, items] of Object.entries(lotes)) {
                            loteIndex++;

                            // A) Si tiene Lote asignado
                            if (codigoLote !== 'SIN_LOTE') {
                                const targetId = `lote_collapse_${loteIndex}`;
                                const codigosDelLote = items.map(d => d.codigo).join(',');

                                // Fila Padre (Lote)
                                tbody.innerHTML += `
                                    <tr class="tr-lote-header" onclick="toggleAcordeon('${targetId}', this)">
                                        <td>
                                            <input type="checkbox" class="form-check-input chk-lote-group" 
                                                   data-target-group="${targetId}" 
                                                   onchange="toggleSelectLote(this, '${targetId}')" 
                                                   onclick="event.stopPropagation()">
                                        </td>
                                        <td>
                                            <i class="fa-solid fa-chevron-right me-2 text-primary btn-toggle-lote"></i>
                                            <span class="badge bg-info text-dark badge-lote fw-bold">
                                                <i class="fa-solid fa-layer-group me-1"></i>${codigoLote}
                                            </span>
                                        </td>
                                        <td>
                                            <strong>Pack de ${items.length} documento(s)</strong>
                                            <small class="text-muted d-block">${items[0].nombre_original} ${items.length > 1 ? 'y otros...' : ''}</small>
                                        </td>
                                        <td><span class="badge bg-light text-dark border">Grupo</span></td>
                                        <td class="small text-muted">${items[0].fecha_creacion || '-'}</td>
                                        <td class="text-end" onclick="event.stopPropagation()">
                                            <button class="btn btn-sm btn-info fw-bold me-1 text-white" onclick="firmarLoteCompleto('${codigoLote}')" title="Firmar lote completo">
                                                <i class="fa-solid fa-pen-nib me-1"></i> Firmar Lote
                                            </button>
                                        </td>
                                    </tr>
                                `;

                                // Filas Hijas (Documentos individuales del Lote)
                                items.forEach(doc => {
                                    tbody.innerHTML += `
                                        <tr class="child-row ${targetId} d-none">
                                            <td class="child-indent">
                                                <input type="checkbox" class="form-check-input chk-doc chk-item-${targetId}" 
                                                       value="${doc.codigo}" 
                                                       onchange="actualizarSeleccion()">
                                            </td>
                                            <td class="ps-4">
                                                <i class="fa-solid fa-arrow-turn-up fa-rotate-90 text-muted me-2"></i>
                                                <span class="badge bg-primary badge-code">${doc.codigo}</span>
                                            </td>
                                            <td class="fw-semibold text-secondary">${doc.nombre_original}</td>
                                            <td><span class="badge bg-secondary">v${doc.version_actual}</span></td>
                                            <td class="small text-muted">${doc.fecha_creacion || '-'}</td>
                                            <td class="text-end">
                                                <button class="btn btn-sm btn-outline-primary me-1" onclick="verIndividual('${doc.codigo}')">
                                                    <i class="fa-solid fa-eye"></i> Ver / Firmar
                                                </button>
                                                <a href="${doc.ruta_pdf}" target="_blank" class="btn btn-sm btn-outline-secondary">
                                                    <i class="fa-solid fa-download"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    `;
                                });
                            }
                            // B) Si son documentos sueltos (Sin Lote)
                            else {
                                items.forEach(doc => {
                                    tbody.innerHTML += `
                                        <tr>
                                            <td>
                                                <input type="checkbox" class="form-check-input chk-doc" value="${doc.codigo}" onchange="actualizarSeleccion()">
                                            </td>
                                            <td><span class="badge bg-primary badge-code">${doc.codigo}</span></td>
                                            <td class="fw-semibold">${doc.nombre_original}</td>
                                            <td><span class="badge bg-secondary">v${doc.version_actual}</span></td>
                                            <td class="small text-muted">${doc.fecha_creacion || '-'}</td>
                                            <td class="text-end">
                                                <button class="btn btn-sm btn-outline-primary me-1" onclick="verIndividual('${doc.codigo}')">
                                                    <i class="fa-solid fa-eye"></i> Ver / Firmar
                                                </button>
                                                <a href="${doc.ruta_pdf}" target="_blank" class="btn btn-sm btn-outline-secondary">
                                                    <i class="fa-solid fa-download"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    `;
                                });
                            }
                        }

                        actualizarSeleccion();
                    }
                })
                .catch(err => console.error(err));
        }

        // Mostrar / Ocultar Filas Hijas del Lote
        function toggleAcordeon(targetClass, trHeader) {
            const filasHijas = document.querySelectorAll(`.${targetClass}`);
            const icono = trHeader.querySelector('.btn-toggle-lote');

            filasHijas.forEach(fila => {
                fila.classList.toggle('d-none');
            });

            if (icono) {
                icono.classList.toggle('rotated');
            }
        }

        // Checkbox del Lote Padre marca todos sus documentos hijos
        function toggleSelectLote(masterChk, targetClass) {
            const checkboxesHijos = document.querySelectorAll(`.chk-item-${targetClass}`);
            checkboxesHijos.forEach(chk => chk.checked = masterChk.checked);
            actualizarSeleccion();
        }

        function firmarLoteCompleto(codigoLote) {
            let formData = new FormData();
            formData.append('query', codigoLote);

            fetch('?action=buscar', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        const codigos = data.data.map(d => d.codigo);
                        abrirModalFirmaLote(data.data, codigos, `Lote ${codigoLote}`);
                    } else {
                        Swal.fire('Atención', data.message, 'warning');
                    }
                });
        }

        function filtrarDocumentos() {
            clearTimeout(timerBusqueda);
            const val = document.getElementById('txtBuscar').value.trim();
            timerBusqueda = setTimeout(() => cargarDocumentos(val), 300);
        }

        function limpiarBuscador() {
            document.getElementById('txtBuscar').value = '';
            cargarDocumentos('');
        }

        function toggleSelectAll(master) {
            document.querySelectorAll('.chk-doc, .chk-lote-group').forEach(chk => chk.checked = master.checked);
            // Asegurar que si marca el general, marque todos los hijos aunque estén ocultos
            document.querySelectorAll('.chk-doc').forEach(chk => chk.checked = master.checked);
            actualizarSeleccion();
        }

        function actualizarSeleccion() {
            const seleccionados = Array.from(document.querySelectorAll('.chk-doc:checked')).map(c => c.value);
            const btn = document.getElementById('btnFirmarSeleccionados');
            document.getElementById('cntSeleccionados').innerText = seleccionados.length;

            if (seleccionados.length > 0) {
                btn.classList.remove('d-none');
            } else {
                btn.classList.add('d-none');
            }
        }

        function verIndividual(codigo) {
            let formData = new FormData();
            formData.append('codigos[]', codigo);

            fetch('?action=buscar', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success' && data.data.length > 0) {
                        const doc = data.data[0];
                        document.getElementById('txtTituloDoc').innerText = 'Documento: ' + doc.nombre_original;
                        document.getElementById('doc_codigos_hidden').value = doc.codigo;

                        document.getElementById('contenedorVisor').classList.remove('d-none');
                        document.getElementById('listaLoteFirmar').classList.add('d-none');

                        document.getElementById('rowModoFirma').classList.add('d-none');
                        document.getElementById('chkOneByOne').checked = false;

                        document.getElementById('previewPdf').src = doc.ruta_pdf + '?t=' + new Date().getTime();

                        modalFirmar.show();
                    }
                });
        }

        function prepararFirmaLote() {
            const codigos = Array.from(document.querySelectorAll('.chk-doc:checked')).map(c => c.value);
            if (codigos.length === 0) return;

            let formData = new FormData();
            codigos.forEach(c => formData.append('codigos[]', c));

            fetch('?action=buscar', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        abrirModalFirmaLote(data.data, codigos, `Selección Manual (${codigos.length} archivos)`);
                    }
                });
        }

        function abrirModalFirmaLote(listaDocs, listaCodigos, tituloLabel) {
            document.getElementById('txtTituloDoc').innerText = `Firma Masiva - ${tituloLabel}`;
            document.getElementById('doc_codigos_hidden').value = listaCodigos.join(',');

            document.getElementById('contenedorVisor').classList.add('d-none');
            const ul = document.getElementById('ulDocsLote');
            ul.innerHTML = '';
            listaDocs.forEach(d => {
                ul.innerHTML += `<li><strong>[${d.codigo}]</strong> ${d.nombre_original} (v${d.version_nro})</li>`;
            });
            document.getElementById('listaLoteFirmar').classList.remove('d-none');

            document.getElementById('rowModoFirma').classList.remove('d-none');
            document.getElementById('chkOneByOne').checked = false;

            modalFirmar.show();
        }

        document.getElementById('formSubirDoc').addEventListener('submit', function(e) {
            e.preventDefault();
            Swal.fire({
                title: 'Subiendo archivos...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            fetch('?action=subir', {
                    method: 'POST',
                    body: new FormData(this)
                })
                .then(async res => {
                    const text = await res.text();
                    try {
                        return JSON.parse(text);
                    } catch (err) {
                        throw new Error("Respuesta no válida del servidor.");
                    }
                })
                .then(data => {
                    if (data.status === 'success') {
                        this.reset();
                        modalSubir.hide();
                        cargarDocumentos();

                        let htmlCodes = data.docs.map(d => `<div class="mb-1"><strong>[${d.codigo}]</strong> ${d.nombre}</div>`).join('');

                        Swal.fire({
                            icon: 'success',
                            title: '¡Pack Registrado con Éxito!',
                            html: `
                                <div class="alert alert-info text-start py-2">
                                    <strong>Código de Lote Asignado:</strong> <span class="badge bg-primary fs-6">${data.codigo_lote}</span>
                                </div>
                                <p class="small text-muted text-start">Puedes expandir el grupo en la tabla para ver o firmar sus archivos.</p>
                                <div class="text-start bg-light p-3 rounded border" style="max-height: 180px; overflow-y: auto;">${htmlCodes}</div>
                            `,
                            confirmButtonText: 'Entendido'
                        });
                    } else {
                        Swal.fire('Error', data.message, 'error');
                    }
                })
                .catch(err => Swal.fire('Error', err.message, 'error'));
        });

        // Ejecutar Proceso con FirmaPerú
        document.getElementById('formEjecutarFirma').addEventListener('submit', function(e) {
            e.preventDefault();

            let formData = new FormData(this);
            if (!document.getElementById('chkOneByOne').checked) {
                formData.set('one_by_one', '0');
            } else {
                formData.set('one_by_one', '1');
            }

            fetch('guardar_firma.php', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        signatureInit();
                        startSignature(data.port, data.param_b64);
                    } else {
                        Swal.fire('Error', data.message || 'No se pudieron procesar los parámetros.', 'error');
                    }
                });
        });

        function signatureInit() {
            Swal.fire({
                title: 'Lanzando FirmaPerú...',
                text: 'Por favor, confirme la firma en la aplicación cliente FirmaPerú.',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });
        }

        function signatureOk() {
            modalFirmar.hide();
            Swal.fire({
                icon: 'success',
                title: '¡Proceso de Firma Completado!',
                text: 'Se han actualizado las versiones firmadas de los documentos seleccionados.'
            }).then(() => {
                cargarDocumentos();
            });
        }

        function signatureCancel() {
            Swal.fire('Operación Cancelada', 'No se aplicó ninguna firma.', 'info');
        }
    </script>
</body>

</html>