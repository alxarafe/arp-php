# Demo PHP/MariaDB por HTTP

Este protocolo local es una adaptación de demostración, **no un contrato normativo ARP**.
Reutiliza `CrudHost`, su `ListGetHost`, `PhysicalCatalog` y los proveedores MariaDB existentes.
No está preparado para producción ni para publicarse detrás de un proxy o en una interfaz pública.

## Arranque, prueba y parada

Requisitos: Docker, Python 3 y un checkout limpio de `alxarafe/arp-contract` en el commit indicado por
`write-contract.ref` (actualmente también coincide con `contract.ref`). No se requiere PHP en el equipo.

```sh
export ARP_WRITE_CONTRACT_PATH=/ruta/arp-contract-fijado
python3 demo/crud-run check
# O mantener la demo abierta para Angular:
python3 demo/crud-run start
# ... usar http://127.0.0.1:5300 ...
python3 demo/crud-run stop
```

`check` arranca una base nueva con los 30 registros de `demo/seed/demo.items.csv`, ejecuta las pruebas HTTP
reales y para sus recursos en un bloque `finally`. `start` conserva los recursos hasta `stop`.
Un segundo `start` falla si ya existe estado local: detener primero. Las opciones son `ARP_DEMO_PORT`
(por defecto 5300) y `ARP_DEMO_ORIGIN` (por defecto `http://127.0.0.1:4173`).

El script muestra la ruta de un archivo privado de estado en el directorio temporal, con permisos 0600.
Su propiedad `token` contiene el token que debe configurar manualmente el cliente local; nunca se sirve por HTTP.
No incluirlo en repositorios ni en URLs. `password` es la credencial efímera del servidor MariaDB;
**no debe copiarse al navegador**. Docker y los procesos del mismo usuario tienen acceso a esas credenciales.
El token Bearer identifica a un único usuario de demostración, con acceso a los tres campos del target.

Cada arranque genera una red privada y dos contenedores con identificadores propios. MariaDB no publica
puertos; HTTP publica exclusivamente `127.0.0.1:PUERTO`. La base `arp_seed_http` vive en el volumen anónimo
del contenedor recién creado. La parada verifica la etiqueta de propiedad y elimina por ID solo los
contenedores, volúmenes anónimos y red registrados. No modifica servicios existentes ni bases externas.
La imagen local de construcción se conserva para reutilizar caché. Si se interrumpe el proceso de forma
no recuperable, conservar el archivo de estado y ejecutar `stop`; no borrar recursos por nombre o patrón.

Para enviar una petición desde la terminal, cargar solo el token (usar la ruta que imprimió `start`):

```sh
export ARP_DEMO_CRUD_TOKEN="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["token"])' /tmp/archivo-estado.json)"
curl -H "Authorization: Bearer $ARP_DEMO_CRUD_TOKEN" http://127.0.0.1:5300/demo/crud/metadata
```

Las rutas de diccionario y `demo/run` anteriores siguen disponibles. La imagen CRUD tiene su propio
`Dockerfile.crud`; no activar credenciales CRUD en la imagen antigua de solo diccionario.

## Seguridad del transporte

Todas las rutas CRUD requieren `Authorization: Bearer TOKEN` (mínimo 32 caracteres configurados).
Las peticiones POST requieren además `Origin` exactamente igual al origen configurado; se rechazan ausencia,
`null`, otros orígenes y `Sec-Fetch-Site: cross-site`. No se usan cookies ni credenciales ambientales.
CORS autoriza exclusivamente ese origen y permite `Content-Type, Authorization`; **CORS no autentica**.
El token es la credencial. Clientes nativos pueden falsificar Origin, por lo que la protección presupone
que no conocen el token. No hay sistema de cuentas, roles configurables, TLS, auditoría ni rotación automática.

POST requiere `application/json`, máximo 65536 bytes. El parser exacto del núcleo rechaza duplicados,
UTF-8 inválido, estructura inválida y sus límites de profundidad/números. No se admiten campos de conexión,
base, tabla o archivo. El único target es `demo.items`; su base, tabla, proyección, identidad y política
se fijan en el servidor. Las credenciales se inyectan en el contenedor. DML usa los proveedores SQL existentes
y sus parámetros preparados. Los errores de transporte son `DEMO_*`; los diagnósticos del núcleo se conservan.
Las respuestas CRUD llevan `Cache-Control: no-store`.

## Rutas y ejemplos

`GET /demo/info` conserva las capacidades de diccionario y anuncia `crud.metadata` y `crud.invoke` cuando
se configura un token. `GET /demo/crud/metadata` devuelve operaciones, proyección, campos, tipos, restricciones,
etiquetas, nulabilidad y condición de clave, derivados del catálogo resuelto. No publica metadatos físicos.
Angular debe consultar estos metadatos y conservar los valores exactos recibidos al mostrar un formulario.
Los valores INTEGER y DECIMAL se transportan como cadenas decimales exactas, conforme al núcleo.
El cliente no debe convertirlos a `Number` de JavaScript. Los números de los metadatos se serializan sin redondeo.

`POST /demo/crud/invoke` recibe `target`, `operation`, `inputs`. Ejemplos de cuerpos:

```json
{
  "target": "demo.items",
  "operation": "LIST",
  "inputs": {
    "limit": 10,
    "page": 1
  }
}

{
  "target": "demo.items",
  "operation": "GET",
  "inputs": {
    "key": {
      "components": [
        {
          "field": "id",
          "type": "INTEGER",
          "value": "3"
        }
      ]
    }
  }
}

{
  "target": "demo.items",
  "operation": "CREATE",
  "inputs": {
    "values": {
      "id": "90001",
      "code": "WEB",
      "rating": "5"
    }
  }
}

{
  "target": "demo.items",
  "operation": "UPDATE",
  "inputs": {
    "key": {
      "components": [
        {
          "field": "id",
          "type": "INTEGER",
          "value": "90001"
        }
      ]
    },
    "changes": {
      "rating": "6"
    },
    "baseline": {
      "rating": "5"
    }
  }
}

{
  "target": "demo.items",
  "operation": "DELETE",
  "inputs": {
    "key": {
      "components": [
        {
          "field": "id",
          "type": "INTEGER",
          "value": "90001"
        }
      ]
    },
    "confirmedValues": {
      "id": "90001",
      "code": "WEB",
      "rating": "6"
    }
  }
}
```

HTTP 200 indica éxito, 409 conflicto, 422 rechazo del núcleo y 500 fallo de ejecución. El cuerpo conserva
`status`, `diagnostics` y `output` del núcleo. Errores previos al núcleo pueden usar 400, 403, 405, 413 o 415.

LIST admite `limit` 1–100 y `page` 1–100. `output.page.next` contiene el número de página siguiente o null.
Los cursores privados del núcleo no se exportan: cada petición recorre mediante LIST las páginas previas
en una misma instancia y entrega la solicitada. Esto cuesta O(página × límite), deliberadamente acotado.
No es un snapshot entre peticiones: altas/bajas concurrentes pueden desplazar páginas; recargar el listado.

## Consentimiento y concurrencia

UPDATE pasa `changes` y su `baseline` al núcleo sin alterarlos. Solo se comparan los campos modificados:
un cambio concurrente en otro campo se conserva, un cambio en el campo escrito produce 409 y sus diagnósticos.
No se ofrecen confirmaciones persistentes: ante conflicto se recarga GET, se muestran los valores actuales
y se solicita al usuario una nueva decisión. El host rechaza `confirmation` enviado por el cliente.

Para DELETE el cliente debe mostrar y confirmar el registro completo y enviar exactamente esos `values`
como `confirmedValues`, además de la clave. El servidor hace `readForDelete()`, compara todos los valores
visibles y sus tipos exactos y solo entonces usa su evidencia privada para DELETE en la **misma instancia**.
Ni referencias de evidencia ni estado privado se envían al navegador o se almacenan globalmente.
Un cambio entre la pantalla y `readForDelete()` devuelve 409. Un cambio posterior entre esa lectura y DELETE
es detectado por la transacción del núcleo, que vuelve a leer bajo bloqueo y compara su evidencia.
Un registro ya ausente también devuelve conflicto en la comprobación de consentimiento.

El cliente debe realmente mostrar los valores y obtener consentimiento: el servidor no puede acreditar
una interacción humana. Se compara estado, no historial/versiones; un cambio A→B→A es indistinguible de A.
Esta demo expone todos los campos del modelo: si se añadieran campos ocultos, habría que revisar expresamente
el consentimiento antes de cambiar esta composición. No se permite sobrescribir un estado diferente por omisión.

## Verificación

`check-crud-http.py` verifica persistencia entre peticiones, 30 filas y paginación, GET con Unicode,
CREATE, UPDATE parcial, conflicto UPDATE, DELETE válido, DELETE obsoleto y sin todos los campos,
valores inválidos, claves inválidas, evidencia/confirmación falsificada, duplicados JSON, límites,
medios inválidos y credenciales/orígenes no autorizados. Deja los 30 registros originales intactos.

Para las regresiones existentes se conserva `composer test`, `composer lint`, `demo/check-http.py` y
las pruebas transaccionales de `providers/tests/crud-mariadb.php`. El nuevo job HTTP ejecuta `crud-run check`.
