# ARP-TEST-001 — Núcleo CRUD, adaptadores SQL e integración

**Estado:** pruebas para revisión; sin fusión automática, etiquetas ni versiones.
**Base:** `507f3e6`, main actualizado tras ARP-INTEGER-002. Rama independiente `feature/arp-test-001`.
**Contrato intacto:** `e3c2c08c93add63faebbe011a7e8e1ec3eccff2c`; los tres pins permanecen iguales.
No se modifica código de `src/`, contrato neutral, fixtures, dependencias ni funcionalidad CRUD.
No se incorpora ni se ejecuta un motor nuevo.

## Inventario y separación

El [inventario por fichero y sección](test-001-inventory.json) clasifica las baterías anteriores y las nuevas.
Un mismo runner histórico puede contener pruebas de más de un nivel; el inventario no lo etiqueta todo como SQL.

| Nivel | Sistema bajo prueba | Evidencia |
| --- | --- | --- |
| Núcleo genérico | Hosts PHP y snapshots con puertos deterministas | Reglas y respuestas PHP; sin SQL. |
| Adaptador SQL | Puertos reales, sin hosts como ejecutores | Persistencia y semántica del motor real. |
| Integración | Núcleo, proveedor SQL y motor real juntos | Comportamiento conjunto, políticas y filas persistidas. |

Las pruebas existentes de codecs, diccionario, resolución CDS y hosts en memoria son genéricas.
Las pruebas de observación MariaDB, bloqueos, integridad, pérdida de conexión y estado transaccional son específicas
del adaptador. El replay de vectores con CrudHost/ListGetHost más MariaDB y el recorrido vertical son integración.
La persistencia del catálogo entre procesos combina observación real y almacenamiento de metadatos y se conserva.

## Proveedor determinista y vectores originales

`tests/Support/DeterministicProvider.php` implementa los puertos existentes `RecordReader`, `RecordWriter`
y `WriteTransaction`; `MemoryPrepared` y `ReadAttempt` utilizan sus interfaces vigentes.
No se modifica ni sustituye CrudHost/ListGetHost, no se invoca el oráculo Python y no se introduce un runtime CRUD
paralelo. Las decisiones de negocio siguen ejecutándose en el núcleo de producción.
El almacenamiento en arrays, los eventos de publicación, las restricciones simuladas y los fallos del puerto son
instrumentación de pruebas. Sus commits, rollback, unicidad o intercalaciones **no prueban persistencia SQL**.

`CoreSuite` traduce únicamente el entorno confiable del fixture a snapshots, decisiones de acceso y fases de los
puertos. Las expectativas no se suministran al proveedor ni al host. El runner las lee para comparar la respuesta
original, estado final, número de escrituras y campos escritos; en lectura, respuesta, número de lecturas y ausencia
de escrituras. No afirma ejecutar todos los detalles privados de instrumentación del oráculo neutral.

Se cargan los **117 vectores CRUD y los 93 de LIST/GET** desde el checkout neutral fijado, sin copiarlos al repositorio.
Cada ID recibe un resultado, incluidos los no ejecutados y las brechas. SHA, limpieza del checkout e inmutabilidad de
cada vector se comprueban; la matriz registra SHA-256 de ambos archivos originales.
Cada caso aplicable se ejecuta dos veces con instancias independientes y se comparan resultados/eventos.
Dos ejecuciones completas también produjeron informes idénticos byte a byte. La suite se ejecutó sin red ni base
de datos, además de localmente con PHP sin drivers PDO SQL instalados.

Los handles simbólicos DELETE se vinculan a evidencia **emitida por el propio núcleo** mediante su API pública;
solo se sustituye el handle de la petición de prueba, nunca la expectativa. No se falsifica estado interno ni se usa
reflexión. La evidencia se prepara antes de activar la fase de fallo/revocación simulada y se restaura el estado
inicial del escenario. Las pruebas de binding a otra clave, estado adyacente, caducidad e historial pasan por el núcleo.

Los tokens criptográficos opacos literales de LIST/GET no pueden fijarse mediante los puertos actuales.
Sus vectores se inventarían como no ejecutados; no se normalizan tokens para hacer pasar expectativas literales.
Los roundtrips criptográficos, keyset y claves ocultas siguen probándose con las baterías genéricas anteriores y
las integraciones reales. Defaults verificados, delegación, ciertas fases de contexto, recibos parciales y activación
modular tampoco se implementan para ampliar cifras de replay.

## Duplicación eliminada y obligaciones comunes

`NeutralReadCases::replay` contiene una sola selección y un solo bucle de comparación para los **26 IDs directos**
que antes se repetían en `providers/mariadb/tests/listget.php` y `providers/tests/sql-listget.php`.
Ambos runners lo utilizan con su propia factoría de hosts/proveedor. Se conservan exactamente sus IDs, peticiones
y respuestas esperadas. Las particularidades de SQL, permisos, DDL y fallos de cada motor no se ocultan en ese helper.

`AdapterObligations::run` es una batería común ejecutable directamente sobre los puertos, sin invocar los hosts.
Recibe catálogo, lector, escritor y dos callbacks de preparación/observación; no contiene SQL ni ramas por motor.
Los datos básicos proceden de los fixtures originales; el parámetro adversarial con comilla es un dato propio de la
obligación de binding, no una copia ni una alteración de un vector neutral.

Las diez obligaciones son:

1. GET con identidad exacta.
2. Lecturas que no persisten cambios.
3. INSERT y roundtrip de parámetro con comilla.
4. Orden numérico, anticipación y continuación keyset.
5. Restricción de filas antes de LIMIT.
6. Unicidad y rechazo atómico de duplicados.
7. Rollback completo tras INSERT y UPDATE seguidos de un fallo de callback.
8. UPDATE vacío que conserva el estado; la prueba de cero DML permanece también en la batería nativa anterior.
9. SET parcial real que conserva campos omitidos.
10. DELETE por identidad exacta.

La batería valida el proveedor determinista como autocontrol, etiquetado **sin SQL**, y se ejecuta también contra
MariaDB con observación independiente de filas: esas diez ejecuciones sí son evidencia del adaptador real.
PostgreSQL, MySQL y SQL Server podrán reutilizarla al suministrar sus puertos/callbacks; no se ejecutaron aquí ni
se les atribuye soporte nuevo. Un adaptador no queda certificado solo por superar estas diez obligaciones.

El inventario exige además pruebas propias del motor para aislamiento/bloqueos entre conexiones, FK/CHECK y efectos
auxiliares, protección DDL/observación/permisos y pérdida de conexión/certeza del commit. Se conservan las pruebas
MariaDB existentes para esas obligaciones, sin sustituirlas por el proveedor determinista.

## Cobertura antes y después

La [matriz por cada ID y nivel](test-001-conformance.json) distingue ejecución, pase, brecha y no ejecución.
El generador compara los informes nuevos con la matriz anterior `integer-conformance.json`: falla si falta
cualquier pase nativo anterior, grupo de evidencia, regresión de certeza o comprobación numérica.
Un pase simulado nunca puede rescatar una pérdida de cobertura nativa. Se comprobó también el rechazo
del generador al retirar deliberadamente un pase nativo de una copia del informe y al etiquetar una simulación
como evidencia SQL. Los informes originales y el contrato permanecieron intactos en esas pruebas negativas.

| Evidencia | Antes | Después |
| --- | --- | --- |
| Replay neutral CRUD sin SQL | Sin runner de los 117 vectores | 96 ejecutados: 94 pases, 2 brechas; 21 no ejecutados. |
| Replay neutral LIST/GET sin SQL | Sin runner de los 93 vectores | 49 pases; 44 no ejecutados. |
| Obligaciones comunes del adaptador | Sin batería independiente | 10 simuladas y 10 MariaDB reales, separadas. |
| Vectores integrados CRUD MariaDB | 52/117 | 52/117, sin pérdidas. |
| Vectores integrados LIST/GET MariaDB | 29/93 | 29/93, sin pérdidas. |
| Grupos de evidencia nativa conservados | 22 | 22. |
| Regresiones de certeza transaccional | 10 | 10. |
| Comprobaciones INTEGER MariaDB | 178, ocho capacidades | 178, ocho capacidades. |

Los 65 vectores CRUD y 64 de LIST/GET sin replay **integrado** continúan identificados como tales.
Algunos ahora tienen cobertura genérica: eso no cambia su estado nativo. No se suman los niveles para afirmar
conformidad total ni se suman grupos, comprobaciones y vectores como si fueran unidades equivalentes.

También se preservan las 428 aserciones del diccionario, 25 regresiones PHP, 2981 comprobaciones INTEGER,
80 resoluciones CDS y 11 de intake, 14 codecs y las pruebas genéricas de publicación/limpieza anteriores.
Verificar los hashes de 76 referencias UPDATE sigue sin equivaler a ejecutarlas.

## Dos brechas del núcleo observadas

Ambos casos eran no ejecutados en la matriz nativa anterior. El nuevo replay estricto revela diferencias
preexistentes; esta tarea no modifica funcionalidades ni expectativas para resolverlas.

| Vector | Contrato original | Núcleo actual |
| --- | --- | --- |
| `delete-blind-baseline` | `PRECONDITION_REQUIRED`, invalid-data | `INPUT_SHAPE`, invalid-data. |
| `create-physical-range-incompatible` | `INCOMPATIBLE_STORAGE` | `INVALID_DEFINITION`. |

El primero rechaza el miembro baseline por shape cerrado antes de comprobar la precondición DELETE.
El segundo interpreta el rango observado sin binding de diccionario como base y clasifica el contexto más amplio
como debilitamiento de restricción. Son brechas reales de clasificación, no pruebas verdes ni omisiones silenciosas.
Sus estados finales y contadores se contrastan igualmente con el vector original.

`composer test:core` es **estricto y termina con código 1** por esas dos diferencias.
`composer test:core:baseline` requiere explícitamente `--allow-known-gaps`: permite comprobar estabilidad del resto,
pero las dos entradas siguen con `passed: false` en la matriz y aparecen en stderr.
Solo admite las firmas actuales exactas; un resultado nuevo, una regresión de estado o una brecha resuelta exige
revisar la entrada, en vez de aceptarla automáticamente. `composer test` y CI invocan esa opción de línea base de
forma explícita. No se renombra ni modifica ningún resultado esperado del contrato.

## Reproducción

Con un checkout limpio del contrato fijado y las dependencias ya bloqueadas:

```bash
composer install
composer validate --no-check-publish
export ARP_CONTRACT_PATH=/path/to/arp-contract
export ARP_WRITE_CONTRACT_PATH="$ARP_CONTRACT_PATH"
export ARP_CORE_REPORT=/tmp/core-contract-report.json
composer test
composer test:core  # salida 1 esperada: las dos brechas descritas
composer test:adapter-port
composer lint
python3 providers/sql-listget-docker.py \
  --contract "$ARP_CONTRACT_PATH" --listget-contract "$ARP_CONTRACT_PATH" \
  --output /tmp/arp-test-reads-new
python3 providers/crud-docker.py \
  --contract "$ARP_CONTRACT_PATH" --output /tmp/arp-test-crud-new
php tests/build-test-matrix.php \
  --core "$ARP_CORE_REPORT" \
  --crud /tmp/arp-test-crud-new/crud-mariadb-report.json \
  --integer /tmp/arp-test-crud-new/integer-mariadb-report.json \
  --reads /tmp/arp-test-reads-new/listget-evidence.json \
  --output /tmp/test-001-conformance.json
git diff --check
```

Los runners nativos utilizan los recursos Docker aislados y marcadores de propiedad existentes. No se instalaron
motores, drivers ni dependencias adicionales y se preservaron todos los contenedores preexistentes.
MariaDB ejecutada: 12.3.3; PHP/mysqlnd nativos: 8.5.12. El proveedor determinista también pasó en PHP CLI 8.5.11.
Composer validate, la línea base explícita, PHPCS PSR-12 y `git diff --check` son correctos.
CI separa el job genérico sin servicio SQL del job MariaDB y muestra informes de cada nivel.
Los informes históricos y sus cifras permanecen como evidencia anterior, sin reescribirlos.

## Límites

La separación reduce el coste de repetir reglas del núcleo en futuras integraciones, no elimina la necesidad de
probar SQL real. Las 21/44 omisiones genéricas y las dos brechas están identificadas individualmente en la matriz.
No se implementan nuevos puertos de tokens, reglas de negocio, defaults, delegación, recibos internos o módulos
para hacer aplicables más vectores. Tampoco se modifica el contrato ni se introduce otro motor.
No hay una afirmación de conformidad integral con los 117/93 vectores; sí evidencia reproducible de que las garantías
nativas previamente demostradas permanecen y de qué partes del núcleo se comprueban ahora sin MariaDB.