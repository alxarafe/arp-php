# ARP-TEST-002 — Cierre de discrepancias contractuales del núcleo

Base: `3b7d55c`, main tras la fusión de ARP-TEST-001. Rama independiente `feature/arp-test-002`.
Contrato neutral intacto: `e3c2c08c93add63faebbe011a7e8e1ec3eccff2c`; sin cambios en sus tres pins,
fixtures, expectativas, dependencias o motores. Sin nuevas operaciones CRUD.

## Correcciones

`delete-blind-baseline`: una petición DELETE sin evidencia recibe `PRECONDITION_REQUIRED`, aunque incluya un
baseline arbitrario. Ese baseline nunca acredita una lectura ni habilita la escritura. La excepción de shape se
limita al diagnóstico de ausencia de precondición; claves inválidas, miembros desconocidos, peticiones malformadas
y baseline junto a una evidencia presente siguen rechazándose. Se conserva el circuito de autorización y evidencia.

`create-physical-range-incompatible`: el resolutor del perfil de escritura distingue el intervalo observado de
almacenamiento de una restricción lógica declarada. En campos INTEGER sin binding al diccionario, comprueba que
los límites lógicos sean válidos y representables antes de comprobar su cabida física. Un dominio lógico válido
que exceda la columna produce `physical-incompatible`, que el host clasifica como `INCOMPATIBLE_STORAGE`.
Los intervalos invertidos, límites malformados o dominios INTEGER imposibles siguen siendo errores de definición.
El debilitamiento de restricciones de un diccionario sigue siendo inválido. Se conserva también la expectativa
neutral del perfil de solo lectura sobre contextos que debilitan una base física sin binding.

No se recorta el dominio lógico a la intersección para permitir escrituras. Una columna real MariaDB
`TINYINT UNSIGNED` se observa como `0..255`; una declaración `0..1000` se rechaza incluso si el candidato es `3`.
El rechazo ocurre antes de DML y conserva las filas. No se cambian transacciones, concurrencia ni publicación.

## Comprobaciones estrictas

Se elimina `KnownGaps`, sus dos excepciones y el script de tolerancia. `composer test:core` compara directamente
las expectativas originales y falla ante cualquier discrepancia. Rechaza argumentos, incluido el antiguo flag
de tolerancia, con salida 2. `composer test` lo incluye; CI tiene además un paso explícito obligatorio sin tolerancia.

Las 29 regresiones focalizadas de `tests/core-classification.php` comprueban variantes de ausencia de evidencia,
entradas malformadas, validación lógica, incompatibilidad física, bindings de diccionario y conservación del perfil
de lectura. Los rechazos DELETE y CREATE comprueban cero transacciones del proveedor y filas intactas.
Son pruebas genéricas: sus eventos no son evidencia SQL.

En MariaDB hay cuatro comprobaciones adicionales con trazado DML y observación independiente de filas:
DELETE sin evidencia con baseline; miembro desconocido; baseline junto a evidencia válida; CREATE sobre dominio
incompatible con candidato físicamente válido. Las cuatro acreditan cero DML y persistencia inalterada.
`delete-blind-baseline` se incorpora además al replay integrado del vector neutral original.
La prueba física adicional no se contabiliza como replay del fixture sintético `0..4`.

## Matriz y conservación de garantías

La [matriz por vector y nivel](test-002-conformance.json) distingue simulación, adaptador real e integración.
El [inventario de niveles](test-001-inventory.json) sigue vigente. Las matrices de ARP-TEST-001 y ARP-INTEGER-002
se conservan como historia, sin reescribir resultados anteriores.

| Evidencia | ARP-TEST-001 | ARP-TEST-002 |
| --- | --- | --- |
| Núcleo CRUD, sin SQL | 96 ejecutados: 94 pases, 2 brechas | 96 pases; 0 discrepancias; 21 no ejecutados de 117 |
| Núcleo LIST/GET, sin SQL | 49 pases de 93 | 49 pases; 0 discrepancias; 44 no ejecutados |
| Integración CRUD MariaDB | 52 pases de 117 | 53 pases; 64 no ejecutados |
| Integración LIST/GET MariaDB | 29 pases de 93 | 29 pases; 64 no ejecutados |
| Obligaciones comunes del adaptador | 10 simuladas / 10 reales | 10 simuladas / 10 reales |
| Grupos nativos CRUD | 22 | 22 |
| Regresiones de certeza transaccional | 10 | 10 |
| Aserciones INTEGER MariaDB | 178, ocho capacidades | 178, ocho capacidades |
| Rechazos focalizados nuevos | Sin esta batería | 29 genéricos / 4 integrados reales |

Se conservan los grupos nativos de atomicidad, bloqueos entre conexiones, concurrencia, autorización, integridad,
SET parcial, UPDATE vacío, efectos auxiliares, recuperación ante fallos y certeza del commit. También se repiten
observación física, persistencia del catálogo entre procesos y reinicio de contenedor, layouts de clave simple y
compuesta, cursores y los recorridos INTEGER sin signo de 64 bits. La batería común ejecuta directamente los puertos
reales y mantiene su etiquetado de persistencia.

El generador exige todos los pases genéricos ejecutados anteriormente, rechaza excepciones y exige cero fallos.
Se verifica además que rechaza un informe con una excepción obsoleta, sin modificar los informes originales.
También conserva sus guardas de pases nativos previos, diez obligaciones reales, 22 grupos, diez regresiones de
certeza y 178 aserciones numéricas; exige los cuatro rechazos nativos sin DML ni mutaciones.

Se repiten asimismo las 428 aserciones del diccionario, 25 regresiones PHP, 2981 comprobaciones INTEGER,
80 resoluciones CDS y 11 de intake, 14 codecs y las baterías genéricas previas de LIST/GET y CRUD.
Composer validate, PHPCS PSR-12 y `git diff --check` pasan. Pruebas genéricas sin red ni servicio SQL;
MariaDB real 12.3.3, PHP/mysqlnd 8.5.12. Los runners aislados conservan los contenedores preexistentes.

## Reproducción

Con dependencias instaladas y checkout limpio del contrato fijado:

```bash
export ARP_CONTRACT_PATH=/path/to/arp-contract
export ARP_WRITE_CONTRACT_PATH="$ARP_CONTRACT_PATH"
export ARP_CORE_REPORT=/tmp/core-contract-report.json
composer validate --no-check-publish
composer test:core
composer test
composer lint
python3 providers/sql-listget-docker.py \
  --contract "$ARP_CONTRACT_PATH" --listget-contract "$ARP_CONTRACT_PATH" \
  --output /tmp/arp-test-002-reads
python3 providers/crud-docker.py \
  --contract "$ARP_CONTRACT_PATH" --output /tmp/arp-test-002-crud
php tests/build-test-matrix.php \
  --core "$ARP_CORE_REPORT" \
  --crud /tmp/arp-test-002-crud/crud-mariadb-report.json \
  --integer /tmp/arp-test-002-crud/integer-mariadb-report.json \
  --reads /tmp/arp-test-002-reads/listget-evidence.json \
  --output /tmp/test-002-conformance.json
git diff --check
```

## Límites

Cero discrepancias se refiere a todos los vectores genéricos aplicables, no a los 117/93 íntegros. Se mantienen
21/44 omisiones genéricas identificadas individualmente: tokens opacos literales, ciertos estados protegidos,
delegación, defaults y fases modulares sin puertos existentes. No se debilitan expectativas para hacerlos pasar.
Las 64/64 omisiones de replay integrado siguen separadas de la cobertura genérica. No se suman niveles ni se
cuentan simulaciones como persistencia. Los hashes de referencias UPDATE no equivalen a ejecutarlas.
Solo se ejecuta MariaDB; no se atribuye evidencia nueva a otros adaptadores. Sin fusión automática.