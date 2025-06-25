<?php
// progress_apply_now.php
// SSE endpoint que procesa y emite progreso

// Ajustes SSE
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');  // para Nginx, deshabilita buffer

// Conexión DB
$dsn    = 'mysql:host=168.181.185.142;dbname=sayt_avv;charset=utf8';
$dbUser = 'guido';
$dbPass = 'kI48Te';

// Obtén el archivo desde GET
$file = __DIR__ . '/uploads/' . ($_GET['file'] ?? '');
if (!file_exists($file)) {
    echo "event: error\n";
    echo 'data: {"msg":"Archivo no encontrado"}' . "\n\n";
    exit;
}

// Abre CSV y prepara updates EXACTAMENTE COMO EN accion_apply_now.php
$fp = fopen($file, 'r');
$firstLine   = fgets($fp);
rewind($fp);
$delim       = (substr_count($firstLine,';')>substr_count($firstLine,','))?';':',';
$header      = fgetcsv($fp, 0, $delim);
$lowerHeader = array_map('strtolower',$header);

$updates = [];
$costos  = [];
while(($row=fgetcsv($fp,0,$delim))!==false){
    $pid = (int)$row[array_search('producto_id',$lowerHeader)];
    if(!$pid) continue;
    // listas 1-11 excepto 4
    foreach(range(1,11) as $i){
        if($i===4) continue;
        $idx = array_search("lista_$i",$lowerHeader);
        if($idx!==false && $row[$idx]!==''){
            $updates[]=['pid'=>$pid,'lid'=>$i,'precio'=>floatval(str_replace(',','.',$row[$idx]))];
        }
    }
    $cIdx = array_search('lista_4',$lowerHeader);
    $pIdx = array_search('promocion',$lowerHeader);
    $costos[$pid]=[
      'costo'=>($cIdx!==false?floatval(str_replace(',','.',$row[$cIdx])):null),
      'promo'=>($pIdx!==false?$row[$pIdx]:null)
    ];
}
fclose($fp);

$total = count($updates) + count($costos);
$done = 0;
$start = microtime(true);

// Inicia transacción
$pdo = new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->beginTransaction();

// 1) Actualiza precios
foreach($updates as $u){
    $stmt = $pdo->prepare("UPDATE precios SET precio=:precio WHERE producto_id=:pid AND lista_id=:lid");
    $stmt->execute([':precio'=>$u['precio'],':pid'=>$u['pid'],':lid'=>$u['lid']]);
    $done++;
    $elapsed = microtime(true)-$start;
    $eta = $done>0 ? round(($elapsed/$done)*($total-$done)) : 0;
    $pct = round($done/$total*100,1);
    // envía progreso
    echo "event: progress\n";
    echo 'data: {"pct":'.$pct.',"eta":'.$eta.'}'."\n\n";
    flush();
}

// 2) Actualiza costo/promo
foreach($costos as $pid=>$info){
    if($info['costo']!==null){
      $stmt = $pdo->prepare("UPDATE productos SET costo=:c,updated_at=NOW(),observaciones=:p WHERE id=:pid");
      $stmt->execute([':c'=>$info['costo'],':p'=>$info['promo'],':pid'=>$pid]);
    }
    else {
      $stmt = $pdo->prepare("UPDATE productos SET updated_at=NOW(),observaciones=:p WHERE id=:pid");
      $stmt->execute([':p'=>$info['promo'],':pid'=>$pid]);
    }
    $done++;
    $elapsed = microtime(true)-$start;
    $eta = $done>0 ? round(($elapsed/$done)*($total-$done)) : 0;
    $pct = round($done/$total*100,1);
    echo "event: progress\n";
    echo 'data: {"pct":'.$pct.',"eta":'.$eta.'}'."\n\n";
    flush();
}

// Commit
$pdo->commit();

// Finalización
echo "event: complete\n";
echo 'data: {"modified":'.count($updates).',"costos":'.count($costos).'}'."\n\n";
flush();
