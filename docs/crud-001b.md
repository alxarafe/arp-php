# ARP-CRUD-001B — Primer recorrido real PHP/MariaDB

**Estado:** implementación para revisión; sin fusión automática, versión, etiqueta ni release.
**Base PHP:** `ba69e467f55c6a8658361e32c3b350ce2d45a9aa`.
**Contrato de escritura:** `c1831e2580a0d92ad3ead1deff719162a8e615e3`, PR #19 fusionada.

## Resultado y límites de la evidencia

CREATE, UPDATE parcial y DELETE físico funcionan sobre la tabla real `demo.items` en MariaDB 12.3.3, con
PHP 8.5.12/mysqlnd 8.5.12. LIST/GET siguen utilizando el lector existente y permiten comprobar la persistencia.
Las definiciones de Data Dictionary, Logical Definition, Projection y SQL proceden del contrato fijado, sin copias
locales ni modificaciones de expectativas. No se modifica Java, .NET, el contrato neutral ni el cliente.

El comprobador nativo ejecuta **45 de los 110 vectores de escritura compartidos** y compara sus respuestas
normalizadas con las expectativas originales, además del estado realmente persistido. Los **65 restantes no se
marcan conformes**: son supuestos de otros motores, delegación, defaults/overrides sintéticos, claves opacas u otras
fases del arnés neutral que este primer perfil no mapea directamente. La
[matriz completa](crud-conformance.json) registra cada identificador, los casos ejecutados y los no ejecutados.
No se afirma conformidad integral con los 110 vectores ni implementación general de SERVICES-001/002.

Se ejecutan **11 vectores originales de codec** y se verifican identidades/hashes de los **76 vectores UPDATE
históricos referenciados**, sin duplicarlos. Verificar sus hashes no equivale a ejecutar esos 76 escenarios en PHP.
Se añaden **21 grupos de evidencia nativa**, incluidos concurrencia, confirmación, integridad y fallos reales de
conexión. Algunos cubren propiedades de escenarios compartidos sin pretender ejecutar su instrumentación privada.

## Integración con las capacidades existentes

- `CrudHost` compone el `ListGetHost` existente y los puertos `RecordWriter`/`WriteTransaction`.
- `MariaDbRecordWriter` reutiliza la factoría PDO, preparación, quoting, observación y protección física de
  `MariaDbRecordReader` y sus bases SQL/MySQL. No se implementa otro lector ni otra arquitectura de catálogo.
- `SqlWriteTransaction` concentra INSERT/UPDATE/DELETE parametrizados y la lectura bloqueada, sin API SQL libre
  en el dominio. No se añaden dependencias de Composer.
- `PhysicalCatalog(..., writeOperations: true)` activa el reconocimiento de las cinco operaciones en el mismo
  resolver. El valor por defecto sigue siendo `false`, conservando el comportamiento y las pruebas de lectura.
- La declaración `operations` no autoriza ni ejecuta por sí sola. El registro enumera proyección, campos y
  operaciones disponibles y la aplicación suministra autorización por operación, campos y ámbito.
- Se mantienen los contratos anteriores fijados por `contract.ref`/`listget-contract.ref`; `write-contract.ref`
  fija separadamente el candidato revisado. No se actualizan esquemas ni se adopta `INTEGER`.

Las aplicaciones pueden seguir despachando sus operaciones especializadas mediante manejadores propios.
Este incremento implementa el perfil gestionado; no añade un motor de delegación, reglas o workflows ni atribuye
sus garantías transaccionales a manejadores externos. No impone estructura de carpetas a la aplicación.

## Recorrido y controles de escritura

Cada ejecución obtiene un snapshot lógico/proyección vigente, valida exposición y autorización y utiliza una
conexión PDO nueva, no persistente, sin transacción previa y con prepares nativos. Se comprueban MariaDB 12.3,
InnoDB, PK/índices completos, ausencia de particionado, codec y nulabilidad observados y metadatos vigentes.
La transacción READ WRITE usa READ COMMITTED, SQL estricto y límites de espera; mantiene protección de metadatos.
Una conexión independiente intenta DDL y recibe timeout mientras se valida; una invalidación local antes del
DML aborta sin efectos y exige reconstruir el catálogo.

Se exige un presupuesto físico de fila de hasta 1 MiB antes de leer el estado completo.
El proveedor rechaza identidades generadas, columnas calculadas, triggers, cascadas entrantes y tipos fuera del
perfil. Comprueba visibilidad suficiente de triggers mediante privilegios directos y exige utf8mb4 para textos.
No presupone ausencia de triggers cuando el usuario SQL no puede observarlos. Otros esquemas de privilegios o
roles no demostrados quedan fuera de capacidad. Los permisos de aplicación y SQL son controles distintos.

INSERT aporta los valores explícitos validados por el diccionario/modelo y usa parámetros. La PK es explícita;
no hay generación, upsert ni reintentos. La demo no tiene defaults. La ausencia de un campo NOT NULL se rechaza,
aunque otra tabla dispusiera de un default que este perfil no acredita; un campo nullable omitido se materializa
como nulo y el `null` explícito se distingue de ausencia. No se admite escribir columnas no expuestas.

UPDATE lee la fila autorizada con `FOR UPDATE`, valida todos los campos incluidos y contrasta sus bases por
campo. Un histórico omitido permanece intacto, mientras reenviarlo exige validación actual. Un conflicto impide
toda la modificación; cambios independientes se conservan. El SQL SET contiene solo diferencias efectivas.
Un no-op conserva autorización/precondiciones/validación y emite **cero sentencias DML**; termina la transacción
sin escritura. No se usa un contador global para rechazar cambios independientes ni se detectan episodios ABA.

El ámbito confiable utiliza el perfil de igualdades enteras ya soportado por el lector. Se aplica dentro del SQL
antes de seleccionar y bloquear, y también al candidato de CREATE/UPDATE. Una política que no pueda representarse
no se convierte en lectura o escritura sin restricciones. La aplicación conserva la autoridad de sus decisiones. También
se prueba que una autorización de escritura
no permite divulgar conflictos de una fila fuera del ámbito de lectura.

Las comprobaciones se repiten antes de persistir y antes/después de commit. En esta implementación local síncrona,
las mutaciones de políticas y registro deben serializarse mediante `changeAccess()`, que retira evidencias y avanza
una revisión. Las políticas compartidas entre procesos necesitan coordinación aportada por la aplicación; estas
pruebas no demuestran revocación distribuida ni convierten un callback en un bloqueo de permisos externos.

## Lectura protegida para DELETE

`GET` no cambia su formato ni revela estado auxiliar. La capacidad separada
`readForDelete(target, key, contexto)` exige GET y DELETE declarados/autorizados. Lee y bloquea la fila bajo
ambas restricciones y devuelve solo los campos públicos autorizados, junto con una referencia opaca para DELETE.

El host conserva internamente el estado completo observado, incluidas columnas ocultas, y vincula la referencia
a operación, destino, proyección, identidad, contexto/principal, revisión de política, generación y revisión local.
El identificador usa 32 bytes aleatorios y no contiene PK ni valores codificados. Hay un máximo de 1024 evidencias,
un presupuesto de 8 MiB de estado serializado y
TTL configurable de 1..3600 segundos (300 por defecto) con reloj monótono y retirada por cambios de acceso.
Los tokens de otro contexto, desconocidos, falsificados, retirados o caducados se rechazan.

DELETE vuelve a autorizar y compara todo el estado protegido con la fila bloqueada, indivisiblemente con el
borrado. Un cambio oculto produce conflicto sin revelar su nombre o valor. La ausencia o inaccesibilidad bajo
una política no modificada devuelve `deleted:false` cuando existe evidencia válida. No se admite DELETE por PK
sin evidencia, ni usar una referencia GET como autorización de borrado. Solo se elimina una fila física.

El almacén de evidencia pertenece a la **misma instancia viva de `CrudHost`**. No se presenta como token persistente
ni servicio distribuido: crear otra instancia retira esa capacidad de uso. Una aplicación con varios procesos o
peticiones independientes necesita una implementación de almacenamiento/vinculación explícitamente revisada;
este incremento no añade esa infraestructura. La PK pública de `demo.items` es el perfil demostrado; claves de
mutación opacas o PK no enteras no están habilitadas. Comparar valores tampoco detecta ABA o recreación idéntica.

## Confirmación de UPDATE sin sobrescritura obsoleta

La confirmación es opcional y no un booleano enviado por el cliente:

1. `readForUpdateConfirmation(request, contexto)` obtiene una lectura protegida y devuelve el registro autorizado
   que se mostrará y un desafío opaco pendiente. Ese desafío todavía **no** permite sobrescribir.
2. Tras consentimiento explícito para esa lectura/propuesta, la aplicación invoca la entrada confiable
   `confirmUpdate(request, contexto, challenge)`. Solo activa esa evidencia, sin sustituir su estado por una
   nueva lectura más reciente.
3. `invoke(UPDATE)` con la confirmación vuelve a validar permisos, propuesta, contexto, generación y el estado
   confirmado de cada campo dentro de la transacción. Si cambió después de mostrarse, hay otro conflicto.

La prueba cambia la fila desde la segunda conexión **entre la visualización y el consentimiento**: el valor nuevo
no se sobrescribe. Otro caso comprueba que una columna independiente sí puede cambiar y conservarse al aplicar
la propuesta confirmada. La aplicación es responsable de obtener consentimiento real antes de activar el desafío;
ningún endpoint genérico ni forma de pantalla se añade a ARP.

## Puerto opcional de validación empresarial

El constructor recibe opcionalmente una Closure pura con operación, candidato/estado lógico, contexto confiable y
campos realmente modificados. Debe devolver `true` para permitir. No recibe PDO ni persiste; no se imponen librerías,
expresiones o carpetas. Dependencias externas y reglas con efectos requieren garantías explícitas de la aplicación
o una operación especializada, no quedan protegidas automáticamente por esta transacción.

Ejemplo de demostración, definido solo por el anfitrión de la prueba:

```php
$validator = static function (string $operation, object $candidate, array $context, array $changes): bool {
    return ($candidate->code ?? '') !== 'RESERVED';
};
```

La biblioteca no conoce ese código ni el significado empresarial. Su rechazo se normaliza como
`BUSINESS_REJECTED`, sin escritura. La evaluación puede rechazar también un no-op si una regla aplicable lo exige.

## Resultados, rollback e incertidumbre

Se conservan estados/familias del contrato. Los valores y claves son exactos, sin float intermedio. SQL, excepciones,
contraseñas, filas ajenas y estado auxiliar no aparecen en la respuesta.

| Evidencia | Resultado |
| --- | --- |
| Error con transacción activa y rollback acreditado | Fallo/rechazo seguro según causa; nunca éxito parcial. |
| Integridad PK/UNIQUE/FK y reversión acreditada | `CONSTRAINT_REJECTED`, sin detalles de filas privadas. |
| Commit falla y rollback completo se acredita | `failed` / `COMMIT_FAILED`. |
| Commit puede haber ocurrido o rollback no se acredita | `failed` / `OUTCOME_UNKNOWN`, sin promesa de reintento. |
| Commit confirmado, pero acceso se pierde antes de publicar | `failed` / `RESPONSE_UNAVAILABLE`, sin salida. |

Se prueban transacciones reales con fallos inyectados en los acuses PDO antes/después de commit y después de
rollback, consultando el estado desde otra conexión. Además se ejecuta `KILL CONNECTION` sobre la sesión DATA
propia: el servidor revierte, pero el cliente no puede acreditar rollback y responde incertidumbre. El fallo de
acuse tras commit se inyecta después de un commit real; no se afirma haber cortado TCP exactamente durante COMMIT.
No se reintenta automáticamente una mutación con resultado incierto.

## Matriz de conformidad y pruebas reproducibles

La [matriz JSON](crud-conformance.json) es una observación local de la ejecución, no una modificación de los fixtures.
El runner genera otra matriz completa en cada ejecución y registra las imágenes y la preservación de contenedores
preexistentes. Los casos compartidos comparan directamente `expected.response`; las observaciones privadas de
filas se comparan por PK, porque una tabla SQL es un conjunto y no la lista de inserción del oráculo en memoria.
Los identificadores opacos ficticios se vinculan a evidencia realmente emitida durante la preparación de la prueba.

| Pruebas | Resultado local |
| --- | --- |
| Diccionario con contrato original | 327 aserciones compartidas correctas. |
| Runtime original | 25 pruebas correctas. |
| Resolución CDS | 80 casos y 11 de intake, más regresiones adicionales, correctos. |
| LIST/GET original | Pruebas de identidad, autorización, publicación y limpieza correctas. |
| Codec de escritura | 11 vectores originales correctos; 76 referencias UPDATE intactas. |
| MariaDB con escrituras | 45 vectores originales correctos y 21 grupos nativos adicionales. |
| Formato | PHPCS PSR-12 correcto, usando el formateador configurado del proyecto. |
| Aislamiento | Sin puertos publicados; código/contrato en solo lectura, marca de propiedad y limpieza etiquetada. |

Preparación del contrato separado, sin copiar fixtures:

```bash
git clone https://github.com/alxarafe/arp-contract.git /tmp/arp-write-contract
git -C /tmp/arp-write-contract checkout --detach "$(cat write-contract.ref)"
```

Con las dependencias de desarrollo existentes instaladas desde `composer.lock`:

```bash
ARP_WRITE_CONTRACT_PATH=/tmp/arp-write-contract \
ARP_CONTRACT_PATH=/ruta/al/contrato-original-fijado composer test
composer lint
```

Ejecución aislada nativa con las imágenes existentes:

```bash
python3 providers/crud-docker.py \
    --contract /tmp/arp-write-contract \
    --output /tmp/arp-crud-resultados-nuevos \
    --php-image arp-sql-php-002-test:local
```

También puede usarse una imagen PHP con pdo_mysql, las dependencias ya instaladas y las extensiones del proyecto;
`providers/mariadb/docker/Dockerfile` conserva la receta existente. El runner exige el contrato limpio y su SHA,
crea una MariaDB y red nuevas sin puertos, usa contraseña aleatoria y verifica etiquetas antes de retirar recursos.
El directorio de resultados debe ser nuevo y estar fuera del repositorio. Los logs redactan la contraseña.
El script PHP nativo rechaza cualquier base que no tenga la marca de propiedad de la ejecución **antes de hacer DDL**.
CI ejecuta además el recorrido nativo en un servicio MariaDB efímero y presenta su matriz.

## Dificultades y decisiones pendientes

- Los `host`/`observed` del oráculo neutral son instrumentación, no entradas del runtime. Las banderas sintéticas
  de commit, proyección, permisos o DDL no se añadieron como API productiva para aparentar conformidad.
- El proveedor existente no demuestra dominios afectados por CHECK: los clasifica sin capacidad suficiente y el
  modelo de `rating` deja de ser verificable. Se rechaza sin escribir, sin convertir ese estado en compatibilidad.
  La atomicidad multicolumna se demuestra con UNIQUE real y la integridad referencial con FK RESTRICT.
- El contrato distingue certeza y efectos, pero no prescribe cómo un cliente resuelve una respuesta incierta.
  Se conserva la distinción y se deja reconciliación/idempotencia fuera de este incremento.
- La lectura, emisión, activación y vigencia de evidencia necesitan un ciclo de vida real de anfitrión; los tokens
  ilustrativos no definen almacenamiento entre procesos. Se documenta la solución local, no una convención obligatoria.
- La confirmación debe vincular el estado mostrado antes del consentimiento. Una lectura nueva al confirmar
  perdería esa garantía; por eso se separa el desafío pendiente de la activación confiable.
- Los defaults no caracterizados, claves opacas de mutación, columnas generadas/identidades, vistas y operaciones
  de varias tablas no se ofrecen gestionadas. La aplicación conserva sus operaciones especializadas.
- No se demuestra autorización distribuida, todo tipo físico/SQL, ni conformidad de los otros runtimes.
  Los restantes 65 vectores y las ampliaciones de proveedores requieren trabajo posterior y revisión explícita.
- `INTEGER` sigue pendiente: no se altera el formato ni los tipos históricos para resolver dificultades PHP.