# ARP-SQL-PHP-001 — LIST/GET neutral, PDO y MariaDB

**Nota de alcance:** este documento registra la implementación y evidencia anteriores a INTEGER.
[ARP-INTEGER-002](integer-002.md) y su [matriz](integer-conformance.json) describen el candidato activo;
las cifras, tipos y revisiones originales que siguen se conservan como evidencia histórica.

## Estado y autoridad

Rama `feature/arp-sql-php-001`, basada en `main` `43bb5f2` (árbol inicial limpio).
Se actualizó main mediante avance rápido desde origin. No se ha hecho staging, commit, push, PR ni publicación.
No se modificaron contratos ni repositorios Java/.NET. Los auxiliares y evidencias viven bajo `~/Desarrollo/tmp/`.

- `contract.ref`: `7a62bba0e8f84dbc198e837baf63fc7e0d8722e1`, sin cambios.
- `listget-contract.ref`: `eb38cb96e6ccd218734df2a181908de46067e0c7`, mismo pin que las referencias.
- Referencia arquitectónica Java: `main` `825012f7fb747c459f69be48c9a026cdaa1eb3e4`.
- Referencia arquitectónica .NET: `main` `4bc9842c3241ff39f34be9f35ac823fe1ea7c3f7`.

El contrato LISTGET sigue siendo un borrador fijado por SHA; este trabajo no altera su estado normativo.

## Componentes y equivalencia

| Responsabilidad existente en Java/.NET | Implementación PHP |
| --- | --- |
| Host neutral, autorización y tokens | `src/Cds/ListGetHost.php` |
| Puerto de catálogo y publicación | `ReadCatalog`, `ReadPublication` y `PhysicalCatalog` |
| Planificación neutral y lectura protegida | `RecordReader`, `PreparedRead`, `ReadPlan`, `ReadAttempt` |
| Un algoritmo SQL | `providers/sql/src/SqlRecordReader.php`, abstracta |
| Estado SQL privado y ciclo obligatorio | `SqlPreparedRead`, `SqlReadAttempt` |
| Sintaxis, protección y codecs MariaDB | `MariaDbRecordReader extends SqlRecordReader` |
| Observación real | `MariaDbObservationProvider` existente |

El núcleo no importa PDO ni clases de infraestructura SQL/MariaDB. Las interfaces nuevas son los puertos neutrales
obligatorios; no hay interfaz de dialectos, despachador de dialectos ni segundo algoritmo.
La especialización usa métodos protegidos para transacción, protección, observación, independencia,
expresiones SQL, límite, capacidad de columna y decodificación física.

Se reutilizan `ReadResolver`, `ReadResult`, `ReadPublication`, `Parser`, `Scalar`, `Number`, `Projection`,
`CatalogBuilder`, `MariaDbObservationProvider` y toda la persistencia física anterior.
Los ocho componentes neutrales antes alojados en `providers/mariadb/src` pasan a `src/Cds/Physical`:
`PhysicalCatalog`, `PhysicalObservationProvider`, `InvalidatablePhysicalProvider`, `ObservationException`,
`FilePhysicalStore`, `PersistedPhysicalProvider`, `PhysicalStoreKey` y `PhysicalYaml`.
Conservan el namespace `Alxarafe\Arp\Cds\Physical` y se resuelven por Composer sin wrappers ni copias.
Los consumidores que incluían rutas PHP directamente deben actualizar esas rutas; los imports públicos se conservan.
Regenerar el autoload con `composer install` o `composer dump-autoload` tras actualizar.

La observación añade hechos escalares DECIMAL y DATE, sin convertirlos en prueba del dominio de edición.
La prueba anterior de DECIMAL se conserva y actualiza su expectativa a ese hecho observable.
TINYINT(1) sigue siendo INT8; no se infiere BOOLEAN por el nombre nativo. CHECK permanece restrictivo.
Los cursores de observación también se cierran y se comprueban sus retornos y los del commit.

## Invocación y perfil soportado

`ListGetHost::invoke(string $request, array $context): array` recibe JSON estricto SERVICES-002.
Reutiliza el parser exacto: Unicode, duplicados, profundidad y presupuesto de 1 MiB.
El contexto confiable contiene `identity` (identidad completa, principal/tenant/ámbito) y `policyRevision`.
Una aplicación pública puede usar identidad vacía, manteniendo autorización y registro explícitos.

El constructor recibe catálogo, lector, registro activo y Closure de autorización. Cada destino registra
`projection`, `defaultLimit`, `maxLimit` y `fields`. La Closure recibe destino, operación y contexto y devuelve
`allowed`, el conjunto completo `fields`, y `equalities`: objeto de igualdades enteras, vacío para permiso explícito
sin restricciones, o null cuando la política no puede ejecutarse. No se filtran filas después de paginar.
Todas las modificaciones de política/composición, incluidas ABA, deben pasar por `changeAccess`.
Las decisiones se comparan con tipos exactos, sin igualdad laxa PHP entre cadenas, números o booleanos.
La aplicación valida sus dependencias modulares antes de registrar una composición.

Se autorizan operación y campos antes de exponer errores de metadatos. GET usa toda la PK física entera,
visible tipada u opaca si cualquier componente es oculto. LIST ordena numéricamente por toda la PK física,
aplica las igualdades antes de LIMIT, usa keyset estricto y obtiene como máximo límite + una fila autorizada.
El ORDER BY referencia las columnas físicas, no los aliases CAST de salida. Valores y límites son parámetros
PDO nativos; los identificadores proceden de registros confiables y se escapan duplicando el delimitador.

Los valores se obtienen mediante CAST a texto UTF-8 y el validador escalar existente, nunca mediante float.
INT64/DECIMAL siguen siendo cadenas exactas; BOOLEAN acepta solo 0/1 físicos y devuelve bool;
nulos requieren nulabilidad demostrada. No se revalidan restricciones actuales de edición: rating histórico 11
se conserva. Proyecciones derivadas mantienen exactamente sus campos; claves internas no aparecen en values.

Los tokens AES-256-GCM usan secreto aleatorio local al host y nonce de 12 bytes. Vinculan propósito,
destino, proyección, identidad completa, revisión/época de política, generación y límite efectivo de continuación.
El payload binario contiene generación y hasta siete componentes INT64, manteniendo el máximo de 128 caracteres.
No hay caché de filas ni de tokens. Recrear el host revoca su evidencia. Tokens desconocidos o fuera de contexto
no confirman existencia; una generación antigua autenticada se rechaza. Cada uso vuelve a autorizarse.

## Ciclo PDO demostrado

1. Factory de conexiones nuevas a la misma instancia MariaDB, propias, no persistentes,
   sin referencias retenidas y con autocommit inicial.
2. DATA: excepciones, parámetros nativos, strings exactos, READ COMMITTED y transacción READ ONLY real.
3. Protección física mediante SELECT LIMIT 0 y SHOW CREATE TABLE; comprobación de InnoDB ordinario,
   ausencia de particiones y concesión de SELECT completa. Se rechazan índices observados incompletos y PK no
demostrada.
4. Reobservación con el proveedor existente y conexión independiente, mientras DATA conserva su metadata lock.
5. SELECT parametrizado acotado, consumo y cierre comprobado del cursor y decodificación completa.
6. GUARD en otra conexión/transacción; se comprueba también CONNECTION_ID distinto de DATA y se solapa su MDL.
7. Comprobaciones finales de autorización, composición, época, identidad de snapshot y generación local.
8. Commit DATA obligatorio; se comprueban retorno, estado de transacción y permanencia de GUARD.
9. Nueva comprobación después del commit; rollback GUARD explícito y comprobado y liberación de referencias PDO.
10. Última comprobación local antes de devolver la respuesta. Todo fallo elimina output, incluidas páginas vacías.

No se confía en el destructor para decidir éxito transaccional. Se comprueban retornos false y excepciones de
beginTransaction/commit/rollBack/closeCursor. La limpieza intenta finalizar ambas conexiones aunque falle una.
PDO carece de close() comprobable para conexiones: se cierran los statements, se verifica el rollback y se liberan
todas las referencias locales. La factory debe transferir propiedad exclusiva y no retener conexiones/statements;
no se promete un acuse verificable de cierre del socket. Un rollback fallido descarta la respuesta aunque PDO
realice después limpieza implícita. No hay conexión persistente ni reutilización de una transacción del consumidor.

Fuentes específicas de PDO consultadas:
[commit](https://www.php.net/manual/en/pdo.commit.php),
[conexiones](https://www.php.net/manual/en/pdo.connections.php) y
[closeCursor](https://www.php.net/manual/en/pdostatement.closecursor.php).
Las pruebas nativas confirman las decisiones de este adaptador; no se infiere equivalencia automática con JDBC.

La publicación de catálogo sigue siendo local a la instancia/proceso PHP. El anfitrión debe serializar el uso
compartido. No se promete sincronización entre procesos, vigilancia DDL, transacciones distribuidas ni snapshot
de datos entre páginas. La observación detecta deriva bajo MDL; después de DDL se requiere invalidar/reconstruir.
Una sustitución de tabla, esquema o fuente debe retirar primero la composición local correspondiente.

## Ejecución y evidencias

Pruebas ordinarias sin Docker ni conexión a bases de datos:

```sh
ARP_CONTRACT_PATH="$HOME/Desarrollo/tmp/arp-contract-php-config-001-validation" composer test
ARP_CONTRACT_PATH="$HOME/Desarrollo/tmp/arp-contract-php-config-001-validation" bash tests/structure.sh
```

Prueba opcional real, con checkouts limpios de ambos contratos en sus SHA:

```sh
python3 providers/sql-listget-docker.py \
    --contract "$HOME/Desarrollo/tmp/arp-contract-php-config-001-validation" \
    --listget-contract "$HOME/Desarrollo/tmp/arp-java-listget-001-contract" \
    --output "$HOME/Desarrollo/tmp/arp-sql-php-001-review"
```

Se reutilizan `arp-cds-read-003-php-test:local` (PHP 8.5 con pdo_mysql/mysqlnd) y `mariadb:12.3`.
No se construyen imágenes ni se descargan motores. Se verificó MariaDB `12.3.3-MariaDB-ubu2404`, imagen
`sha256:4ebbcf100a3ba320766dfa0e1af008df12261301db6ef72798c7650f70794aed`.
El CLI anfitrión tiene PHP 8.5.11 y PDO, pero no pdo_mysql; las pruebas nativas usan la imagen existente.
Composer del anfitrión falla por falta de Normalizer; Composer de la imagen valida composer.json y genera autoload.

El runner usa red y contenedores propios, nonce antes de DDL, etiquetas de propiedad y contratos/repositorio
montados en solo lectura. Ejecuta observación existente, persistencia seed, recuperación en otro contenedor,
y LIST/GET con ambas PK. Cada ejecución conserva su almacén de prueba bajo la salida auxiliar y retira solo
sus contenedores/red. No se cambian las fixtures, ni se copian al repositorio. Los productos/definiciones/expected
se leen de los originales. Las filas auxiliares de extremos y las inyecciones de fallos pertenecen al arnés.

Resultados finales conservados en `~/Desarrollo/tmp/arp-sql-php-001-final-review/`:

- 327 casos DICT existentes; 25 pruebas runtime; 80 resoluciones CDS y 11 intake, más regresiones existentes.
- Suite neutral nueva: límites, tokens INT64 extremos, autorización, generaciones y fallos de finalización.
- 106 pruebas estructurales del contrato y 12 pruebas del guard de artefactos físicos.
- Observación real preexistente y 16 grupos de persistencia, incluida recuperación sin introspección tras recrear
contenedor.
- Ambas PK: recorrido completo, GET, restricciones SQL, proyecciones, codecs exactos y 26 vectores directos.
- Fallos DATA/GUARD: begin falso/excepción, commit falso/excepción, rollback falso/excepción,
  closeCursor falso/excepción; revocación, invalidación y ABA durante la lectura/commit.
- Nulo imposible, columna ausente e identidad duplicada inyectados; BOOLEAN=2 real en la segunda fila;
  nunca se devuelve una primera fila parcial.
- DDL incompatible desde otra conexión: timeout 1205 tanto con DATA como después de su commit mientras GUARD sigue
activo.
- MyISAM, índices incompletos, PK ausente, estrechamiento incompatible y deriva por DDL rechazados;
  reconstruir tras ADD COLUMN no amplía la proyección y DROP COLUMN no usa metadatos antiguos.
- 158 respuestas registradas validadas con jsonschema contra el esquema LISTGET original fijado: cero errores.
- Lint PHP de los 24 archivos PHP afectados, longitudes/finales de línea y git diff --check correctos.
  No hay formateador configurado; se aplicó .editorconfig sin reformatear archivos ajenos.

Los fallos iniciales del arnés se corrigieron antes de estos resultados: la inyección afectaba también al commit
de observación y el almacén persistente se reutilizaba entre ejecuciones. La salida final contiene solo evidencias
exitosas y un manifiesto con checksums; la salida de trabajo anterior conserva el diagnóstico histórico.

## Límites conocidos

Solo MariaDB 12.3 e InnoDB ordinario. Igualdades enteras del servidor; PK entera no nula, simple o compuesta,
de uno a siete componentes y runtime PHP de 64 bits. Columnas publicadas: enteros, DECIMAL, STRING/TEXT, BOOLEAN y DATE.
No hay soporte gestionado para CHAR fijo, vistas, particiones, PK no enteras, UINT64 o predicados arbitrarios.
La falta de capacidad rechaza la invocación; no produce una consulta menos restringida.
No se implementan CRUD, escrituras de negocio, HTTP, ORM, otros motores, gestión de permisos ni activación modular.
La integración modular presupone composición validada por la aplicación; los casos equivalentes no acreditan
un motor de dependencias ni la caché general MODULES. No se declara conformidad completa con los 88 vectores.

## Matriz de los 88 vectores

**26 directos, 53 equivalentes y 9 pendientes.**

Directo significa solicitud y respuesta esperada originales comparadas íntegramente. Equivalente significa
un escenario ejecutado con igual obligación, pero tokens/composición/estado reales distintos del arnés original.
Pendiente no acredita el vector aunque exista código relacionado. `coverage-88.json` conserva la misma clasificación.

| Vector | Cobertura | Evidencia |
| --- | --- | --- |
| `get-authorized-hidden-key` | equivalente | E1 |
| `get-null-preserved` | equivalente | E1 |
| `get-historical-rating` | equivalente | E1 |
| `get-exact-decimal` | equivalente | E1 |
| `get-unicode-and-empty-values` | equivalente | E1 |
| `get-unknown-reference` | directa | D |
| `get-existing-but-row-denied` | equivalente | E2 |
| `get-scope-binding-denied` | equivalente | E3 |
| `get-principal-binding-denied` | equivalente | E3 |
| `get-policy-binding-denied` | equivalente | E3 |
| `get-projection-binding-denied` | pendiente | P1 |
| `get-simple-typed-key` | directa | D |
| `get-composite-typed-key` | directa | D |
| `get-missing-typed-key` | directa | D |
| `get-raw-hidden-key-rejected` | directa | D |
| `key-null` | directa | D |
| `key-incomplete` | directa | D |
| `key-reordered` | directa | D |
| `key-extra` | directa | D |
| `key-number-not-string` | directa | D |
| `key-wrong-type` | directa | D |
| `key-out-of-range` | directa | D |
| `list-first-page` | equivalente | E4 |
| `list-second-page` | equivalente | E4 |
| `list-last-page` | equivalente | E4 |
| `list-server-clamps-limit` | equivalente | E5 |
| `list-empty-authorized-set` | equivalente | E6 |
| `list-numeric-order-simple-keys` | equivalente | E4 |
| `list-derived-projection` | equivalente | E7 |
| `list-limit-zero` | directa | D |
| `list-limit-negative` | directa | D |
| `list-limit-bool` | directa | D |
| `list-limit-fraction` | directa | D |
| `list-limit-too-large` | directa | D |
| `list-limit-null` | directa | D |
| `list-arbitrary-filter` | directa | D |
| `list-arbitrary-sort` | directa | D |
| `list-fields-injection` | directa | D |
| `list-offset-rejected` | directa | D |
| `cursor-unknown` | directa | D |
| `cursor-null` | directa | D |
| `cursor-limit-changed` | equivalente | E8 |
| `cursor-context-changed` | equivalente | E8 |
| `operation-not-authorized` | equivalente | E9 |
| `target-not-registered` | equivalente | E10 |
| `module-disabled` | equivalente | E11 |
| `operation-not-enabled` | equivalente | E12 |
| `derived-operation-not-exposed` | equivalente | E12 |
| `selected-field-not-readable` | equivalente | E9 |
| `client-context-injection` | directa | D |
| `client-context-cannot-authorize` | pendiente | P2 |
| `input-null` | directa | D |
| `read-capability-not-proven` | equivalente | E13 |
| `table-without-key` | equivalente | E14 |
| `invalid-definition` | equivalente | E15 |
| `unauthorized-definition-error-concealed` | equivalente | E16 |
| `physical-incompatibility` | equivalente | E17 |
| `stale-catalog-generation` | pendiente | P3 |
| `stale-record-reference` | equivalente | E18 |
| `stale-continuation` | equivalente | E18 |
| `invalidation-during-read` | equivalente | E19 |
| `authorization-lost-before-publication` | equivalente | E20 |
| `provider-failure` | equivalente | E21 |
| `last-row-decode-failure-no-partial-page` | equivalente | E22 |
| `unexpected-null-nonnullable` | equivalente | E23 |
| `duplicate-record-identity` | equivalente | E23 |
| `new-column-not-published-after-rebuild` | equivalente | E24 |
| `removed-published-column-rebuild-rejected` | equivalente | E15 |
| `public-application-without-principal` | equivalente | E25 |
| `empty-get-still-checks-publication-generation` | equivalente | E19 |
| `context-changed-before-publication` | pendiente | P4 |
| `list-limit-one` | equivalente | E26 |
| `list-after-last-empty` | pendiente | P5 |
| `invalid-server-limit-registration` | equivalente | E27 |
| `modules-catalog-list` | equivalente | E28 |
| `modules-catalog-get-denied-independently` | equivalente | E29 |
| `modules-catalog-get-authorized` | equivalente | E28 |
| `modules-reports-derived-list` | equivalente | E7 |
| `modules-reports-get-unavailable` | equivalente | E12 |
| `modules-reports-inactive` | equivalente | E11 |
| `modules-all-inactive` | pendiente | P6 |
| `modules-reports-without-catalog` | pendiente | P7 |
| `modules-undeclared-projection-dependency` | pendiente | P8 |
| `modules-deactivation-during-read` | equivalente | E30 |
| `modules-row-restriction-still-authoritative` | equivalente | E31 |
| `modules-client-cannot-activate` | pendiente | P9 |
| `modules-field-access-still-authoritative` | equivalente | E9 |
| `modules-permission-revoked-before-publication` | equivalente | E20 |

D: solicitud y respuesta esperada originales comparadas íntegramente, sin sustituir tokens.

E1: Recorrido de productos originales y GET de cada referencia: igualdad exacta de todos los campos publicados.

E2: GET tipado de la fila other-scope original en ambos diseños: found:false en tenant 7.

E3: GET con referencia real y otra identidad completa o policyRevision: found:false; también prueba neutral de
identidad.

P1: La vinculación está implementada; falta aislar el cambio de proyección en una prueba nativa.

E4: Recorrido keyset real de las cinco filas originales, en ambos diseños, comparando valores y número de filas.

E5: LIST limit=100 produce límite efectivo 3 y tres registros.

E6: Igualdad SQL tenant_id=9: página autorizada vacía sin lectura sin restricciones.

E7: products.summary devuelve exactamente sku y name, desde la proyección derivada original.

E8: Continuación real usada con límite 3 u otra identidad: INVALID_CURSOR.

E9: Autorizer deniega la operación o no concede todos los campos: UNAVAILABLE sin output.

E10: Destino desconocido: UNAVAILABLE.

E11: changeAccess retira products.summary; summary falla y catalog permanece disponible.

E12: GET de summary no está expuesto; además LIST permitido no concede GET en el autorizador.

P2: Falta reproducir la combinación exacta de contexto inyectado y permiso denegado.

E13: Política de filas null, índices incompletos y motor MyISAM: CAPABILITY_UNAVAILABLE.

E14: DROP PRIMARY KEY, invalidación y nueva llamada real: CAPABILITY_UNAVAILABLE.

E15: DROP COLUMN sku e invalidación: INVALID_DEFINITION sin fallback.

E16: Tras retirar sku, la denegación del autorizador devuelve UNAVAILABLE antes del error de definición.

E17: tenant_id SMALLINT frente al dominio lógico INT32: INCOMPATIBLE_STORAGE; se restaura la tabla.

P3: No se inyectó un catálogo neutral que publique una generación ya retirada al iniciar la llamada.

E18: Invalidación y cambio del número de componentes PK: tokens reales devuelven GENERATION_EXPIRED.

E19: Invalidación tras SELECT, también en GET sin filas, y desde commit DATA: fallo sin output.

E20: Revocación después de adquirir GUARD y antes de publicar: UNAVAILABLE; la prueba neutral también revoca desde
close.

E21: Excepción PDO después de SELECT y diez fallos de transacción/cursor: READ_FAILED sin detalles privados.

E22: available=2 real en la segunda fila TINYINT(1): ROW_DECODE_FAILED, sin primera fila parcial.

E23: Inyección en el resultado PDO de nulo imposible/columna ausente/PK duplicada: se descarta la página completa.

E24: ADD COLUMN añadido, deriva detectada, invalidación y reconstrucción: campo nuevo no publicado.

E25: Context.identity vacío con política explícita y registro activo: LIST real correcto.

P4: El contexto PHP se captura por valor; no se reprodujo el cambio dinámico del arnés original.

E26: LIST real limit=1 y GET de referencia INT64 mínima; límite efectivo uno.

P5: Falta una página vacía después de un cursor válido tras borrar las filas posteriores.

E27: Prueba neutral: default=0, default>max y max=101 rechazados al registrar.

E28: Registro de aplicación que apunta a products.catalog: LIST y GET reales; sin motor modular.

E29: Autorizer permite LIST y deniega GET; ambas llamadas reales comprueban la independencia.

P6: Falta probar la composición completamente vacía.

P7: La validación de dependencias de módulos corresponde a la aplicación; no hay motor modular nuevo.

P8: La aplicación debe validar la composición; no se implementó un validador modular.

E30: changeAccess durante SELECT incrementa la época, incluso restaurando la misma composición: GENERATION_CHANGED.

E31: Registro activo y proyección derivada mantienen restricciones tenant_id=7 antes de LIMIT y anticipación.

P9: Se rechazan miembros no permitidos; falta reproducir la inyección modular exacta.