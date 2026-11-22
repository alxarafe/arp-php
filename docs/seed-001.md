# ARP-SEED-001 — Carga inicial CSV para PHP/MariaDB

**Estado:** implementación para revisión; sin fusión automática, versión, etiqueta ni release.
**Base:** `main` tras ARP-TEST-002 (`3df4004`), rama independiente `feature/arp-seed-001`.
**Contrato de escritura:** `e3c2c08c93add63faebbe011a7e8e1ec3eccff2c` (`write-contract.ref`).

## Alcance

La entrega añade una preparación de demostración reproducible para `demo.items` y dos piezas reutilizables:

- `CsvTable`: intake CSV UTF-8 con cabecera, separador configurable y reglas RFC 4180.
- `CsvSeedLoader`: relación de cabeceras con campos ARP, prevalidación completa y carga mediante el
  `CrudHost::invoke(CREATE)` existente.

No se añade un segundo motor de escritura, una API HTTP de importación, una interfaz de usuario, migraciones de
esquema ni un ETL general. El contrato neutral, Java, .NET y Angular no se modifican.

## CSV admitido

- UTF-8 válido; se acepta y retira un BOM UTF-8 inicial.
- Cabecera obligatoria. Un archivo totalmente vacío se rechaza; una cabecera sin filas produce cero registros.
- Separador predeterminado `;`, configurable en `CsvTable::parse()` y `CsvSeedLoader`.
- Campos entrecomillados con `"`, comillas escapadas como `""`, separadores y saltos de línea dentro del campo.
- Los valores permanecen como cadenas exactas. No se convierten cadenas vacías en `null` ni números en `float`.
- Cada fila debe tener exactamente el número de columnas de la cabecera.
- Se rechazan cabeceras vacías o duplicadas, campos desconocidos, columnas obligatorias ausentes, valores fuera de
  tipo/restricción y claves primarias duplicadas dentro del CSV, indicando fila y columna.

La fila de cabecera es la 1. Las filas de datos se numeran por su línea física de inicio, de modo que un campo
entrecomillado con saltos de línea no desplaza erróneamente el diagnóstico.

## API reutilizable

```php
use Alxarafe\Arp\Dictionary\Cds\Seed\CsvSeedLoader;

$loader = new CsvSeedLoader(
    $crudHost,
    $catalog,          // ReadCatalog con la observación física vigente
    'demo.items',      // target registrado en CrudHost
    'demo.items.public',
    ['identity' => 'seed-job', 'policyRevision' => 'p1'],
    ';'                // separador opcional
);
$result = $loader->load($csvBytes);
// ['processed' => 30, 'inserted' => 30, 'rejected' => 0, 'errors' => []]
```

`inspect()` valida el archivo completo sin DML. `load()` llama primero a `inspect()` y solo inserta si no hay
errores. La prevalidación de cada fila reutiliza `CrudHost::validateCreate()`, que ejecuta el mismo camino de
validación de CREATE (campos, tipos, restricciones, obligatorios, ámbito, autorización y regla de negocio) dentro
de una transacción que termina sin escrituras. Las inserciones reales se ejecutan después con CREATE.

`CsvTable::parse()` puede usarse de forma independiente cuando la aplicación solo necesita el intake CSV.

## Demostración `demo.items`

Archivo de ejemplo: `demo/seed/demo.items.csv`, con 30 registros y columnas `id;code;rating`. Incluye campos
entrecomillados, un separador dentro de un campo, comillas escapadas y UTF-8 para ejercitar el intake.

La preparación se ejecuta exclusivamente sobre una base de datos cuyo nombre empieza por `arp_seed_`:

```bash
export ARP_WRITE_CONTRACT_PATH=/ruta/al/arp-contract-fijado
export ARP_DB_PASSWORD='...'
php demo/seed/prepare.php \
  --database=arp_seed_demo \
  --host=127.0.0.1 --port=3306 --user=root
```

El CSV se puede cambiar con `--csv=/ruta/alternativa.csv`. La contraseña no se imprime; los mensajes la redactan.

Secuencia de la preparación:

1. Comprueba que el checkout del contrato coincide con `write-contract.ref` cuando dispone de `.git`.
2. Si la base no existe, la crea con `utf8mb4`, crea la marca de propiedad `__arp_seed_owner` y ejecuta el SQL de
   ejemplo `examples/demo/sql/mariadb.sql`.
3. Construye el diccionario, las definiciones lógicas/proyecciones y el catálogo físico reales del contrato.
4. Valida el CSV completo antes de insertar.
5. Inserta mediante ARP y responde con JSON: base, estado, procesados, insertados y rechazados.

### Atomicidad y repetibilidad

- Una base nueva se crea dentro de la misma ejecución. Ante cualquier error posterior, solo se elimina esa base
  nueva, previa comprobación de que fue creada por la ejecución. No se elimina ninguna base preexistente.
- Una base preexistente sin marca de propiedad se rechaza antes de cualquier DDL.
- Una base preexistente con marca y filas se considera ya inicializada: la segunda ejecución no inserta, no
  duplica y no borra; informa `already-initialized`.
- Una base preexistente con marca pero vacía se rechaza para evitar una inicialización parcial no acreditada.
- Si la validación falla, no se ejecuta ningún CREATE y la base nueva se descarta.

Esto evita dejar una demostración parcialmente inicializada sin introducir un borrado implícito sobre bases ajenas.

## Pruebas

Genéricas, sin SQL ni servicio de base de datos:

```bash
composer test:seed
```

Cubren CSV correcto y vacío, separador predeterminado y alternativo, comillas, caracteres especiales, UTF-8,
saltos de línea, errores de cabecera y estructura, restricciones del diccionario, claves duplicadas, enteros
exactos de 64 bits, ausencia de DML en la prevalidación y ausencia de escrituras ante CSV inválido.

Integración real con MariaDB 12.3:

```bash
php providers/tests/seed-mariadb.php
```

Comprueba persistencia real, tipos exactos, rechazos sin escritura, duplicados de base, repetibilidad de la
preparación, ausencia de borrado implícito, rechazo y conservación de una base ajena y descarte de una base nueva
tras un fallo de validación. `providers/crud-docker.py` ejecuta esta fase junto con las pruebas CRUD/INTEGER en un
entorno aislado; CI también la ejecuta en el servicio MariaDB efímero.

## Límites

- No se implementa carga atómica multi-fila del propio `CsvSeedLoader`; la atomicidad del entorno de demostración
  se consigue descartando la base nueva ante un error. Un consumidor que reutilice el cargador sobre una base
  existente debe aportar su propia unidad transaccional o estrategia de compensación.
- La detección de duplicados dentro del CSV es previa. Un duplicado ya persistido en la base se detecta en el
  CREATE real como rechazo; la demostración no llega a ese estado porque solo inicializa bases nuevas.
- No hay importación HTTP, subida desde navegador, descubrimiento de archivos, ETL, migraciones ni cargas masivas
  de producción.
- Solo se demuestra MariaDB; no se atribuye esta evidencia a otros adaptadores.