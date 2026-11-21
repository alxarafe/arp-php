# ARP-INTEGER-002 — PHP/MariaDB con INTEGER exacto

**Estado:** implementación para revisión; sin fusión automática, etiqueta, versión ni release.
**Base PHP:** `62d0a53`, `main` actualizado, rama independiente `feature/arp-integer-002`.
**Contrato neutral:** `e3c2c08c93add63faebbe011a7e8e1ec3eccff2c`, PR #20 fusionada.
`contract.ref`, `listget-contract.ref` y `write-contract.ref` fijan esa misma revisión.
La referencia funcional es `docs/dictionary.md` y los nueve ajustes de `docs/features/arp-integer-001.md`
en ese commit de `alxarafe/arp-contract`.

## Resultado y ruptura deliberada

Los once tipos escalares activos incluyen únicamente `INTEGER` para enteros, con `constraints.bits` y
`constraints.signed` opcionales. Se rechazan los siete identificadores anteriores; no hay alias ni formato
transitorio. Tampoco existe un identificador `UINT64`: se declara `INTEGER`, `bits: 64`, `signed: false`.
Las definiciones que deban conservar su capacidad anterior deben explicitar tamaño y signo.
No se cambia `arp.dictionary/v1`, no se activa ninguna otra propuesta 1.0 y no se modifica Java ni .NET.

El diccionario puro sigue sin requerir SQL ni extensiones de aritmética. La integración real demuestra MariaDB
12.3.3, PHP 8.5.12 y mysqlnd 8.5.12 en imágenes locales ya existentes. El PHP CLI local es 8.5.11;
las comprobaciones de Composer y PHPCS se ejecutaron además en la imagen 8.5.12 con sus extensiones completas.

## Los nueve ajustes del contrato

| Ajuste | Implementación y verificación |
| --- | --- |
| 1. Tipos y dispatch | `Scalar::TYPES` y definiciones usan INTEGER; pruebas rechazan cada identificador retirado. |
| 2. Inferencia y descriptores | `Integer::derive` resuelve capacidad antes de publicar el catálogo; 46 definiciones. |
| 3. Restricciones y errores | Intervalos, exclusiones y existencia de múltiplos exactos; 55 valores INTEGER. |
| 4. Precisión | Comparación, suma y resto decimal por cadenas; sin BCMath, GMP, float ni casts de escalares a int. |
| 5. Observación MariaDB | Tipos nativos y signo determinan integerRange observado, incluido BIGINT UNSIGNED. |
| 6. Resolución CDS | Se congela capacidad antes del contexto; se conserva contención y evidencia parcial. |
| 7. Claves y lectura | Planes confiables llevan constraints; claves, cursores y referencias preservan 64 bits. |
| 8. Escritura y evidencia | CREATE, UPDATE y DELETE conservan restricciones, atomicidad y comparación exacta. |
| 9. Adopción y pruebas | Pins actualizados, 428 aserciones de diccionario y pruebas reales descritas debajo. |

La inferencia respeta signo explícito; sin él, solo un mínimo declarado no negativo permite inferir sin signo.
Con ambos límites declarados se elige la menor capacidad de 8/16/32/64 bits que represente ambos extremos;
con un extremo ausente se prueba 32 y después 64. Se validan extremos declarados antes de aplicar exclusiones.
No se infiere capacidad de datos, SQL, defaults o `multipleOf` al compilar un elemento del diccionario.
Se rechazan atributos nulos o contradictorios, intervalos sin enteros o sin múltiplos válidos y límites fuera
de la capacidad. La existencia de múltiplos se demuestra con resto y suma exactos, reflejando intervalos negativos.
Los descriptores publican tamaño y signo resueltos; el CDS materializa límites efectivos sin reinferirlos.

Los valores y límites son cadenas decimales exactas; los metadatos `bits` siguen el modelo de entero matemático
del parser. `-0` normaliza a `"0"` también sin signo. Los errores mantienen orden `typeRange`, `minimum`,
`maximum`, `multipleOf`; se conservan los demás presupuestos y codecs escalares.
El límite INTEGER de 4096 caracteres incluye el signo y tiene prioridad sobre la evaluación del léxico.

La observación física conserva `nativeType` opaco y emite `scalarType: INTEGER` con límites exactos realmente
observados. La ausencia de un límite no inventa 32 bits: puede probar un conflicto con el lado conocido,
pero no compatibilidad completa. No se amplía el soporte a tipos nativos adicionales.
La base MySQL compartida y las pequeñas traducciones de los proveedores PHP PostgreSQL/SQL Server se ajustan
al mismo nombre neutral para evitar producir tipos retirados. No se ejecutaron esos motores ni se les atribuye
conformidad nueva; sus runtimes Java y .NET no se modifican.

## Identidad, serialización y escritura

Los componentes públicos siguen `{field, type: "INTEGER", value: "..."}`. La capacidad procede del descriptor
confiable del plan, nunca de atributos añadidos por una petición. SQL recibe cadenas mediante parámetros;
las lecturas usan representaciones textuales sin redondeo y se validan con la capacidad resuelta.
Los límites de negocio actuales no invalidan una identidad histórica representable.

Se elimina el cast a `int` de claves en cursores y referencias. Cada componente conserva ocho bytes exactos;
los negativos se codifican en complemento a dos mediante operaciones decimales y se decodifican con el signo
del descriptor confiable. Se mantienen AES-GCM, propósito, destino, contexto, política, generación, límite y
presupuesto de token. Solo generación y tamaños internos acotados usan enteros PHP nativos.
El perfil existente de LIST/GET conserva su requisito de plataforma PHP de 64 bits y máximo siete componentes.

CREATE valida todos los valores efectivos y devuelve identidad exacta. UPDATE valida cada enviado,
incluso si no cambia; baseline y confirmaciones preservan cadenas exactas. Los valores históricos mantienen
su capacidad al ignorar restricciones de negocio actuales; un campo omitido queda inalterado.
DELETE conserva evidencia protegida del estado leído, identidad, contexto, propósito y generación.
Los endpoints no ganan autorización ni capacidad por reconocer INTEGER. Se conservan protecciones de esquema,
conexiones independientes, límites atómicos, no-op sin DML y clasificación de commit/rollback incierto.

## Matriz de conformidad y evidencia

La [matriz verificable](integer-conformance.json) enumera **cada uno** de los 117 vectores de escritura y
93 de LIST/GET con estado ejecutado/no ejecutado. Incluye SHA-256 de los artefactos neutrales, revisiones,
versiones, imágenes y grupos de evidencia. Las matrices anteriores se conservan como historial.

| Capa | Cobertura ejecutada | Alcance |
| --- | --- | --- |
| Diccionario neutral | 428/428 aserciones | Incluye 46 definiciones y 55 valores INTEGER nuevos. |
| Pruebas PHP existentes | 25/25 | Intake, precisión, patrones, aislamiento e inmutabilidad. |
| INTEGER independiente | 2981 aserciones | Suma y múltiplos por enumeración; binario y overflow de identidad. |
| Resolución CDS | 80/80 + 11/11 intake | Expectativas originales, orden, límites y generaciones. |
| Codec de escritura | 14/14 | Nodos numéricos rechazados, valores grandes y normalización. |
| Estructura neutral | 214/214 pruebas | Esquemas e integridad; separadas de conformidad PHP. |
| Escritura nativa | 52/117 vectores | 45 conservados y siete nuevos con uint64; respuesta y filas persistidas. |
| LIST/GET nativo | 29/93 vectores exactos | 26 conservados y tres nuevos del layout unsigned64. |
| Capacidades MariaDB | 8/8 | CREATE y GET en extremos, overflow sin DML, UPDATE y DELETE con evidencia. |
| INTEGER MariaDB | 178 aserciones | Incluye las capacidades y vectores anteriores, no se suman como casos distintos. |

La batería nativa anterior conserva sus **22 grupos de evidencia** y **10 regresiones de certeza transaccional**:
concurrencia entre conexiones reales, bloqueos, confirmación, revocación, CHECK, FK, rechazo de cascadas y triggers,
columnas ocultas, rollback real, commit confirmado con acuse perdido, conexión perdida y publicación posterior.
La suite de lectura conserva layouts simple/compuesto, tipos de datos existentes, restricciones antes de LIMIT,
revocación, fallos PDO, protección DDL DATA/GUARD y persistencia del catálogo tras reinicio de contenedor/proceso.
Los 76 vectores UPDATE referenciados conservan hashes e identidades; **verificar sus hashes no es ejecutarlos**.

La prueba específica nativa recorre `9007199254740993`, `9007199254740994`, `18446744073709551614` y
`18446744073709551615` mediante keyset, sin saltos ni duplicados. Recupera el máximo mediante referencia opaca
sin exponer la PK, y rechaza overflow antes de leer/escribir según el caso.
UPDATE compara baselines uint64 adyacentes, rechaza el valor histórico inválido reenviado y permite corregirlo
con baseline exacto conservando campos omitidos. DELETE detecta estados históricos adyacentes y un binding
a otra identidad. La aritmética independiente enumera todos los valores representables de 8 bits y contrasta
existencia de múltiplos en intervalos positivos/negativos sin usar el mismo algoritmo como expectativa.

## Reproducción e integración continua

Instalar únicamente las dependencias bloqueadas y usar un checkout limpio del commit neutral fijado:

```bash
composer install
composer validate --no-check-publish
ARP_CONTRACT_PATH=/path/to/arp-contract \
  ARP_WRITE_CONTRACT_PATH=/path/to/arp-contract composer test
ARP_CONTRACT_PATH=/path/to/arp-contract composer test:structure
composer lint
python3 providers/sql-listget-docker.py \
  --contract /path/to/arp-contract --listget-contract /path/to/arp-contract \
  --output /tmp/arp-integer-reads-new
python3 providers/crud-docker.py \
  --contract /path/to/arp-contract --output /tmp/arp-integer-crud-new
git diff --check
```

Los runners necesitan Docker y las imágenes locales de prueba indicadas en sus opciones. El segundo ejecuta
ambas suites de escritura y genera `crud-mariadb-report.json` e `integer-mariadb-report.json`, además de logs e
`isolation.json`. CI ejecuta ambas contra su servicio MariaDB aislado y publica las matrices en el log.
Las rutas de informes deben estar fuera del código fuente; las bases se identifican mediante marcadores propios
antes de DDL. Se crean y eliminan solo los recursos Docker etiquetados por cada ejecución; todos los contenedores
preexistentes permanecieron intactos. No se accede a bases de aplicaciones ni se introducen seeds de producto.
Los datos insertados son exclusivamente fixtures en bases desechables del arnés de pruebas.
Composer validate, toda la suite, PHPCS PSR-12 y `git diff --check` pasan. Se formatearon solo archivos de esta tarea.

## Limitaciones expresas

- **65/117 vectores de escritura y 64/93 de LIST/GET no tienen replay nativo exacto**. La matriz los identifica;
  evidencia nativa de una capacidad no se convierte automáticamente en conformidad de sus vectores neutrales.
- Se mantienen las limitaciones anteriores para escritura gestionada: PK pública tipada, tabla única,
  sin generación de IDs, defaults no caracterizados, triggers/cascadas no caracterizados ni efectos auxiliares.
  Las referencias opacas de lectura sí se prueban; no se implementan mutaciones con PK oculta ni manejadores delegados.
- CHECK nativos fuera del perfil siguen impidiendo demostrar el dominio por observación; no se habilita escritura
  usando solo la intersección de dominios incompatibles ni se añaden reglas generales de metadatos SQL.
- La evidencia nativa certifica esta combinación PHP/mysqlnd/MariaDB. No certifica otros motores,
  plataformas PHP de 32 bits ni versiones futuras. Las traducciones PHP de otros proveedores requieren
  su propia ejecución.
- Las garantías siguen dependiendo de que el anfitrión registre destinos y decisiones confiables y utilice las
  barreras de invalidación y autorización existentes. No se añade transporte, cliente visual ni API de permisos.

No se publica ninguna versión ni se amplía el alcance a seeds, UI, Java, .NET u otras propuestas del borrador 1.0.