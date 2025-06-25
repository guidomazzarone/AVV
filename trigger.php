<?php
// trigger.php

// 1) Mostrar errores en pantalla (durante la depuración)
ini_set('display_errors',       1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 2) Log de errores a tu fichero dentro de uploads/
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/uploads/php-error.log');

// 3) Marca en el log cada invocación
error_log("trigger.php invocado: " . date('c'));

// 4) Comprueba existencia de run_scheduled.php
$runner = __DIR__ . '/run_scheduled.php';
if (! file_exists($runner)) {
    error_log("ERROR: No se encontró run_scheduled.php en $runner");
    header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error', true, 500);
    exit("Error interno: fichero de ejecución no encontrado.");
}

// 5) Carga el script que procesa los jobs
try {
    require_once $runner;
} catch (Throwable $e) {
    error_log("Exception en run_scheduled.php: " . $e->getMessage());
    header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error', true, 500);
    exit("Error interno al ejecutar la tarea programada.");
}

// 6) Si todo fue bien, devolvemos un 200
echo "OK - tareas programadas ejecutadas correctamente.";
