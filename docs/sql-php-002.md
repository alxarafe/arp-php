# ARP-SQL-PHP-002 — Cuatro motores PDO con un único LIST/GET

## Preparación y alcance

Rama `feature/arp-sql-php-002`, base `main` `c140c60d05cf00ec42b0373c8850690979b368ec`, con PR #9 fusionada.
El árbol inicial estaba limpio. Se actualizó main por avance rápido. Se eliminó únicamente la rama local
`feature/arp-sql-php-001`: su PR estaba fusionada por squash, su árbol era idéntico al main actualizado y no tenía
worktree ni cambios pendientes. Las demás ramas/worktrees se conservan; no se presume fusión por su nombre.
No se ha hecho staging, commit, push, PR, merge de la funcionalidad, etiquetas ni releases.

Toda la información auxiliar está bajo `~/Desarrollo/tmp/arp-sql-php-002/` y la entrega verificable bajo
`~/Desarrollo/tmp/arp-sql-php-002-final-review/`. Los contratos y los repositorios Java/.NET no se han modificado.
`contract.ref` conserva `7a62bba0e8f84dbc198e837baf63fc7e0d8722e1` y `listget-contract.ref` conserva
`eb38cb96e6ccd218734df2a181908de46067e0c7`. Se usan checkouts limpios de esos SHA, montados en solo lectura.
Las fixtures originales no se copian al repositorio ni se editan.

## Arquitectura y equivalencia

```text
SqlRecordReader (abstract, único algoritmo PDO LIST/GET)
├── PostgreSqlRecordReader
├── MySqlRecordReaderBase (abstract)
│   ├── MySqlRecordReader
│   └── MariaDbRecordReader
└── SqlServerRecordReader
```

Se conservan byte por byte todos los archivos de `src/`, incluidos `ListGetHost`, `PhysicalCatalog`, el parser,
la validación escalar, la publicación y los puertos neutrales. También permanecen intactos `SqlReadAttempt`,
`SqlPreparedRead`, las fixtures, los contratos y los scripts de prueba ordinarios existentes.
`composer.json` solo añade rutas de autoload para los proveedores opcionales; no exige extensiones adicionales.

Los únicos cambios de `SqlRecordReader` son los dos previstos en ARP-SQL-PHP-001:

1. La comparación de claves se extrae a `keyComparison`, protegido y sobrescribible. El comportamiento por defecto
   sigue usando comparación de tuplas y los mismos parámetros. SQL Server expande GET a conjunciones y LIST a
   predicados lexicográficos OR/AND, sin OFFSET variable ni ordenar cadenas en vez de las columnas físicas.
2. `valueExpression` recibe el tipo lógico; la llamada le pasa el tipo ya resuelto para cada columna.

No se cambia el ciclo DATA/GUARD, la planificación, el límite/anticipación, la lectura ni la decodificación comunes.
No hay interfaces de dialectos, un segundo algoritmo ni otro host. `SqlMetadata` contiene únicamente plumbing
acotado de metadatos y propiedad de conexiones/cursores; no ejecuta LIST/GET. Se corresponde con el helper de
metadatos de las referencias Java/.NET.

Se verificaron las implementaciones publicadas en main, sin escribir en sus repositorios. `references.json`
conserva URL, SHA de blob y SHA-256 del contenido consultado, incluidos estos componentes:

- Java: MySqlRecordReaderBase, MySqlObservationProviderBase, SqlServerRecordReader y postgresql.sql.
  [Implementaciones fusionadas](https://github.com/alxarafe/arp-java/tree/main/providers).
- .NET: SqlRecordReader y sus puntos protegidos.
  [Implementación fusionada](https://github.com/alxarafe/arp-dotnet/tree/main/providers).

## Especializaciones

### PostgreSQL 18.6

Se traslada el SQL de observación pg_catalog de la referencia como recurso de implementación, sin duplicar fixtures.
Conserva columnas físicas y nulabilidad, restricciones conocidas, PK ordenada, índices representables y foreign keys.
Los CHECK y dominios no se convierten en pruebas de dominios que no se puedan demostrar.
Se exige SELECT de tabla completo, tabla ordinaria sin herencia/particiones y sin RLS; las extensiones fuera del
perfil no se amplían implícitamente. Se rechaza metadata de índices incompleta en la preparación de lectura.

DATA/GUARD usan READ COMMITTED READ ONLY, ACCESS SHARE, statement_timeout, lock_timeout y DateStyle ISO.
La observación usa una tercera conexión independiente mientras DATA conserva la protección. pg_backend_pid
verifica independencia real entre conexiones simultáneas. CAST a text conserva números exactos; BOOLEAN decodifica
solo true/false y DATE usa el formato ISO. No hay intermediario float.

El lector común ya exponía `execute` pero no un modificador FROM. Para respetar el límite de dos cambios comunes,
la especialización incorpora ONLY en su override de execute únicamente al SELECT acotado generado por el algoritmo.
Se saltan los identificadores entrecomillados y escapados al reconocer FROM; no se interpreta SQL del solicitante.
Las consultas realmente ejecutadas están en queries.json: todas las lecturas de registros PostgreSQL contienen ONLY.
También se probó una tabla con FROM y comillas dentro del identificador. La construcción del SELECT sigue siendo única.

### MySQL 8.4 y MariaDB 12.3

`MySqlRecordReaderBase` comparte la especialización previa; las clases concretas seleccionan familia/versión.
`MySqlObservationProviderBase` conserva el mapeo y persistencia semántica anteriores de MariaDB, sin duplicarlos.
Los namespaces y constructores públicos MariaDB permanecen disponibles por Composer.

Cada motor verifica su versión/familia. MariaDB usa max_statement_time; MySQL usa max_execution_time en milisegundos.
Ambos usan lock_wait_timeout, innodb_lock_wait_timeout, READ COMMITTED READ ONLY, identificadores con backticks,
LIMIT parametrizado y metadata locks retenidos mediante SELECT LIMIT 0 y SHOW CREATE TABLE.
Se exige InnoDB ordinario sin particiones y concesión directa completa de SELECT. Las concesiones parciales o
visibilidad incompleta no prueban autoridad. No se amplían roles implícitamente para fabricar una prueba de acceso.

La observación distingue IS_GENERATED de MariaDB y GENERATION_EXPRESSION de MySQL, conservando columnas invisibles,
PK, índices, FK y restricciones desconocidas. DECIMAL e INT64 se leen como cadenas. TINYINT(1) conserva INT8 físico;
solo un campo lógico BOOLEAN con almacenamiento admitido y valor exacto 0/1 se devuelve como booleano.
Los índices con prefijos no se presentan como completos. La batería MariaDB anterior completa sigue pasando,
incluida la recuperación del catálogo persistente tras recrear el contenedor sin introspección.

### SQL Server 2022

La observación se basa en sys.tables, sys.columns, sys.types, sys.indexes y sys.index_columns de la referencia.
Se exige SELECT completo y VIEW DEFINITION de base de datos; sin esa autoridad se rechaza la observación.
Se excluyen tablas temporales de sistema, optimizadas en memoria, temporales de historial, particiones múltiples,
RLS, tipos de usuario sin prueba e índices filtrados/deshabilitados/no representables como catálogo completo.

Se escapan corchetes duplicando ], se usa OFFSET 0/FETCH NEXT parametrizado y la comparación lexicográfica
protegida para claves compuestas. La conversión depende del tipo lógico: DATE usa estilo 23; DECIMAL comprueba el
tipo real de almacenamiento antes de convertir a nvarchar; el resto se convierte sin float. Unicode usa UTF-8 en PDO
y nvarchar en las fixtures. BIT se decodifica de manera estricta a bool.

El contrato contiene precios que requieren hasta 24 dígitos integrales y 18 fraccionarios en distintos registros.
SQL Server limita DECIMAL a precisión 38: ninguna geometría fija conserva todos esos extremos conjuntamente.
Se reutiliza la solución demostrada en Java/.NET: sql_variant con DECIMAL/NUMERIC de geometría propia por registro.
Cada valor original sí cabe en precisión 38. Un sql_variant con texto u otra clase física se rechaza en SQL y falla
la respuesta entera. No se reduce precisión ni se altera una fixture. También se admiten DECIMAL/NUMERIC ordinarios
cuando la columna y los datos caben en su geometría física.

La protección transaccional es SELECT TOP(1) constante WITH (TABLOCK,HOLDLOCK). El bloqueo S de tabla sobrevive
al statement y al commit de DATA gracias a GUARD independiente. **Puede bloquear escrituras**, no solo DDL.
Se verificó un UPDATE desde otra conexión: timeout 1222 mientras GUARD permanecía activo después del commit DATA.
SQL Server no ofrece READ ONLY equivalente: no se promete esa característica; el algoritmo solo emite lecturas
además de su configuración transaccional. Se configuran XACT_ABORT, LOCK_TIMEOUT y READ COMMITTED.

## PDO y entorno aislado

El anfitrión conserva PHP 8.5.11 y sus extensiones originales: PDO sin controladores de base de datos.
Se reutilizó la imagen arp-cds-read-003-php-test:local con pdo_mysql y las cuatro imágenes de bases de datos locales.
Faltaban pdo_pgsql y pdo_sqlsrv. Solo se preparó una imagen adicional, arp-sql-php-002-test:local, con el Dockerfile
opcional en providers/integration. No se instaló nada en el PHP del anfitrión ni se cambió la suite ordinaria.
La imagen ya preparada se reutiliza; el runner no la reconstruye ni descarga motores.

Entorno verificado: PHP 8.5.12, pdo_mysql/pdo_pgsql 8.5.12, pdo_sqlsrv 5.13.3 y Microsoft ODBC Driver 18.
Los SHA de las imágenes efectivamente usadas están en los informes de aislamiento por motor.

Dos diferencias concretas de PDO requirieron adaptación protegida en los proveedores, sin cambiar el algoritmo:

- pgsql/sqlsrv no proporcionan el ATTR_AUTOCOMMIT de MySQL. Sus overrides de connection comprueban conexión nueva,
  propiedad exclusiva, ausencia de transacción, driver esperado y ausencia de persistencia PDO; sqlsrv además
  comprueba @@TRANCOUNT=0. No se simula autocommit ni se ignora un error del atributo.
- pdo_sqlsrv rechaza ATTR_PERSISTENT=false en las opciones del constructor. El arnés omite esa opción solo allí
  y verifica el valor false consultable. Configura preparación nativa con SQLSRV_ATTR_DIRECT_QUERY=false y UTF-8.
  El primer bootstrap falló por esa opción, no por la base de datos; se corrigió sin reconstruir el entorno.

Se verifican conexiones simultáneas distintas con pg_backend_pid, CONNECTION_ID o @@SPID. Se consumen y cierran
cursores antes de solapar protección. Begin, commit, rollback y closeCursor tienen retornos/errores comprobados;
las inyecciones false y excepción eliminan output. Se vuelve a comprobar autorización/generación después del commit.
La limpieza obligatoria del host y SqlReadAttempt permanece intacta. PDO no tiene close() de conexión comprobable:
se hace rollback explícito y se liberan las referencias propias; no se promete acuse de cierre de socket ni control
del pooling interno de ODBC. La factory debe transferir conexiones nuevas al mismo servidor sin referencias retenidas.

La publicación sigue siendo local a la instancia/proceso PHP. No se promete coordinación entre procesos,
vigilancia DDL, transacciones distribuidas ni snapshot de datos entre páginas.

Fuentes de controlador consultadas: [PDO PostgreSQL](https://www.php.net/manual/en/ref.pdo-pgsql.php),
[atributos pdo_sqlsrv](https://learn.microsoft.com/en-us/sql/connect/php/pdo-setattribute?view=sql-server-ver17),
[consulta de atributos](https://learn.microsoft.com/en-us/sql/connect/php/pdo-getattribute?view=sql-server-ver17)
y [pdo_sqlsrv 5.13.3](https://pecl.php.net/package/pdo_sqlsrv).

## Pruebas y resultados

La suite ordinaria se ejecutó en el CLI del anfitrión sin Docker ni conexión de datos:
327 vectores DICT, 25 pruebas runtime, 80 resoluciones CDS y 11 intake más regresiones existentes,
la suite neutral LIST/GET, 106 pruebas estructurales del contrato y 12 del guard de artefactos físicos: todo correcto.
No se añadieron requisitos de extensiones a composer ni se modificaron esas pruebas.

La integración nueva comparte únicamente el arnés, no la clasificación de cobertura: cada motor produce sus propias
respuestas, consultas ejecutadas, evidencia, resultados de esquema e informe de aislamiento.
Se verificaron de forma nativa PK simple/compuesta, GET encontrado/ausente/inaccesible, todos los valores originales,
Unicode, nulos, BOOLEAN y DECIMAL, keyset por PK física y restricciones anteriores al límite/anticipación,
proyección derivada, autorización independiente LIST/GET, campos, revocación e invalidación y cambios ABA.

Cada nuevo motor ejecutó diez fallos de begin DATA/GUARD, commit DATA, rollback y cierre de cursor, con false y
excepción. Se inyectaron nulo imposible, columna ausente y PK duplicada. Se introdujo un dato real ilegible en la
segunda fila: NaN PostgreSQL, BOOLEAN=2 MySQL y sql_variant no decimal SQL Server. No se devuelve página parcial.
Se comprobó DDL incompatible desde otra conexión durante DATA y después de su commit con GUARD activo;
se observaron los códigos nativos 55P03/1205/1222. Los cambios de esquema disparan invalidación/detección de deriva;
una columna añadida no amplía proyecciones y una retirada no usa el catálogo antiguo como fallback.
PK ausente, índices incompletos y tenant_id estrechado a SMALLINT se rechazaron; las fuentes lógicas no se alteraron.

MariaDB ejecutó su batería anterior íntegra: observación, 16 grupos de persistencia y recuperación en otro contenedor,
y la suite LIST/GET previa. Los otros motores no heredan ese resultado ni una prueba de persistencia no realizada.
MySQL tuvo un aviso de aislamiento al coincidir su medición con la limpieza legítima del runner PostgreSQL propio;
se repitió secuencialmente. Su ejecución final conserva contenedores y volúmenes preexistentes. Todos los informes
finales de aislamiento son correctos y no quedan contenedores/redes de estas ejecuciones activos.

| Motor real | Respuestas válidas | Directos | Equivalentes | Pendientes |
| --- | ---: | ---: | ---: | ---: |
| mariadb | 158 | 26 | 53 | 9 |
| postgresql | 152 | 26 | 52 | 10 |
| mysql | 152 | 26 | 52 | 10 |
| sqlserver | 152 | 26 | 52 | 10 |

Versiones reales: PostgreSQL 18.6, MySQL 8.4.13, MariaDB 12.3.3 y SQL Server 2022 CU28 (16.0.4325.4).
Cada respuesta registrada valida con cero errores contra el esquema original del pin LISTGET. Las comparaciones
directas usan solicitudes y expected.response originales íntegros; no se reemplazan tokens para llamarlas directas.
Los equivalentes usan tokens/estados propios u obligaciones comunes demostradas. No se declara conformidad completa.

## Reproducción

```sh
# Una sola preparación aislada, si no existe ya la imagen.
docker build --pull=false -t arp-sql-php-002-test:local providers/integration

# Elegir un motor por invocación y ejecutarlos secuencialmente para medir aislamiento.
python3 providers/integration/run.py \
    --engine postgresql \
    --contract "$HOME/Desarrollo/tmp/arp-contract-php-config-001-validation" \
    --listget-contract "$HOME/Desarrollo/tmp/arp-java-listget-001-contract" \
    --output "$HOME/Desarrollo/tmp/arp-sql-php-002/postgresql"

# Regresión MariaDB completa, además de los otros tres motores.
python3 providers/sql-listget-docker.py \
    --contract "$HOME/Desarrollo/tmp/arp-contract-php-config-001-validation" \
    --listget-contract "$HOME/Desarrollo/tmp/arp-java-listget-001-contract" \
    --php-image arp-sql-php-002-test:local \
    --output "$HOME/Desarrollo/tmp/arp-sql-php-002/mariadb-regression"
```

El runner valida SHA y limpieza de los contratos, usa nonce antes de DDL, etiquetas de propiedad, red sin puertos
publicados y montajes del repositorio/contratos en solo lectura. Conserva logs/respuestas fuera del repositorio y
retira solo contenedores, volúmenes anónimos y red creados por su ejecución. No se escriben credenciales en artefactos.
Las escrituras de fixtures, permisos e inyecciones pertenecen al arnés sobre bases efímeras propias; los lectores
no implementan escrituras de negocio.

## Límites y pendientes

Se mantiene el perfil anterior: PK entera no nula de uno a siete componentes, PHP de 64 bits, igualdades enteras
para restricciones de filas y campos publicados enteros/DECIMAL/STRING/TEXT/BOOLEAN/DATE. CHAR fijo, dominios no
probados, vistas, particiones/herencia y políticas de filas no traducibles no obtienen capacidad por aproximación.
No se añade CRUD, HTTP, ORM, administración de permisos, otros motores ni activación modular.
La aplicación valida composición/dependencias antes de registrar destinos; los equivalentes modulares no acreditan
un motor MODULES ni su caché general.

La persistencia física anterior se conserva sin cambios: MariaDB se prueba íntegramente, y la clave neutral
existente admite PostgreSQL. No se amplía PhysicalStoreKey para mysql/sqlserver porque está fuera de los cambios
neutrales autorizados; esas observaciones funcionan con PhysicalCatalog en memoria, sin afirmar persistencia
configurable nueva para esos dos dialectos. No se han realizado pruebas de recuperación por proceso en ellos.

Los nueve pendientes anteriores siguen pendientes. PostgreSQL/MySQL/SQL Server mantienen además LIST limit=1
como pendiente nativo: no se convierte en aprobado por la prueba MariaDB o por la suite neutral. La prueba de
registro de límites inválidos sí es neutral y se identifica expresamente como equivalente común, no replay SQL.
Los archivos coverage-88.json por motor registran evidencia/límite para cada uno de los 88 IDs originales.

## Matriz completa

D = directo; E = equivalente; P = pendiente. Las evidencias detalladas por motor están en coverage-88.json.

| Vector | MariaDB | PostgreSQL | MySQL | SQL Server |
| --- | --- | --- | --- | --- |
| `get-authorized-hidden-key` | E | E | E | E |
| `get-null-preserved` | E | E | E | E |
| `get-historical-rating` | E | E | E | E |
| `get-exact-decimal` | E | E | E | E |
| `get-unicode-and-empty-values` | E | E | E | E |
| `get-unknown-reference` | D | D | D | D |
| `get-existing-but-row-denied` | E | E | E | E |
| `get-scope-binding-denied` | E | E | E | E |
| `get-principal-binding-denied` | E | E | E | E |
| `get-policy-binding-denied` | E | E | E | E |
| `get-projection-binding-denied` | P | P | P | P |
| `get-simple-typed-key` | D | D | D | D |
| `get-composite-typed-key` | D | D | D | D |
| `get-missing-typed-key` | D | D | D | D |
| `get-raw-hidden-key-rejected` | D | D | D | D |
| `key-null` | D | D | D | D |
| `key-incomplete` | D | D | D | D |
| `key-reordered` | D | D | D | D |
| `key-extra` | D | D | D | D |
| `key-number-not-string` | D | D | D | D |
| `key-wrong-type` | D | D | D | D |
| `key-out-of-range` | D | D | D | D |
| `list-first-page` | E | E | E | E |
| `list-second-page` | E | E | E | E |
| `list-last-page` | E | E | E | E |
| `list-server-clamps-limit` | E | E | E | E |
| `list-empty-authorized-set` | E | E | E | E |
| `list-numeric-order-simple-keys` | E | E | E | E |
| `list-derived-projection` | E | E | E | E |
| `list-limit-zero` | D | D | D | D |
| `list-limit-negative` | D | D | D | D |
| `list-limit-bool` | D | D | D | D |
| `list-limit-fraction` | D | D | D | D |
| `list-limit-too-large` | D | D | D | D |
| `list-limit-null` | D | D | D | D |
| `list-arbitrary-filter` | D | D | D | D |
| `list-arbitrary-sort` | D | D | D | D |
| `list-fields-injection` | D | D | D | D |
| `list-offset-rejected` | D | D | D | D |
| `cursor-unknown` | D | D | D | D |
| `cursor-null` | D | D | D | D |
| `cursor-limit-changed` | E | E | E | E |
| `cursor-context-changed` | E | E | E | E |
| `operation-not-authorized` | E | E | E | E |
| `target-not-registered` | E | E | E | E |
| `module-disabled` | E | E | E | E |
| `operation-not-enabled` | E | E | E | E |
| `derived-operation-not-exposed` | E | E | E | E |
| `selected-field-not-readable` | E | E | E | E |
| `client-context-injection` | D | D | D | D |
| `client-context-cannot-authorize` | P | P | P | P |
| `input-null` | D | D | D | D |
| `read-capability-not-proven` | E | E | E | E |
| `table-without-key` | E | E | E | E |
| `invalid-definition` | E | E | E | E |
| `unauthorized-definition-error-concealed` | E | E | E | E |
| `physical-incompatibility` | E | E | E | E |
| `stale-catalog-generation` | P | P | P | P |
| `stale-record-reference` | E | E | E | E |
| `stale-continuation` | E | E | E | E |
| `invalidation-during-read` | E | E | E | E |
| `authorization-lost-before-publication` | E | E | E | E |
| `provider-failure` | E | E | E | E |
| `last-row-decode-failure-no-partial-page` | E | E | E | E |
| `unexpected-null-nonnullable` | E | E | E | E |
| `duplicate-record-identity` | E | E | E | E |
| `new-column-not-published-after-rebuild` | E | E | E | E |
| `removed-published-column-rebuild-rejected` | E | E | E | E |
| `public-application-without-principal` | E | E | E | E |
| `empty-get-still-checks-publication-generation` | E | E | E | E |
| `context-changed-before-publication` | P | P | P | P |
| `list-limit-one` | E | P | P | P |
| `list-after-last-empty` | P | P | P | P |
| `invalid-server-limit-registration` | E | E | E | E |
| `modules-catalog-list` | E | E | E | E |
| `modules-catalog-get-denied-independently` | E | E | E | E |
| `modules-catalog-get-authorized` | E | E | E | E |
| `modules-reports-derived-list` | E | E | E | E |
| `modules-reports-get-unavailable` | E | E | E | E |
| `modules-reports-inactive` | E | E | E | E |
| `modules-all-inactive` | P | P | P | P |
| `modules-reports-without-catalog` | P | P | P | P |
| `modules-undeclared-projection-dependency` | P | P | P | P |
| `modules-deactivation-during-read` | E | E | E | E |
| `modules-row-restriction-still-authoritative` | E | E | E | E |
| `modules-client-cannot-activate` | P | P | P | P |
| `modules-field-access-still-authoritative` | E | E | E | E |
| `modules-permission-revoked-before-publication` | E | E | E | E |