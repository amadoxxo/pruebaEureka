# Prueba Eureka - Solución Técnica

## Descripción del Proyecto
Solución a la prueba técnica para Desarrollador PHP de Eureka Contenidos Educativos S.A.S. Incluye los tres módulos requeridos:
- **Módulo 1**: Homologación de IDs entre tablas de colegios y facturación
- **Módulo 2**: CRUD web funcional para gestión de colegios
- **Módulo 3**: Reporte consolidado cruzando información vía tabla de homologación

---

## Instrucciones de Instalación
1. Asegurar que el servidor XAMPP (Apache + MySQL) esté en ejecución
2. Importar la base de datos `prueba_eureka` con las tablas `colegios`, `facturacion` y `homologacion_colegios` (el usuario ya las creó previamente)
3. Verificar credenciales de base de datos en los archivos PHP (por defecto: `host=localhost`, `user=root`, `password=""`, `dbname=prueba_eureka`)
4. Acceder a los módulos vía navegador:
   - Homologación: `http://localhost/PruebaEureka/modulo1_homologacion.php`
   - CRUD Colegios: `http://localhost/PruebaEureka/modulo2_crud.php`
   - Reporte: `http://localhost/PruebaEureka/modulo3_reporte.php`

---

## Respuestas a Preguntas Técnicas

### Pregunta 1 — Base de datos
La tabla `facturacion` no tiene una clave foránea (FK) hacia `colegios`, usando en su lugar un campo de texto `nombre_colegio`.

**Ventajas de no tener FK**:
- Flexibilidad inicial: Permite facturar a entidades que aún no están registradas en la tabla de colegios, facilitando la migración progresiva de datos históricos.
- No bloquea operaciones: No impide eliminar colegios aunque tengan facturas asociadas (aunque esto es riesgoso si no se valida correctamente).
- Menor fricción en cargas masivas: No requiere que los datos estén consistentes antes de importar.

**Desventajas**:
- Inconsistencia de datos: Como se evidencia en el caso, hay nombres mal escritos, duplicados o que no existen en la tabla de colegios (10 registros en el caso).
- JOINs ineficientes: Obliga a hacer coincidencias por texto en lugar de por IDs numéricos, lo que es más lento y propenso a errores.
- Falta de integridad referencial: No hay garantía de que una factura corresponda a un colegio existente, generando registros huérfanos.

**Rediseño propuesto para evitar el problema**:
1. Agregar un campo `id_colegio INT NULL` a la tabla `facturacion` para referenciar directamente al colegio.
2. Crear la clave foránea:
   ```sql
   ALTER TABLE facturacion 
   ADD CONSTRAINT fk_facturacion_colegio 
   FOREIGN KEY (id_colegio) REFERENCES colegios(id);
   ```
3. Migrar los datos existentes usando el proceso de homologación del Módulo 1:
   ```sql
   UPDATE facturacion f
   JOIN homologacion_colegios h ON f.nombre_colegio = h.nombre_facturacion
   SET f.id_colegio = h.id_colegio
   WHERE h.estado = 'homologado';
   ```
4. Una vez validada la migración, se puede hacer `id_colegio` no nulo y eventualmente eliminar el campo `nombre_colegio` (o mantenerlo como respaldo histórico).

---

### Pregunta 2 — Proceso de homologación
El Módulo 1 implementa coincidencia exacta por nombre usando `COLLATE utf8mb4_bin` para sensibilidad a mayúsculas/minúsculas. En un caso real, los nombres tienen variaciones como espacios extras, tildes, abreviaciones o errores de escritura.

**Estrategia para mejorar el proceso con coincidencias aproximadas**:

1. **Normalización de cadenas en PHP**:
   Crear una función que limpie los nombres antes de comparar:
   ```php
   function normalizarNombre($nombre) {
       // Convertir a mayúsculas, eliminar tildes, recortar espacios
       $nombre = mb_strtoupper(trim($nombre), 'UTF-8');
       $nombre = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nombre);
       // Colapsar múltiples espacios en uno solo
       $nombre = preg_replace('/\s+/', ' ', $nombre);
       return $nombre;
   }
   ```
   Aplicar esta función tanto a los nombres en `colegios` como en `facturacion` antes de buscar coincidencias.

2. **Coincidencia difusa en PHP**:
   Usar la función `levenshtein()` para encontrar nombres con baja distancia de edición (menos de 3 caracteres de diferencia):
   ```php
   $distancia = levenshtein(
       normalizarNombre($nombreFacturacion),
       normalizarNombre($nombreColegio)
   );
   if ($distancia <= 3) {
       // Considerar como coincidencia válida
   }
   ```

3. **Búsqueda fonética en SQL (MySQL)**:
   Usar la función `SOUNDEX()` para agrupar nombres que suenan igual, útil para errores de escritura fonética (ej: "Colégio" vs "Colegio"):
   ```sql
   SELECT * FROM colegios 
   WHERE SOUNDEX(nombre) = SOUNDEX(:nombre_facturacion)
   ```

---

### Pregunta 3 — Seguridad
Tres vulnerabilidades comunes en CRUDs PHP mal implementados y cómo se previnieron en la solución:

1. **Inyección SQL**:
   - *Problema*: Concatenar directamente la entrada del usuario en consultas SQL, permitiendo ejecutar comandos maliciosos.
   - *Prevención*: En todos los módulos se usaron **sentencias preparadas (prepared statements) de PDO** con vinculación de parámetros, ejemplo en Módulo 2:
     ```php
     $stmt = $pdo->prepare("INSERT INTO colegios (nombre, departamento, ciudad, cantidad_grupos, cantidad_estudiantes) 
                           VALUES (?, ?, ?, ?, ?)");
     $stmt->execute([$nombre, $departamento, $ciudad, $cantidad_grupos, $cantidad_estudiantes]);
     ```

2. **XSS (Cross-Site Scripting)**:
   - *Problema*: Mostrar datos de la base de datos sin escape, permitiendo inyectar código JavaScript malicioso.
   - *Prevención*: Se usó `htmlspecialchars($valor, ENT_QUOTES, 'UTF-8')` en todas las salidas HTML, ejemplo en Módulo 2:
     ```php
     <td><?php echo htmlspecialchars($row['nombre']); ?></td>
     ```

3. **Validación de entrada insuficiente**:
   - *Problema*: Aceptar datos inválidos (campos vacíos, números negativos) que pueden corromper la base de datos.
   - *Prevención*: En el Módulo 2 se implementaron validaciones tanto de campos requeridos como de tipos de datos (ej: `cantidad_estudiantes` debe ser numérico y positivo).

---

### Pregunta 4 — Rendimiento
Si la tabla `facturacion` crece a 500,000 registros y `colegios` a 10,000, las consultas del Módulo 3 necesitan optimización para mantener tiempos de respuesta aceptables.

**Estrategia 1: Agregar índices adecuados**
Los índices aceleran las búsquedas y JOINs significativamente:
```sql
-- Índice para búsquedas por nombre en facturación
CREATE INDEX idx_facturacion_nombre_colegio ON facturacion(nombre_colegio);
-- Índice para JOIN con tabla de homologación
CREATE INDEX idx_homologacion_id_colegio ON homologacion_colegios(id_colegio);
CREATE INDEX idx_homologacion_nombre_facturacion ON homologacion_colegios(nombre_facturacion);
-- Índice para filtro por departamento en colegios
CREATE INDEX idx_colegios_departamento ON colegios(departamento);
```

**Estrategia 2: Optimizar la consulta principal del Módulo 3**
- Eliminar la subconsulta correlacionada para `estado_predominante` (que se ejecuta por cada fila de resultado) y reemplazarla por un JOIN con una tabla derivada:
  ```sql
  SELECT 
      c.id,
      c.nombre,
      -- ... otros campos ...
      COALESCE(ep.estado_predominante, 'sin_facturacion') AS estado_predominante
  FROM colegios c
  INNER JOIN homologacion_colegios h ON c.id = h.id_colegio
  LEFT JOIN facturacion f ON f.nombre_colegio = h.nombre_facturacion
  LEFT JOIN (
      SELECT nombre_colegio, estado AS estado_predominante
      FROM (
          SELECT 
              nombre_colegio, 
              estado, 
              COUNT(*) AS cnt,
              ROW_NUMBER() OVER (PARTITION BY nombre_colegio ORDER BY COUNT(*) DESC) AS rn
          FROM facturacion
          GROUP BY nombre_colegio, estado
      ) t WHERE rn = 1
  ) ep ON ep.nombre_colegio = h.nombre_facturacion
  GROUP BY c.id, c.nombre, c.ciudad, c.departamento, c.cantidad_estudiantes, h.estado, h.nombre_facturacion
  ORDER BY c.id;
  ```
- Implementar **paginación** (LIMIT/OFFSET) para no cargar todos los 10,000 colegios en una sola petición.

---

### Pregunta 5 — Criterio propio
**Problemas detectados en el diseño original y solución implementada**:

1. **Campo `nombre_facturacion` NOT NULL en `homologacion_colegios`**:
   - *Problema*: Al insertar registros de tipo `sin_facturacion` (colegios sin facturas), no hay un nombre de facturación que asociar, pero el campo original no permitía valores NULL. Al ejecutar el Módulo 1 inicialmente daba un error de integridad.
   - *Solución*: Modifiqué la tabla para permitir NULL en ese campo: `ALTER TABLE homologacion_colegios MODIFY nombre_facturacion VARCHAR(200) NULL`.

2. **Comparación de cadenas sensible a mayúsculas/minúsculas**:
   - *Problema*: Por defecto, MySQL hace comparaciones de cadenas insensibles a mayúsculas/minúsculas (collation case-insensitive), lo que podría causar coincidencias incorrectas (ej: "Colegio A" vs "COLEGIO A").
   - *Solución*: Usé el operador `COLLATE utf8mb4_bin` en todas las comparaciones de nombres en los tres módulos para asegurar coincidencia exacta según lo requerido.

3. **Idempotencia del Módulo 1**:
   - *Problema*: El requisito exige que el proceso de homologación sea idempotente (ejecutable múltiples veces sin duplicar registros).
   - *Solución*: Antes de insertar un registro en `homologacion_colegios`, elimino cualquier registro existente para ese nombre de colegio en facturación, y limpio los registros de tipo `sin_facturacion` antes de volver a insertarlos. Esto garantiza que no haya duplicados al ejecutar el script varias veces.

---

## Estructura del Proyecto
```
PruebaEureka/
├── modulo1_homologacion.php   # Homologación de IDs (con comentarios en español y estilos CSS)
├── modulo2_crud.php           # CRUD de colegios (con alertas JS y validaciones)
├── modulo3_reporte.php        # Reporte consolidado (usando tabla de homologación)
└── README.md                  # Documentación y respuestas técnicas
```
