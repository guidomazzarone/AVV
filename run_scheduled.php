<?php
// run_scheduled.php – Ejecuta las actualizaciones programadas y registra fecha de ejecución

$dsn    = 'mysql:host=168.181.185.142;dbname=sayt_avv;charset=utf8';
$dbUser = 'guido';
$dbPass = 'kI48Te';
define('UPLOAD_DIR', __DIR__ . '/uploads/');

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    error_log("run_scheduled: fallo conexión DB: " . $e->getMessage());
    exit(1);
}

// 1) Traer jobs pendientes cuya run_at <= ahora
$stmt = $pdo->query("
    SELECT id, filename
      FROM scheduled_updates
     WHERE run_at <= NOW()
       AND status = 'pending'
");
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($jobs as $job) {
    $id   = $job['id'];
    $file = $job['filename'];
    $path = UPLOAD_DIR . $file;

    if (!file_exists($path)) {
        $pdo->prepare("
            UPDATE scheduled_updates
               SET status     = 'failed',
                   failed_at  = NOW()
             WHERE id = ?
        ")->execute([$id]);
        error_log("run_scheduled: CSV no encontrado para job #$id: $path");
        continue;
    }

    try {
        // Aquí incluyes tu lógica de aplicación inmediata, por ejemplo:
        include __DIR__ . '/progress_apply_now.php';

        // Si pasó OK, marcamos como completed y estampamos executed_at
        $pdo->prepare("
            UPDATE scheduled_updates
               SET status       = 'completed',
                   executed_at  = NOW()
             WHERE id = ?
        ")->execute([$id]);

    } catch (Exception $e) {
        // En caso de error, lo marcamos failed y registramos failed_at
        $pdo->prepare("
            UPDATE scheduled_updates
               SET status     = 'failed',
                   failed_at  = NOW()
             WHERE id = ?
        ")->execute([$id]);
        error_log("run_scheduled: Error en job #$id: " . $e->getMessage());
    }
}
