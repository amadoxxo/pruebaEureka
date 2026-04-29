<?php

try {
    // Conexión a la base de datos usando PDO (misma configuración que módulos anteriores)
    $pdo = new PDO(
        "mysql:host=localhost;dbname=prueba_eureka;charset=utf8mb4",
        "root",
        "",
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

    // Obtener filtro de departamento (vía GET)
    $departamentoFilter = $_GET['departamento'] ?? '';
    
    // Obtener lista de departamentos para el dropdown de filtro
    $stmtDept = $pdo->query("SELECT DISTINCT departamento FROM colegios ORDER BY departamento");
    $departamentos = $stmtDept->fetchAll();

    $sql = "SELECT 
                c.id,
                c.nombre,
                c.ciudad,
                c.departamento,
                c.cantidad_estudiantes,
                COALESCE(SUM(f.total_factura), 0) AS total_facturado,
                COUNT(f.id) AS cantidad_facturas,
                CASE 
                    WHEN COUNT(f.id) = 0 THEN 'sin_facturacion'
                    ELSE (
                        SELECT f2.estado 
                        FROM facturacion f2 
                        WHERE f2.nombre_colegio = h.nombre_facturacion
                        GROUP BY f2.estado 
                        ORDER BY COUNT(*) DESC 
                        LIMIT 1
                    )
                END AS estado_predominante,
                h.estado AS homologacion_estado
            FROM colegios c
            INNER JOIN homologacion_colegios h ON c.id = h.id_colegio
            LEFT JOIN facturacion f ON f.nombre_colegio = h.nombre_facturacion
            " . (!empty($departamentoFilter) ? " WHERE c.departamento = :dept " : "") . "
            GROUP BY c.id, c.nombre, c.ciudad, c.departamento, c.cantidad_estudiantes, h.estado, h.nombre_facturacion
            ORDER BY c.id";
    
    $stmt = $pdo->prepare($sql);
    if (!empty($departamentoFilter)) {
        $stmt->bindValue(':dept', $departamentoFilter);
    }
    $stmt->execute();
    $colegios = $stmt->fetchAll();

    // CÁLCULOS DE TOTALES (se computan en PHP para simplificar, dado el tamaño pequeño de datos)
    $totalGeneralFacturado = 0;
    $colegiosConFacturacion = []; // Para promedio de estudiantes
    $colegiosSinFactura = 0;
    
    foreach ($colegios as $row) {
        $totalGeneralFacturado += $row['total_facturado'];
        if ($row['cantidad_facturas'] > 0) {
            $colegiosConFacturacion[] = $row['cantidad_estudiantes'];
        } else {
            $colegiosSinFactura++;
        }
    }
    
    $promedioEstudiantes = count($colegiosConFacturacion) > 0 
        ? array_sum($colegiosConFacturacion) / count($colegiosConFacturacion) 
        : 0;

} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporte Consolidado - Eureka</title>
    <style>
        /* Estilos consistentes con los módulos anteriores */
        * {
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        body {
            background-color: #f5f7fa;
            padding: 20px;
            margin: 0;
        }
        .container {
            max-width: 1300px;
            margin: 0 auto;
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            padding: 30px;
        }
        h1 {
            color: #2c3e50;
            text-align: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #3498db;
        }
        .filtro {
            background-color: #ecf0f1;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .filtro form {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .filtro label {
            font-weight: bold;
            color: #2c3e50;
        }
        .filtro select {
            padding: 8px;
            border: 1px solid #bdc3c7;
            border-radius: 4px;
            min-width: 200px;
        }
        .filtro button {
            padding: 8px 16px;
            background-color: #3498db;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        .filtro button:hover {
            background-color: #2980b9;
        }
        .filtro a {
            color: #7f8c8d;
            text-decoration: none;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            font-size: 0.9em;
        }
        th {
            background-color: #34495e;
            color: white;
            padding: 12px;
            text-align: left;
        }
        td {
            padding: 10px;
            border-bottom: 1px solid #ddd;
        }
        tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        /* Resaltar colegios sin facturación (requisito) */
        tr.sin-facturacion {
            background-color: #fff3cd !important; /* Amarillo claro */
        }
        tr.sin-facturacion td:first-child::after {
            content: " (Sin Facturación)";
            color: #856404;
            font-size: 0.85em;
            margin-left: 5px;
        }
        .totales {
            background-color: #2c3e50;
            color: white;
            padding: 20px;
            border-radius: 8px;
            margin-top: 30px;
        }
        .totales h3 {
            margin-top: 0;
            border-bottom: 1px solid #7f8c8d;
            padding-bottom: 10px;
        }
        .totales-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
        }
        .total-item {
            background-color: #34495e;
            padding: 15px;
            border-radius: 6px;
        }
        .total-item .label {
            font-size: 0.9em;
            opacity: 0.8;
        }
        .total-item .value {
            font-size: 1.4em;
            font-weight: bold;
            margin-top: 5px;
        }
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 0.85em;
            font-weight: bold;
        }
        .badge.pagado { background-color: #27ae60; color: white; }
        .badge.pendiente { background-color: #f39c12; color: white; }
        .badge.vencido { background-color: #e74c3c; color: white; }
        .badge.sin_facturacion { background-color: #95a5a6; color: white; }
        .money { color: #27ae60; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Reporte Consolidado de Colegios</h1>
        
        <!-- Formulario de filtro por departamento -->
        <div class="filtro">
            <form method="GET" action="modulo3_reporte.php">
                <label for="departamento">Filtrar por Departamento:</label>
                <select name="departamento" id="departamento">
                    <option value="">Todos los departamentos</option>
                    <?php foreach ($departamentos as $dept): ?>
                        <option value="<?php echo htmlspecialchars($dept['departamento']); ?>"
                            <?php echo ($departamentoFilter == $dept['departamento']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($dept['departamento']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit">Filtrar</button>
                <?php if ($departamentoFilter): ?>
                    <a href="modulo3_reporte.php">Limpiar filtro</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Tabla de resultados -->
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nombre del Colegio</th>
                    <th>Ciudad</th>
                    <th>Departamento</th>
                    <th>Estudiantes</th>
                    <th>Total Facturado</th>
                    <th>Cant. Facturas</th>
                    <th>Estado Predominante</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($colegios)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center;">No hay datos para mostrar</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($colegios as $row): ?>
                        <?php 
                        // Determinar si el colegio no tiene facturación para resaltarlo
                        $rowClass = ($row['cantidad_facturas'] == 0) ? 'sin-facturacion' : '';
                        ?>
                        <tr class="<?php echo $rowClass; ?>">
                            <td><?php echo $row['id']; ?></td>
                            <td><?php echo htmlspecialchars($row['nombre']); ?></td>
                            <td><?php echo htmlspecialchars($row['ciudad']); ?></td>
                            <td><?php echo htmlspecialchars($row['departamento']); ?></td>
                            <td><?php echo number_format($row['cantidad_estudiantes']); ?></td>
                            <td class="money">$<?php echo number_format($row['total_facturado'], 2); ?></td>
                            <td><?php echo $row['cantidad_facturas']; ?></td>
                            <td>
                                <?php 
                                $estado = $row['estado_predominante'];
                                $badgeClass = $estado;
                                ?>
                                <span class="badge <?php echo $badgeClass; ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $estado)); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Totales al pie de la tabla -->
        <div class="totales">
            <h3>Resumen General</h3>
            <div class="totales-grid">
                <div class="total-item">
                    <div class="label">Total General Facturado</div>
                    <div class="value">$<?php echo number_format($totalGeneralFacturado, 2); ?></div>
                </div>
                <div class="total-item">
                    <div class="label">Promedio de Estudiantes (con facturación)</div>
                    <div class="value"><?php echo number_format($promedioEstudiantes, 1); ?></div>
                </div>
                <div class="total-item">
                    <div class="label">Colegios sin ninguna factura</div>
                    <div class="value"><?php echo $colegiosSinFactura; ?></div>
                </div>
            </div>
        </div>
        
        <div style="text-align: center; margin-top: 20px; color: #7f8c8d; font-size: 0.9em;">
            Módulo 3 - Reporte Consolidado | Eureka Contenidos Educativos S.A.S. | 
            Generado: <?php echo date('Y-m-d H:i:s'); ?>
        </div>
    </div>
</body>
</html>
