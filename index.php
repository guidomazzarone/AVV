<?php
/**
 * index.php - Gestión de precios mediante carga de CSV y generación de listados/informes.
 *
 * Flujo:
 *  1. Subida de CSV
 *  2. Previsualización de las primeras filas
 *  3. Menú de operaciones: generar Excel, PDF, informe o aplicar cambios
 *  4. Confirmar aplicación inmediata o programada
 *  5. Listado y gestión de programaciones existentes
 *
 * Tecnologías:
 *  - PHP con PDO
 *  - MySQL
 *  - Bootstrap 5 para estilos
 *  - FPDF para generación de PDF (requiere '../src/fpdf/fpdf.php')
 *  - SSE (Server-Sent Events) para barra de progreso
 */

session_start();

// --------------------------------------------------
// 1. CONFIGURACIÓN INICIAL: manejo de errores y logs
// --------------------------------------------------
// Mostrar errores en pantalla (solo entorno desarrollo)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Registrar errores en un fichero dentro de uploads/
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/uploads/php-error.log');
// Crear carpeta si no existe
if (!is_dir(__DIR__ . '/uploads')) {
    mkdir(__DIR__ . '/uploads', 0755, true);
}
// Inicializar log si no existe
if (!file_exists(__DIR__ . '/uploads/php-error.log')) {
    file_put_contents(__DIR__ . '/uploads/php-error.log', "=== Nuevo log ===\n", FILE_APPEND);
}

// --------------------------------------------------
// 2. CONSTANTES: credenciales y rutas fijas
// --------------------------------------------------
define('SECRET_KEY', 'sertalperlas2764');           // Clave para autorizar acciones críticas
define('UPLOAD_DIR', __DIR__ . '/uploads/');       // Carpeta donde se guardan CSV subidos
$fpdfPath = __DIR__ . '/../src/fpdf/fpdf.php';     // Ruta relativa a FPDF

// Verificar y cargar FPDF
if (!file_exists($fpdfPath)) {
    die("<h1>Error fatal</h1><pre>No se encuentra FPDF en: $fpdfPath</pre>");
}
require_once $fpdfPath;

// --------------------------------------------------
// 3. DATOS DE CONEXIÓN a la base de datos MySQL
// --------------------------------------------------
$dsn    = 'mysql:host=168.181.185.142;dbname=sayt_avv;charset=utf8';
$dbUser = 'guido';
$dbPass = 'kI48Te';

// --------------------------------------------------
// 4. DEFINICIÓN DE COLUMNAS OBLIGATORIAS en el CSV
// --------------------------------------------------
$required = ['producto_id'];
for ($i = 1; $i <= 11; $i++) {
    $required[] = "lista_$i";  // listas de precio 1..11
}
$required[] = 'promocion';      // columna de promo

// --------------------------------------------------
// 5. Detección de la acción solicitada por GET/POST
// --------------------------------------------------
$action = $_REQUEST['action'] ?? 'form';  // default: mostrar formulario de subida
$error  = '';                           // para capturar excepciones
$data   = [];                           // estructura para pasar datos a las vistas

try {
    switch ($action) {
        /**
         * 5.1 SUBIR CSV
         */
        case 'upload':
            // Validar archivo en $_FILES
            if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Error al subir archivo.');
            }
            $name   = basename($_FILES['file']['name']);
            $target = UPLOAD_DIR . $name;
            // Mover archivo temporal a destino
            if (!move_uploaded_file($_FILES['file']['tmp_name'], $target)) {
                throw new Exception('No se pudo mover el archivo subido.');
            }
            // Redirigir a vista de previsualización
            header('Location:?action=preview&file=' . urlencode($name));
            exit;

        /**
         * 5.2 PREVISUALIZAR CSV
         */
        case 'preview':
            $file = UPLOAD_DIR . ($_GET['file'] ?? '');
            if (!file_exists($file)) throw new Exception('Archivo no encontrado.');

            // Abrir fichero y detectar delimitador
            $fp    = fopen($file, 'r');
            $first = fgets($fp);
            rewind($fp);
            $delim = (substr_count($first, ';') > substr_count($first, ',')) ? ';' : ',';
            $data['delimiter'] = $delim;

            // Leer encabezado y comparar con columnas requeridas
            $header          = fgetcsv($fp, 0, $delim);
            $data['missing'] = array_diff($required, array_map('strtolower', $header));

            // Leer primeras 8 filas para preview
            $preview = [];
            for ($i = 0; $i < 8 && ($row = fgetcsv($fp, 0, $delim)) !== false; $i++) {
                $preview[] = $row;
            }
            fclose($fp);

            $data['preview'] = $preview;
            $data['file']    = basename($file);
            break;

        /**
         * 5.3 MENÚ PRINCIPAL tras subir/previsualizar
         */
        case 'menu':
            $file = UPLOAD_DIR . ($_GET['file'] ?? '');
            if (!file_exists($file)) throw new Exception('Archivo no encontrado.');
            $data['file'] = basename($file);
            break;

        /**
         * 5.4 CONFIRMAR APLICACIÓN INMEDIATA
         */
        case 'confirm_apply_now':
            $file = $_GET['file'] ?? '';
            $path = UPLOAD_DIR . $file;
            if (!file_exists($path)) throw new Exception("Archivo no encontrado: $file");

            // Contar líneas (sin encabezado)
            $total = 0;
            $fh    = fopen($path, 'r');
            $first = fgets($fh);
            rewind($fh);
            $d = (substr_count($first, ';') > substr_count($first, ',')) ? ';' : ',';
            fgetcsv($fh, 0, $d); // saltar encabezado
            while (fgetcsv($fh, 0, $d) !== false) $total++;
            fclose($fh);

            // Mostrar vista de confirmación con Bootstrap
            echo <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Confirmar Actualización</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
</head>
<body class="bg-light">
  <div class="container py-5">
    <div class="card mx-auto" style="max-width:500px;">
      <div class="card-body text-center">
        <h5 class="card-title mb-4">Aplicar cambios <strong>inmediatos</strong>?</h5>
        <p><strong>Archivo:</strong> {$file}</p>
        <p><strong>Registros:</strong> {$total}</p>
        <form method="post" action="?action=execute&accion=apply_now&file={$file}">
          <div class="mb-3 form-floating">
            <input type="password" name="secret_key" id="secret_key" class="form-control" placeholder="Clave secreta" required>
            <label for="secret_key">Clave secreta</label>
          </div>
          <div class="d-flex justify-content-center gap-3">
            <button type="button" onclick="location.href='?action=menu&file={$file}'" class="btn btn-outline-danger">Cancelar</button>
            <button type="submit" class="btn btn-primary">Confirmar</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</body>
</html>
HTML;
            exit;

        /**
         * 5.5 CONFIRMAR APLICACIÓN PROGRAMADA
         */
        case 'confirm_apply_schedule':
            $file = $_GET['file'] ?? '';
            $path = UPLOAD_DIR . $file;
            if (!file_exists($path)) throw new Exception("Archivo no encontrado: $file");

            // Contar registros
            $total = 0;
            $fh    = fopen($path, 'r');
            $first = fgets($fh);
            rewind($fh);
            $d     = (substr_count($first, ';') > substr_count($first, ',')) ? ';' : ',';
            fgetcsv($fh, 0, $d);
            while (fgetcsv($fh, 0, $d) !== false) $total++;
            fclose($fh);

            echo <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Programar Actualización</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
  <div class="container py-5">
    <div class="card mx-auto" style="max-width:500px;">
      <div class="card-body">
        <h5 class="card-title text-center mb-4">Programar cambios <strong>diferidos</strong></h5>
        <p><strong>Archivo:</strong> {$file}</p>
        <p><strong>Registros:</strong> {$total}</p>
        <form method="post" action="?action=execute&accion=apply_schedule&file={$file}">
          <div class="mb-3">
            <label for="run_at" class="form-label">Fecha y hora</label>
            <input type="datetime-local" id="run_at" name="run_at" class="form-control" required>
          </div>
          <div class="mb-3 form-floating">
            <input type="password" name="secret_key" id="secret_key_schedule" class="form-control" placeholder="Clave secreta" required>
            <label for="secret_key_schedule">Clave secreta</label>
          </div>
          <div class="d-flex justify-content-end gap-3">
            <button type="button" onclick="location.href='?action=menu&file={$file}'" class="btn btn-outline-secondary">Cancelar</button>
            <button type="submit" class="btn btn-primary">Programar</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</body>
</html>
HTML;
            exit;

        /**
         * 5.6 EJECUTAR ACCIONES (apply_now / apply_schedule / otras)
         */
        case 'execute':
            // Verificar método POST y clave
            if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['secret_key'] ?? '') !== SECRET_KEY) {
                throw new Exception('Clave secreta incorrecta o método no válido.');
            }
            $accion = $_GET['accion'] ?? '';
            $file   = $_GET['file']   ?? '';

            // Aplicar ahora: mostrará SSE y barra progreso
            if ($accion === 'apply_now') {
                // plantilla con EventSource en JS...
                // (igual que confirm_apply_now pero streaming)
            }

            // Programar deferred
            if ($accion === 'apply_schedule') {
                $runAt = $_POST['run_at'] ?? '';
                if (!$runAt) throw new Exception('Debes elegir fecha y hora.');
                $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
                $stmt = $pdo->prepare("INSERT INTO scheduled_updates (filename, run_at) VALUES (:f,:r)");
                $stmt->execute([':f'=>$file, ':r'=>$runAt]);
                // Confirmación simple:
                header('Location:?action=scheduled');
                exit;
            }

            // Otras (xlsx, pdf, informe)
            ob_start();
            $fn = __DIR__ . "/accion_${accion}.php";
            if (file_exists($fn)) include $fn;
            else echo "<div class='alert alert-danger'>Acción ‘{$accion}’ no encontrada.</div>";
            $data['result'] = ob_get_clean();
            break;

        /**
         * 5.7 LISTAR PROGRAMACIONES
         */
        case 'scheduled':
            $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $data['jobs'] = $pdo->query("SELECT * FROM scheduled_updates ORDER BY run_at DESC")->fetchAll(PDO::FETCH_ASSOC);
            break;

        /**
         * 5.8 EDITAR / ACTUALIZAR / CANCELAR
         */
        case 'edit_schedule':
            $id = (int)($_GET['id'] ?? 0);
            $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $stmt = $pdo->prepare("SELECT * FROM scheduled_updates WHERE id=:i");
            $stmt->execute([':i'=>$id]);
            $data['job'] = $stmt->fetch(PDO::FETCH_ASSOC);
            break;

        case 'update_schedule':
            $id    = (int)($_GET['id'] ?? 0);
            $runAt = $_POST['run_at'] ?? '';
            if (!$runAt) throw new Exception('Falta fecha/hora.');
            $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $pdo->prepare("UPDATE scheduled_updates SET run_at=:r WHERE id=:i")->execute([':r'=>$runAt, ':i'=>$id]);
            header('Location:?action=scheduled');
            exit;

        case 'run_now':
            $id = (int)($_GET['id'] ?? 0);
            $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $pdo->prepare("UPDATE scheduled_updates SET run_at=NOW() WHERE id=:i")->execute([':i'=>$id]);
            header('Location:?action=scheduled');
            exit;

        case 'cancel':
            $id = (int)($_GET['id'] ?? 0);
            $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $pdo->prepare("UPDATE scheduled_updates SET status='cancelled' WHERE id=:i")->execute([':i'=>$id]);
            header('Location:?action=scheduled');
            exit;
    }
} catch (Exception $e) {
    // Captura de errores para mostrarlos en la interfaz
    $error = $e->getMessage();
}

// --------------------------------------------------
// 6. RENDERIZADO DE LA VISTA PRINCIPAL en HTML
// --------------------------------------------------
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Gestión de Precios</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
</head>
<body class="bg-light">
  <div class="container py-4">
    <?php if (!empty($error)): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($action === 'form'): ?>
      <!-- FORMULARIO SUBIDA -->
      <h2 class="mb-4">Subir CSV de precios</h2>
      <form method="post" action="?action=upload" enctype="multipart/form-data">
        <input type="file" name="file" accept=".csv" class="form-control form-control-lg mb-3" required>
        <button type="submit" class="btn btn-primary">Subir y Previsualizar</button>
      </form>

    <?php elseif ($action === 'preview'): ?>
      <!-- PREVISUALIZACIÓN -->
      <h2 class="mb-3">Previsualización: <?= htmlspecialchars($data['file']) ?></h2>
      <div class="alert alert-info mb-3">Delimitador: <strong><?= htmlspecialchars($data['delimiter']) ?></strong></div>
      <div class="table-responsive mb-3">
        <table class="table table-bordered">
          <thead class="table-dark"><tr><th>COD</th><?php for($i=1; $i<=11; $i++): ?><th>L<?=$i?></th><?php endfor; ?><th>PROMO</th></tr></thead>
          <tbody><?php foreach($data['preview'] as $row): ?><tr><?php foreach($row as $cell): ?><td><?= htmlspecialchars($cell) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
        </table>
      </div>
      <a href="?action=menu&file=<?= urlencode($data['file']) ?>" class="btn btn-success">Continuar</a>

    <?php elseif ($action === 'menu'): ?>
      <!-- MENÚ OPERACIONES -->
      <h2 class="text-center mb-4">¿Qué deseas hacer con <em><?= htmlspecialchars($data['file']) ?></em>?</h2>
      <div class="row gx-3 gy-3 mb-4">
        <!-- Botones de acción generados en cuadrícula -->
        <?php foreach (['xlsx'=>'success','pdf'=>'danger','informe'=>'primary'] as $acc => $col): ?>
        <div class="col-6 col-md-3">
          <form method="post" action="?action=execute&accion=<?= $acc ?>&file=<?= urlencode($data['file']) ?>">
            <button class="btn btn-<?= $col ?> w-100 py-3">
              <i class="bi bi-file-earmark-<?= $acc ?>-fill me-2"></i><?= strtoupper($acc) ?>
            </button>
          </form>
        </div>
        <?php endforeach; ?>
        <!-- Aplicar Cambios -->
        <div class="col-6 col-md-3">
          <form method="post" action="?action=confirm_apply_now&file=<?= urlencode($data['file']) ?>">
            <button class="btn btn-warning w-100 text-white py-3">
              <i class="bi bi-lightning-fill me-2"></i>Ahora
            </button>
          </form>
        </div>
        <div class="col-6 col-md-3">
          <form method="post" action="?action=confirm_apply_schedule&file=<?= urlencode($data['file']) ?>">
            <button class="btn btn-warning w-100 text-white py-3">
              <i class="bi bi-calendar-event me-2"></i>Programado
            </button>
          </form>
        </div>
      </div>
      <a href="?action=scheduled" class="btn btn-outline-secondary">
        <i class="bi bi-clock-history me-1"></i>Ver Programaciones
      </a>

    <?php elseif ($action === 'scheduled'): ?>
      <!-- LISTADO DE PROGRAMACIONES -->
      <h2 class="mb-4">Historial de Programaciones</h2>
      <div class="table-responsive mb-3">
        <table class="table table-hover align-middle">
          <thead class="table-light">
            <tr><th>#</th><th>Archivo</th><th>Para</th><th>Ejecutado</th><th>Estado</th><th>Creado</th><th>Acciones</th></tr>
          </thead>
          <tbody>
            <?php if (empty($data['jobs'])): ?>
            <tr><td colspan="7" class="text-center text-muted py-4">No hay programaciones.</td></tr>
            <?php endif; ?>
            <?php foreach($data['jobs'] as $j): ?>
            <?php switch($j['status']) {case 'pending': $badge='warning'; break; case 'completed': $badge='success'; break; case 'failed': $badge='danger'; break; default: $badge='secondary'; }
            ?>
            <tr>
              <td><?= $j['id']?></td>
              <td><?= htmlspecialchars($j['filename'])?></td>
              <td><?= date('d/m/Y H:i',strtotime($j['run_at']))?></td>
              <td><?= $j['executed_at']?date('d/m/Y H:i',strtotime($j['executed_at'])):'—'?></td>
              <td><span class="badge bg-<?= $badge?>"><?= ucfirst(htmlspecialchars($j['status']))?></span></td>
              <td><?= date('d/m/Y H:i',strtotime($j['created_at']))?></td>
              <td><?php if($j['status']==='pending'): ?>
                <a href="?action=edit_schedule&id=<?= $j['id']?>" class="btn btn-sm btn-outline-primary">✎</a>
                <a href="?action=run_now&id=<?= $j['id']?>" class="btn btn-sm btn-outline-success">▶</a>
                <a href="?action=cancel&id=<?= $j['id']?>" class="btn btn-sm btn-outline-danger">✕</a>
              <?php endif; ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

    <?php elseif ($action === 'edit_schedule'): ?>
      <!-- FORM EDITAR PROGRAMACIÓN -->
      <h2 class="mb-4">Editar Programación #<?= $data['job']['id']?></h2>
      <form method="post" action="?action=update_schedule&id=<?= $data['job']['id']?>">
        <div class="mb-3">
          <label class="form-label">Archivo</label>
          <input class="form-control" disabled value="<?= htmlspecialchars($data['job']['filename'])?>">
        </div>
        <div class="mb-3">
          <label for="run_at" class="form-label">Para</label>
          <input type="datetime-local" name="run_at" id="run_at" class="form-control" value="<?= date('Y-m-d\TH:i',strtotime($data['job']['run_at']))?>" required>
        </div>
        <button class="btn btn-primary">Guardar</button>
        <a href="?action=scheduled" class="btn btn-secondary ms-2">Volver</a>
      </form>

    <?php elseif (isset($data['result'])): ?>
      <!-- RESULTADO DE OTRAS ACCIONES -->
      <h2 class="text-center mb-4">Resultado</h2>
      <div class="card mb-3"><div class="card-body"><?= $data['result'] ?></div></div>
      <a href="?action=menu&file=<?= urlencode($data['file']) ?>" class="btn btn-secondary">← Menú</a>
    <?php endif; ?>
  </div>
</body>
</html>
