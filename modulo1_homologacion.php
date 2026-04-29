<?php

try {
    $pdo = new PDO(
        "mysql:host=localhost;dbname=prueba_eureka;charset=utf8mb4",
        "root",
        "",
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

    $pdo->beginTransaction();

    // Process all facturacion records, track unique school names to avoid duplicates
    $processedSchools = [];
    $stmtFact = $pdo->query("SELECT id, nombre_colegio FROM facturacion");
    while ($rowFact = $stmtFact->fetch()) {
        $nombreFacturacion = $rowFact['nombre_colegio'];
        
        // Skip if already processed this school name
        if (in_array($nombreFacturacion, $processedSchools)) {
            continue;
        }
        $processedSchools[] = $nombreFacturacion;

        // Remove existing record for this billing school name (idempotency)
        $delStmt = $pdo->prepare("DELETE FROM homologacion_colegios WHERE nombre_facturacion = ?");
        $delStmt->execute([$nombreFacturacion]);

        // Exact case-sensitive match in colegios table
        $stmtColegio = $pdo->prepare("SELECT id, nombre FROM colegios WHERE nombre COLLATE utf8mb4_bin = ?");
        $stmtColegio->execute([$nombreFacturacion]);
        $colegio = $stmtColegio->fetch();

        if ($colegio) {
            // Homologado: matched school
            $sql = "INSERT INTO homologacion_colegios 
                    (id_colegio, nombre_facturacion, nombre_colegio, estado, fecha_homologacion) 
                    VALUES (?, ?, ?, 'homologado', CURRENT_TIMESTAMP)";
            $pdo->prepare($sql)->execute([$colegio['id'], $nombreFacturacion, $colegio['nombre']]);
        } else {
            // Sin colegio: no matching school in colegios table
            $sql = "INSERT INTO homologacion_colegios 
                    (id_colegio, nombre_facturacion, nombre_colegio, estado, fecha_homologacion) 
                    VALUES (NULL, ?, NULL, 'sin_colegio', CURRENT_TIMESTAMP)";
            $pdo->prepare($sql)->execute([$nombreFacturacion]);
        }
    }

    // Clear existing sin_facturacion records (idempotency)
    $pdo->exec("DELETE FROM homologacion_colegios WHERE estado = 'sin_facturacion'");

    // Process schools with no billing records (case-sensitive match)
    $stmtSinFact = $pdo->query("SELECT c.id, c.nombre 
                                FROM colegios c
                                LEFT JOIN facturacion f ON c.nombre COLLATE utf8mb4_bin = f.nombre_colegio COLLATE utf8mb4_bin
                                WHERE f.nombre_colegio IS NULL");
    while ($row = $stmtSinFact->fetch()) {
        $sql = "INSERT INTO homologacion_colegios 
                (id_colegio, nombre_facturacion, nombre_colegio, estado, fecha_homologacion) 
                VALUES (?, NULL, ?, 'sin_facturacion', CURRENT_TIMESTAMP)";
        $pdo->prepare($sql)->execute([$row['id'], $row['nombre']]);
    }

    $pdo->commit();

    // Generate summary from actual table counts
    $totalHomologados = $pdo->query("SELECT COUNT(*) FROM homologacion_colegios WHERE estado = 'homologado'")->fetchColumn();
    $totalSinColegio = $pdo->query("SELECT COUNT(*) FROM homologacion_colegios WHERE estado = 'sin_colegio'")->fetchColumn();
    $totalSinFacturacion = $pdo->query("SELECT COUNT(*) FROM homologacion_colegios WHERE estado = 'sin_facturacion'")->fetchColumn();

    echo "=== Resumen de Homologación ===\n";
    echo "Total homologados: " . $totalHomologados . "\n";
    echo "Total sin colegio: " . $totalSinColegio . "\n";
    echo "Total sin facturación: " . $totalSinFacturacion . "\n";
    echo "Total registros: " . ($totalHomologados + $totalSinColegio + $totalSinFacturacion) . "\n";

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    die("Error: " . $e->getMessage() . "\n");
}
