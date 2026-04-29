<?php

session_start();

// Configuración de conexión a la base de datos usando PDO (prevención de SQL Injection)
try {
    $pdo = new PDO(
        "mysql:host=localhost;dbname=prueba_eureka;charset=utf8mb4",
        "root",
        "",
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, // Activar excepciones en errores
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC // Resultados como arrays asociativos
        ]
    );
} catch (PDOException $e) {
    die("Error al conectar con la base de datos: " . $e->getMessage());
}

// Variables iniciales
$action = $_GET['action'] ?? 'list'; // Acción por defecto: listar colegios
$errors = [];
$colegio = [];
$colegios = [];

// Procesar acciones POST (crear, editar, eliminar)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $postAction = $_POST['action'] ?? '';
    
    try {
        // OPERACIÓN: CREAR NUEVO COLEGIO
        if ($postAction === 'create') {
            // Obtener y limpiar datos del formulario
            $nombre = trim($_POST['nombre'] ?? '');
            $departamento = trim($_POST['departamento'] ?? '');
            $ciudad = trim($_POST['ciudad'] ?? '');
            $cantidad_grupos = $_POST['cantidad_grupos'] ?? '';
            $cantidad_estudiantes = $_POST['cantidad_estudiantes'] ?? '';
            
            // Validaciones básicas
            $errors = [];
            if (empty($nombre)) $errors['nombre'] = 'El nombre es requerido';
            if (empty($departamento)) $errors['departamento'] = 'El departamento es requerido';
            if (empty($ciudad)) $errors['ciudad'] = 'La ciudad es requerida';
            if (!is_numeric($cantidad_grupos) || $cantidad_grupos <= 0) {
                $errors['cantidad_grupos'] = 'Debe ser un número positivo';
            }
            if (!is_numeric($cantidad_estudiantes) || $cantidad_estudiantes <= 0) {
                $errors['cantidad_estudiantes'] = 'Debe ser un número positivo';
            }
            
            if (empty($errors)) {
                // Insertar nuevo colegio usando prepared statement (previene SQL Injection)
                $stmt = $pdo->prepare("INSERT INTO colegios (nombre, departamento, ciudad, cantidad_grupos, cantidad_estudiantes) 
                                        VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$nombre, $departamento, $ciudad, $cantidad_grupos, $cantidad_estudiantes]);
                
                $_SESSION['message'] = 'Colegio creado exitosamente';
                $_SESSION['message_type'] = 'success';
                header('Location: modulo2_crud.php?action=list');
                exit;
            } else {
                // Preservar valores del formulario en caso de error
                $colegio = [
                    'nombre' => $nombre,
                    'departamento' => $departamento,
                    'ciudad' => $ciudad,
                    'cantidad_grupos' => $cantidad_grupos,
                    'cantidad_estudiantes' => $cantidad_estudiantes
                ];
            }
        }
        
        // OPERACIÓN: EDITAR COLEGIO EXISTENTE
        elseif ($postAction === 'edit') {
            $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT);
            if (!$id) {
                $_SESSION['message'] = 'ID de colegio inválido';
                $_SESSION['message_type'] = 'error';
                header('Location: modulo2_crud.php?action=list');
                exit;
            }
            
            // Obtener y limpiar datos del formulario
            $nombre = trim($_POST['nombre'] ?? '');
            $departamento = trim($_POST['departamento'] ?? '');
            $ciudad = trim($_POST['ciudad'] ?? '');
            $cantidad_grupos = $_POST['cantidad_grupos'] ?? '';
            $cantidad_estudiantes = $_POST['cantidad_estudiantes'] ?? '';
            
            // Validaciones básicas
            $errors = [];
            if (empty($nombre)) $errors['nombre'] = 'El nombre es requerido';
            if (empty($departamento)) $errors['departamento'] = 'El departamento es requerido';
            if (empty($ciudad)) $errors['ciudad'] = 'La ciudad es requerida';
            if (!is_numeric($cantidad_grupos) || $cantidad_grupos <= 0) {
                $errors['cantidad_grupos'] = 'Debe ser un número positivo';
            }
            if (!is_numeric($cantidad_estudiantes) || $cantidad_estudiantes <= 0) {
                $errors['cantidad_estudiantes'] = 'Debe ser un número positivo';
            }
            
            if (empty($errors)) {
                // Actualizar colegio usando prepared statement
                $stmt = $pdo->prepare("UPDATE colegios SET nombre = ?, departamento = ?, ciudad = ?, 
                                        cantidad_grupos = ?, cantidad_estudiantes = ? WHERE id = ?");
                $stmt->execute([$nombre, $departamento, $ciudad, $cantidad_grupos, $cantidad_estudiantes, $id]);
                
                $_SESSION['message'] = 'Colegio actualizado exitosamente';
                $_SESSION['message_type'] = 'success';
                header('Location: modulo2_crud.php?action=list');
                exit;
            } else {
                // Preservar valores del formulario en caso de error
                $colegio = [
                    'id' => $id,
                    'nombre' => $nombre,
                    'departamento' => $departamento,
                    'ciudad' => $ciudad,
                    'cantidad_grupos' => $cantidad_grupos,
                    'cantidad_estudiantes' => $cantidad_estudiantes
                ];
            }
        }
        
        // OPERACIÓN: ELIMINAR COLEGIO (confirmado vía JS)
        elseif ($postAction === 'confirm_delete') {
            $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT);
            if (!$id) {
                $_SESSION['message'] = 'ID de colegio inválido';
                $_SESSION['message_type'] = 'error';
                header('Location: modulo2_crud.php?action=list');
                exit;
            }
            
            // Eliminar colegio usando prepared statement
            $stmt = $pdo->prepare("DELETE FROM colegios WHERE id = ?");
            $stmt->execute([$id]);
            
            $_SESSION['message'] = 'Colegio eliminado exitosamente';
            $_SESSION['message_type'] = 'success';
            header('Location: modulo2_crud.php?action=list');
            exit;
        }
    } catch (PDOException $e) {
        $errors['general'] = 'Error en la base de datos: ' . $e->getMessage();
    }
}

// Procesar acciones GET (mostrar formularios, listar)
try {
    if ($action === 'list') {
        // Obtener todos los colegios con conteo de registros en facturación (para alerta JS)
        $stmt = $pdo->query("SELECT c.*, 
                            (SELECT COUNT(*) FROM facturacion f 
                            WHERE f.nombre_colegio COLLATE utf8mb4_bin = c.nombre COLLATE utf8mb4_bin) AS fact_count 
                            FROM colegios c ORDER BY c.id");
        $colegios = $stmt->fetchAll();
    } elseif ($action === 'edit') {
        // Obtener datos del colegio a editar
        $id = filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT);
        if (!$id) {
            $_SESSION['message'] = 'ID de colegio inválido';
            $_SESSION['message_type'] = 'error';
            header('Location: modulo2_crud.php?action=list');
            exit;
        }
        
        $stmt = $pdo->prepare("SELECT * FROM colegios WHERE id = ?");
        $stmt->execute([$id]);
        $colegio = $stmt->fetch();
        if (!$colegio) {
            $_SESSION['message'] = 'Colegio no encontrado';
            $_SESSION['message_type'] = 'error';
            header('Location: modulo2_crud.php?action=list');
            exit;
        }
    } elseif ($action === 'create') {
        // Inicializar colegio vacío para el formulario de creación
        $colegio = [
            'nombre' => '',
            'departamento' => '',
            'ciudad' => '',
            'cantidad_grupos' => '',
            'cantidad_estudiantes' => ''
        ];
    }
} catch (PDOException $e) {
    $errors['general'] = 'Error al cargar datos: ' . $e->getMessage();
}

$message = $_SESSION['message'] ?? '';
$message_type = $_SESSION['message_type'] ?? '';
unset($_SESSION['message'], $_SESSION['message_type']);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CRUD de Colegios - Eureka</title>
    <style>
        * {
            box-sizing: border-box;
            font-family: Arial, sans-serif;
        }
        body {
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 {
            color: #2c3e50;
            border-bottom: 2px solid #3498db;
            padding-bottom: 10px;
        }
        .message {
            padding: 10px;
            margin: 10px 0;
            border-radius: 4px;
            color: white;
        }
        .message.success {
            background-color: #27ae60;
        }
        .message.error {
            background-color: #e74c3c;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        th, td {
            padding: 12px;
            border: 1px solid #ddd;
            text-align: left;
        }
        th {
            background-color: #f2f2f2;
        }
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .actions {
            display: flex;
            gap: 5px;
        }
        .btn {
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            color: white;
            text-decoration: none;
            font-size: 14px;
        }
        .btn-edit {
            background-color: #3498db;
        }
        .btn-delete {
            background-color: #e74c3c;
        }
        .btn-create {
            background-color: #27ae60;
            display: inline-block;
            margin: 10px 0;
        }
        .btn-cancel {
            background-color: #95a5a6;
            display: inline-block;
            margin: 10px 0;
        }
        form {
            margin: 20px 0;
        }
        .form-group {
            margin: 10px 0;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        input {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .error {
            color: #e74c3c;
            font-size: 0.9em;
            margin-top: 5px;
        }
    </style>
    <script>
        // Función para confirmar eliminación con alerta JavaScript
        function confirmDelete(factCount, colegioNombre) {
            let msg = `¿Está seguro de eliminar el colegio "${colegioNombre}"?`;
            if (factCount > 0) {
                msg += `\n¡Advertencia! Este colegio tiene ${factCount} registros en facturación.`;
            }
            return confirm(msg); // Retorna true si el usuario confirma, false si cancela
        }
    </script>
</head>
<body>
    <div class="container">
        <h1>Gestión de Colegios</h1>
        
        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($action === 'list'): ?>
            <!-- LISTAR: Mostrar todos los colegios en tabla HTML -->
            <a href="modulo2_crud.php?action=create" class="btn btn-create">Agregar Nuevo Colegio</a>
            
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nombre</th>
                        <th>Departamento</th>
                        <th>Ciudad</th>
                        <th>Grupos</th>
                        <th>Estudiantes</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($colegios as $row): ?>
                        <tr>
                            <td><?php echo $row['id']; ?></td>
                            <td><?php echo htmlspecialchars($row['nombre']); ?></td>
                            <td><?php echo htmlspecialchars($row['departamento']); ?></td>
                            <td><?php echo htmlspecialchars($row['ciudad']); ?></td>
                            <td><?php echo $row['cantidad_grupos']; ?></td>
                            <td><?php echo $row['cantidad_estudiantes']; ?></td>
                            <td>
                                <div class="actions">
                                    <a href="modulo2_crud.php?action=edit&id=<?php echo $row['id']; ?>" class="btn btn-edit">Editar</a>
                                    <!-- Formulario de eliminación con alerta JS -->
                                    <form method="POST" action="modulo2_crud.php" 
                                        onsubmit="return confirmDelete(<?php echo $row['fact_count']; ?>, '<?php echo htmlspecialchars(addslashes($row['nombre'])); ?>')">
                                        <input type="hidden" name="action" value="confirm_delete">
                                        <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                        <button type="submit" class="btn btn-delete">Eliminar</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($colegios)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center;">No hay colegios registrados</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            
        <?php elseif ($action === 'create' || $action === 'edit'): ?>
            <!-- FORMULARIO: Crear o Editar Colegio -->
            <h2><?php echo $action === 'create' ? 'Agregar Nuevo Colegio' : 'Editar Colegio'; ?></h2>
            
            <?php if (isset($errors['general'])): ?>
                <div class="message error"><?php echo htmlspecialchars($errors['general']); ?></div>
            <?php endif; ?>
            
            <form method="POST" action="modulo2_crud.php">
                <input type="hidden" name="action" value="<?php echo $action === 'create' ? 'create' : 'edit'; ?>">
                <?php if ($action === 'edit'): ?>
                    <input type="hidden" name="id" value="<?php echo $colegio['id']; ?>">
                <?php endif; ?>
                
                <div class="form-group">
                    <label for="nombre">Nombre *</label>
                    <input type="text" id="nombre" name="nombre" value="<?php echo htmlspecialchars($colegio['nombre'] ?? ''); ?>" required>
                    <?php if (isset($errors['nombre'])): ?>
                        <div class="error"><?php echo htmlspecialchars($errors['nombre']); ?></div>
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label for="departamento">Departamento *</label>
                    <input type="text" id="departamento" name="departamento" value="<?php echo htmlspecialchars($colegio['departamento'] ?? ''); ?>" required>
                    <?php if (isset($errors['departamento'])): ?>
                        <div class="error"><?php echo htmlspecialchars($errors['departamento']); ?></div>
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label for="ciudad">Ciudad *</label>
                    <input type="text" id="ciudad" name="ciudad" value="<?php echo htmlspecialchars($colegio['ciudad'] ?? ''); ?>" required>
                    <?php if (isset($errors['ciudad'])): ?>
                        <div class="error"><?php echo htmlspecialchars($errors['ciudad']); ?></div>
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label for="cantidad_grupos">Cantidad de Grupos *</label>
                    <input type="number" id="cantidad_grupos" name="cantidad_grupos" min="1" value="<?php echo htmlspecialchars($colegio['cantidad_grupos'] ?? ''); ?>" required>
                    <?php if (isset($errors['cantidad_grupos'])): ?>
                        <div class="error"><?php echo htmlspecialchars($errors['cantidad_grupos']); ?></div>
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label for="cantidad_estudiantes">Cantidad de Estudiantes *</label>
                    <input type="number" id="cantidad_estudiantes" name="cantidad_estudiantes" min="1" value="<?php echo htmlspecialchars($colegio['cantidad_estudiantes'] ?? ''); ?>" required>
                    <?php if (isset($errors['cantidad_estudiantes'])): ?>
                        <div class="error"><?php echo htmlspecialchars($errors['cantidad_estudiantes']); ?></div>
                    <?php endif; ?>
                </div>
                
                <button type="submit" class="btn btn-<?php echo $action === 'create' ? 'create' : 'edit'; ?>">
                    <?php echo $action === 'create' ? 'Guardar' : 'Actualizar'; ?>
                </button>
                <a href="modulo2_crud.php?action=list" class="btn btn-cancel">Cancelar</a>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
