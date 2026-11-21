# Albaranes de servicio → programa de facturación

El equipo registra en el panel los servicios hechos para un propietario (certificados, gestiones, reportajes,
reparaciones…) como **albaranes** y los envía al programa de facturación como **pendientes de facturar**. El panel
nunca emite facturas: la factura, su número y sus impuestos los decide el programa de facturación; el panel solo
recoge después el número y la fecha.

## Estado

**Envíos reales desactivados.** `FACTURADOR_DRIVER=simulado`, `FACTURADOR_ENABLED=false`: todo funciona contra una
simulación local con el mismo contrato (nada sale del servidor). Ningún programa nuestro acepta hoy albaranes por API
(ver «Programa de facturación revisado»), así que el cliente HTTP implementa el **contrato propuesto** de abajo.

## Dónde se usa

| Dónde | Qué |
|---|---|
| Menú **Ventas y alquileres → Albaranes** | lista general (filtros por estado, propietario, inmueble), crear, editar, PDF, enviar, consultar facturación |
| Ficha 360 del propietario, pestaña **Albaranes** | sus albaranes con estado y factura enlazada; «Nuevo albarán» con el cliente precargado (registro `OwnerProfileSections`, clave `albaranes`) |
| Ficha del inmueble, pestaña **Albaranes** | albaranes del inmueble; «Nuevo albarán» con el inmueble precargado |
| Lista de **Encargos** | acción «Nuevo albarán»: cliente e inmueble salen del encargo |

Inmueble y encargo son opcionales. Crear desde otra pantalla es abrir `…/albaranes/create?party=ID`,
`?property=ID` o `?instruction=ID`.

## Flujo y estados

1. **Borrador**: cliente precargado del propietario (nombre, correo, teléfono; NIF y dirección fiscal de la ficha
   de Propietarios si existe, si no de `metadata.tax_id` / `metadata.address`), editable. Líneas: servicio del
   catálogo o línea libre, con cantidad, precio unitario y descuento %. Importes **sin impuestos**.
2. **PDF** («Descargar PDF») en cualquier momento. Indica que no es una factura y, si hace falta conformidad, deja
   el recuadro de firma del cliente.
3. **Conformidad**: si alguna línea usa un servicio del catálogo marcado con conformidad, el albarán queda
   «Pendiente de firma» y **no se envía** hasta tenerla: «Solicitar conformidad (firma)» (firma electrónica, ver
   «Enganche con Formularios y firma») o «Conformidad recibida» (firmado en papel).
4. **Confirmar y enviar** → **Pendiente de envío** → **Enviado** (con el id externo) o **Error** (con el motivo,
   visible en la ficha y en la lista). Desde Error: «Reintentar envío».
5. **Facturado**: cuando el programa de facturación factura el albarán, «Consultar facturación» (o la tarea horaria
   `clarto-albaranes-facturacion`, solo con envíos reales activados) guarda el número y la fecha de la factura.

Una vez enviado, el albarán no se puede modificar ni borrar en el panel.

**Sin duplicados.**
* Cliente: un vínculo por propietario (`clarto_billing_clients`). Antes de crearlo se busca en el programa de
  facturación por nuestra referencia (`clarto-party-{id}`) y por NIF; el alta lleva `Idempotency-Key` = esa referencia.
* Albarán: cada albarán tiene una `Idempotency-Key` fija que se repite en todos los intentos. Si un envío se quedó
  sin respuesta, el albarán sigue «Pendiente de envío» y el reintento primero lo busca por nuestro número
  (`externalRef` = `ALB-2026-00001`); si ya llegó, lo enlaza sin volver a enviarlo.

## Mapa de datos

| Panel (`clarto_albaranes` / `clarto_albaran_lines`) | Programa de facturación |
|---|---|
| `party_id` → `clarto_billing_clients.external_id` | `customers.id` |
| `client_name`, `client_tax_id`, `client_email`, `client_phone`, `client_address` | `customers.displayName`, `document`, `email`, `phone`, `address` (solo en el alta del cliente) |
| `clarto-party-{party_id}` | `customers.externalRef` |
| `number` (ALB-AAAA-NNNNN, nuestro, no es número de factura) | `delivery-notes.externalRef` |
| `idempotency_key` | cabecera `Idempotency-Key` |
| `service_date`, `notes` | `serviceDate`, `notes` |
| líneas: `service_code`, `description`, `quantity`, `unit_price`, `discount_pct` | `lines[].code`, `description`, `quantity`, `unitPrice`, `discountPct` |
| inmueble (`CL-2026-00004`), `instruction_id` | `context.property`, `context.mandateId` (informativo) |
| `external_id` | `delivery-notes.id` |
| `invoice_number`, `invoice_date`, `invoice_external_id` | `delivery-notes.invoice.number`, `.issueDate`, `.id` |
| `status`: borrador · pendiente_envio · enviado · error · facturado | `delivery-notes.status`: pending · invoiced |

## Contrato propuesto (cliente HTTP `App\Albaranes\BillingApiTransport`)

Mismas convenciones que la API pública v1 de DEALER: `Authorization: Bearer rt_…`, respuestas `{data: …}`, errores
`{error: {code, message}}`, listas con `cursor`/`limit`, `Idempotency-Key` en las altas.

| Llamada | Para qué |
|---|---|
| `GET customers?externalRef=clarto-party-{id}` y `GET customers?document={NIF}` | encontrar el cliente antes de crearlo |
| `POST customers` `{externalRef, kind, displayName, document, email, phone, address}` → `{data: {id}}` | alta del cliente (una vez por propietario) |
| `POST delivery-notes` `{customerId, externalRef, serviceDate, currency, notes, lines[], context}` → `{data: {id, status}}` | albarán como pendiente de facturar. **No factura.** |
| `GET delivery-notes?externalRef=ALB-…` | encontrar un albarán enviado sin confirmación |
| `GET delivery-notes/{id}` → `{data: {id, status, invoice: {id, number, issueDate}}}` | estado y factura emitida |
| `GET catalog/services` → `{data: [{code, name, unitPrice, requiresConformity}]}` | catálogo de servicios (si falta, se usa `config/facturador.php`) |

## Programa de facturación revisado

**No existe hoy ninguna API externa de facturación en nuestros proyectos** (investigación del orquestador,
`orquestacion/ficha-360-y-facturador.md`, y revisión propia):

* **DEALER (red-telecom/telecom-dealer-hub)**: tiene la ficha 360 del cliente (CRM001), pero **no factura**: solo
  configura ciclos y series; la «facturación externa» está en su hoja de ruta. Su API pública v1 (`/api/public/v1`,
  clave Bearer con *scopes*) solo lee, retiró la escritura de `/customers` y no recibe albaranes ni líneas
  (`orders:write`, `catalog:read` reservados sin implementar). De ella tomamos solo las convenciones del contrato.
* **Gestoría (red-telecom/teleco-viabilidad)**: decidió facturación propia integrada, sin API (DEC-001).
* **retail-pos (red-telecom/retail-pos)**: solo factura pedidos pagados; sin estado «pendiente de facturar» ni
  webhooks. Descartado.
* **Facturalia** (referencia externa): anuncia API, sin especificación pública.
* No hay catálogo de servicios de facturación en ningún proyecto.

## Qué falta para verificar la conexión real

1. **Decidir el programa de facturación** que recibe los albaranes (un programa externo con API, p. ej. Facturalia
   si publica su especificación, o un desarrollo propio) y que ofrezca las llamadas del contrato propuesto: alta y búsqueda de clientes por NIF / referencia externa, alta de albaranes
   o líneas pendientes de facturar con `Idempotency-Key`, consulta del albarán con la factura que lo incluye, y el
   catálogo de servicios. Si su API usa otros nombres, solo cambia `BillingApiTransport`.
2. **Entorno de pruebas** de ese programa: URL base (`FACTURADOR_URL`, p. ej. `https://<dominio>/api/public/v1`)
   y una clave de pruebas (`FACTURADOR_API_KEY`, `rt_test_…`) con permisos de clientes (lectura/escritura),
   albaranes (escritura), facturas y catálogo (lectura).
3. **Catálogo**: códigos, nombres, precios y qué servicios exigen conformidad del cliente. Mientras tanto se usa la
   lista orientativa de `config/facturador.php` (`servicios`), que hay que confirmar.
4. **Cómo identifica al cliente**: si el NIF es obligatorio para el alta y qué hace con un NIF repetido.
5. **Aviso de factura emitida**: hoy se consulta cada hora. Si el programa avisa por webhook (como `invoice.issued`
   de DEALER), el aviso debe incluir el id del albarán (o nuestro `externalRef`) para poder enlazarlo; hace falta
   además el secreto de firma del webhook.
6. Con todo eso, en el servidor: `FACTURADOR_DRIVER=api`, `FACTURADOR_URL`, `FACTURADOR_API_KEY`,
   `FACTURADOR_ENABLED=true`, y probar un albarán real de prueba contra el entorno de pruebas.

## Enganche con Formularios y firma

`App\Albaranes\Albaran::$conformityRequester` (callable, se fija al arrancar): recibe el albarán, inicia la
solicitud de firma con su PDF (`App\Albaranes\AlbaranPdf::render($albaran)`) y devuelve el id de la solicitud, que
se guarda en `signature_request_id`. Al firmarse, la firma llama a `$albaran->markConformitySigned($requestId)`.
Mientras no esté conectado, el botón explica cómo seguir con la firma en papel.

## Prueba

```
php artisan clarto:albaranes-prueba [--inmueble=CL-2026-00004]
```

Programa de facturación simulado con `Http::fake`, en una transacción que se deshace (no queda nada): un albarán con
inmueble y otro independiente, catálogo del facturador, conformidad, fallo y reintento sin duplicar cliente ni
albarán, envío sin respuesta y reintento que lo encuentra, «no factura al confirmar», factura emitida enlazada en la
ficha del propietario, facturador caído al consultar.

## Configuración

`config/facturador.php`: `FACTURADOR_DRIVER` (`simulado` | `api`), `FACTURADOR_ENABLED`, `FACTURADOR_URL`,
`FACTURADOR_API_KEY`, `FACTURADOR_TIMEOUT` (20 s), `servicios` (catálogo local). Migración
`2026_11_21_000010_create_clarto_albaranes_tables.php` (`clarto_albaranes`, `clarto_albaran_lines`,
`clarto_billing_clients`).
