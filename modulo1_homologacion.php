<?php


// Configuración de conexión a la base de datos MySQL usando PDO
$pdo = new PDO(
    "mysql:host=localhost;dbname=prueba_eureka;charset=utf8mb4",
    "root",
    "",
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, // Activar excepciones en errores
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC // Devolver resultados como arrays asociativos
    ]
);

$pdo->beginTransaction();

// Usamos un array para rastrear nombres de colegios ya procesados, evitando duplicados en homologacion_colegios
$processedSchools = [];
$stmtFact = $pdo->query("SELECT id, nombre_colegio FROM facturacion");

while ($rowFact = $stmtFact->fetch()) {
    $nombreFacturacion = $rowFact['nombre_colegio'];
    
    // Saltar si este nombre de colegio ya fue procesado (evita duplicados en la tabla de homologación)
    if (in_array($nombreFacturacion, $processedSchools)) {
        continue;
    }
    $processedSchools[] = $nombreFacturacion;

    // Idempotencia: Eliminar registro existente para este nombre de colegio en facturación
    $delStmt = $pdo->prepare("DELETE FROM homologacion_colegios WHERE nombre_facturacion = ?");
    $delStmt->execute([$nombreFacturacion]);


    $stmtColegio = $pdo->prepare("SELECT id, nombre FROM colegios WHERE nombre COLLATE utf8mb4_bin = ?");
    $stmtColegio->execute([$nombreFacturacion]);
    $colegio = $stmtColegio->fetch();

    if ($colegio) {
        // Estado: homologado (el nombre en facturación existe en colegios)
        $sql = "INSERT INTO homologacion_colegios 
                (id_colegio, nombre_facturacion, nombre_colegio, estado, fecha_homologacion) 
                VALUES (?, ?, ?, 'homologado', CURRENT_TIMESTAMP)";
        $pdo->prepare($sql)->execute([$colegio['id'], $nombreFacturacion, $colegio['nombre']]);
    } else {
        // Estado: sin_colegio (el nombre en facturación no existe en colegios)
        $sql = "INSERT INTO homologacion_colegios 
                (id_colegio, nombre_facturacion, nombre_colegio, estado, fecha_homologacion) 
                VALUES (NULL, ?, NULL, 'sin_colegio', CURRENT_TIMESTAMP)";
        $pdo->prepare($sql)->execute([$nombreFacturacion]);
    }
}

// Idempotencia: Eliminar registros existentes con estado sin_facturacion antes de insertar nuevos
$pdo->exec("DELETE FROM homologacion_colegios WHERE estado = 'sin_facturacion'");

// Consulta para encontrar colegios sin facturación: LEFT JOIN y filtrar los que no tienen coincidencia
$stmtSinFact = $pdo->query("SELECT c.id, c.nombre 
                            FROM colegios c
                            LEFT JOIN facturacion f ON c.nombre COLLATE utf8mb4_bin = f.nombre_colegio COLLATE utf8mb4_bin
                            WHERE f.nombre_colegio IS NULL");

while ($row = $stmtSinFact->fetch()) {
    // Estado: sin_facturacion (el colegio existe pero no tiene facturas)
    $sql = "INSERT INTO homologacion_colegios 
            (id_colegio, nombre_facturacion, nombre_colegio, estado, fecha_homologacion) 
            VALUES (?, NULL, ?, 'sin_facturacion', CURRENT_TIMESTAMP)";
    $pdo->prepare($sql)->execute([$row['id'], $row['nombre']]);
}

$pdo->commit();

$totalHomologados = $pdo->query("SELECT COUNT(*) FROM homologacion_colegios WHERE estado = 'homologado'")->fetchColumn();
$totalSinColegio = $pdo->query("SELECT COUNT(*) FROM homologacion_colegios WHERE estado = 'sin_colegio'")->fetchColumn();
$totalSinFacturacion = $pdo->query("SELECT COUNT(*) FROM homologacion_colegios WHERE estado = 'sin_facturacion'")->fetchColumn();

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resumen de Homologación - Eureka</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background-color: #f5f7fa;
            padding: 20px;
            display: flex;
            justify-content: center;
            min-height: 100vh;
        }
        
        .container {
            max-width: 1000px;
            width: 100%;
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            padding: 30px;
            margin-top: 20px;
        }
        
        h1 {
            color: #2c3e50;
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 2px solid #3498db;
        }
        
        .resumen {
            text-align: center;
            margin-bottom: 30px;
            color: #555;
            font-size: 1.1em;
        }
        
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            padding: 25px;
            border-radius: 8px;
            text-align: center;
            color: white;
            box-shadow: 0 3px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-card.homologado {
            background-color: #27ae60;
        }
        
        .stat-card.sin-colegio {
            background-color: #e74c3c;
        }
        
        .stat-card.sin-facturacion {
            background-color: #f39c12;
        }
        
        .stat-card h3 {
            font-size: 1.2em;
            margin-bottom: 10px;
            font-weight: 600;
        }
        
        .stat-value {
            font-size: 2.5em;
            font-weight: 700;
            margin: 10px 0;
        }
        
        .stat-card p {
            font-size: 0.9em;
            opacity: 0.9;
        }
        
        .total-container {
            background-color: #ecf0f1;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            font-size: 1.2em;
            color: #2c3e50;
            font-weight: 600;
        }
        
        .footer {
            text-align: center;
            margin-top: 30px;
            color: #7f8c8d;
            font-size: 0.9em;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Resumen de Homologación de Colegios</h1>
        
        <div class="resumen">
            Resultados del proceso de homologación entre las tablas de colegios y facturación
        </div>
        
        <div class="stats-container">
            <div class="stat-card homologado">
                <h3>Colegios Homologados</h3>
                <div class="stat-value"><?php echo $totalHomologados; ?></div>
                <p>Facturación coincide con registro de colegio</p>
            </div>
            
            <div class="stat-card sin-colegio">
                <h3>Sin Colegio</h3>
                <div class="stat-value"><?php echo $totalSinColegio; ?></div>
                <p>Facturación no encuentra colegio correspondiente</p>
            </div>
            
            <div class="stat-card sin-facturacion">
                <h3>Sin Facturación</h3>
                <div class="stat-value"><?php echo $totalSinFacturacion; ?></div>
                <p>Colegio registrado sin facturas</p>
            </div>
        </div>
        
        <div class="total-container">
            Total de registros en homologación: <?php echo $totalHomologados + $totalSinColegio + $totalSinFacturacion; ?>
        </div>
        
        <div class="footer">
            Módulo 1 - Homologación | Eureka Contenidos Educativos S.A.S. | <?php echo date('Y-m-d H:i:s'); ?>
        </div>
    </div>
</body>
</html>
<?php
