# Albaranes de servicio → Factúralo

El equipo registra en el panel los servicios hechos para un propietario (certificados, gestiones, reportajes,
reparaciones…) como **albaranes** y los envía a **Factúralo**, el programa de facturación del grupo (FacturaScripts
2026, repositorio `carlanval-ux/facturacion`), como **albaranes de cliente pendientes de facturar**. El panel nunca
emite facturas: la factura y su número los pone Factúralo cuando el equipo factura el albarán allí; el panel solo
recoge después el número y la fecha.

## Estado

**Envíos reales desactivados.** `FACTURADOR_DRIVER=simulado`, `FACTURADOR_ENABLED=false`: todo funciona contra una
simulación local (nada sale del servidor). El cliente de Factúralo (`App\Albaranes\FacturaloTransport`) está hecho y
probado contra una API de Factúralo simulada con `Http::fake`. Todavía no se ha probado contra una instancia real:
falta lo de «Qué falta para activarlo de verdad».

## Dónde se usa

| Dónde | Qué |
|---|---|
| Menú **Ventas y alquileres → Albaranes** | lista general (filtros por estado, propietario, inmueble), crear, editar, PDF, enviar, consultar facturación |
| Menú **Ventas y alquileres → Artículos de albarán** | artículos hechos a mano para reutilizar conceptos (crear, corregir, desactivar) |
| Ficha 360 del propietario, pestaña **Albaranes** | sus albaranes con estado y factura enlazada; «Nuevo albarán» con el cliente precargado (registro `OwnerProfileSections`, clave `albaranes`) |
| Ficha del inmueble, pestaña **Albaranes** | albaranes del inmueble; «Nuevo albarán» con el inmueble precargado |
| Lista de **Encargos** | acción «Nuevo albarán»: cliente e inmueble salen del encargo |

Inmueble y encargo son opcionales. Crear desde otra pantalla es abrir `…/albaranes/create?party=ID`,
`?property=ID` o `?instruction=ID`.

## Líneas y artículos

Decisión del propietario: **no hay productos predefinidos**. Cada línea es **texto libre**: concepto, cantidad,
precio unitario sin IVA, descuento % e **IVA %** (21, 10, 4 o 0). Se ven la base imponible, el IVA y el total (en el
formulario y en el PDF). El catálogo `servicios` de `config/facturador.php` ya no existe.

**Artículos** (`clarto_billing_articles`, por empresa): se crean a mano, desde el menú o con el botón **+** del
campo «Artículo» de la línea. Tienen concepto, referencia opcional, precio, IVA, «Pide la conformidad del cliente» y
«Activo». Elegir un artículo rellena la línea, que sigue siendo editable; la línea guarda `article_id` y la
referencia en `service_code`. Borrar o desactivar un artículo no cambia las líneas que ya lo usaron.

**En Factúralo**: un artículo **con referencia** se da de alta como producto (`productos`, `nostock=1`, servicio
sin stock) la primera vez que se envía un albarán que lo usa, y su id queda en `external_id`. Las líneas llevan
entonces `referencia`. Sin referencia, la línea va solo como texto. Un cambio de precio posterior en el panel no se
reenvía al producto: la línea siempre lleva su propio precio.

## Flujo y estados

1. **Borrador**: cliente precargado del propietario (nombre, correo, teléfono; NIF y dirección fiscal de la ficha
   de Propietarios si existe, si no de `metadata.tax_id` / `metadata.address`), editable. Líneas de texto libre.
2. **PDF** («Descargar PDF») en cualquier momento. Indica que no es una factura y, si hace falta conformidad, deja
   el recuadro de firma del cliente.
3. **Conformidad**: si alguna línea usa un artículo que la pide, el albarán queda «Pendiente de firma» y **no se
   envía** hasta tenerla: «Solicitar conformidad (firma)» (firma electrónica, ver «Enganche con Formularios y
   firma») o «Conformidad recibida» (firmado en papel).
4. **Confirmar y enviar** → **Pendiente de envío** → **Enviado** (con el id del albarán en Factúralo) o **Error**
   (con el motivo, visible en la ficha y en la lista). Desde Error: «Reintentar envío».
5. **Facturado**: cuando el equipo factura el albarán en Factúralo (estado del albarán «Facturar»), «Consultar
   facturación» (o la tarea horaria `clarto-albaranes-facturacion`, solo con envíos reales activados) guarda el
   número y la fecha de la factura.

Una vez enviado, el albarán no se puede modificar ni borrar en el panel.

**Sin duplicados.**
* Cliente: un vínculo por propietario (`clarto_billing_clients`). Antes de crearlo se busca en Factúralo por nuestro
  código (`CLP` + id del propietario con 7 cifras, p. ej. `CLP0000012`) y por NIF; si un cliente con ese NIF ya
  existía en Factúralo (dado de alta a mano), se reutiliza su código.
* Albarán: `crearAlbaranCliente` **no tiene idempotencia** en Factúralo. Por eso cada envío busca primero el albarán
  por nuestro número en `numero2` (`GET albaranclientes?filter[numero2]=ALB-…`) y, si ya llegó (p. ej. tras un
  envío sin respuesta), lo enlaza sin volver a enviarlo. La cabecera `Idempotency-Key` se manda igualmente.
  ponytail: dos envíos simultáneos del mismo albarán podrían cruzarse entre la búsqueda y el alta; hoy el botón es
  manual y el panel no reintenta solo. Si hiciera falta, un recurso idempotente en Factúralo (como
  `telecoFacturaCliente`) para albaranes.

## Mapa de datos

| Panel | Factúralo (`/api/3`) |
|---|---|
| `party_id` → `clarto_billing_clients.external_id` | `clientes.codcliente` (`CLP0000012`, o el existente con el mismo NIF) |
| `client_name`, `client_tax_id`, `client_email`, `client_phone` | `clientes.nombre` y `razonsocial`, `cifnif`, `email`, `telefono1` (solo en el alta); `personafisica` según el NIF; la dirección fiscal va en `observaciones` del cliente |
| `number` (ALB-AAAA-NNNNN, nuestro, no es número de factura) | `albaranclientes.numero2` |
| `service_date` | `fecha` (si es futura, la de hoy: Factúralo no admite fechas futuras) |
| `notes` + inmueble (`CL-2026-00004`) + encargo | `observaciones` |
| líneas: `description`, `quantity`, `unit_price`, `discount_pct`, `vat_pct`, `service_code` | `lineas[]`: `descripcion`, `cantidad`, `pvpunitario` (sin IVA), `dtopor`, `codimpuesto` (`IVA21`, `IVA10`, `IVA4`, `IVA0`), `referencia` |
| artículo con referencia: `reference`, `name`, `unit_price`, `vat_pct` → `external_id` | `productos`: `referencia`, `descripcion`, `precio`, `codimpuesto`, `nostock=1` → `idproducto` |
| `external_id` | `albaranclientes.idalbaran` |
| `invoice_external_id`, `invoice_number`, `invoice_date` | `doctransformations` (`AlbaranCliente` → `FacturaCliente`, `iddoc2`) → `facturaclientes.idfactura`, `codigo`, `fecha` |
| `status`: borrador · pendiente_envio · enviado · error · facturado | albarán «Abierto» = enviado; con factura enlazada = facturado |

Serie, forma de pago y almacén: `FACTURALO_CODSERIE`, `FACTURALO_CODPAGO`, `FACTURALO_CODALMACEN` (vacío = el
valor por defecto de la instancia; Teleco usa `A`, `CONT`, `ALG`). Totales e impuestos los calcula Factúralo.

## Contrato con Factúralo (`App\Albaranes\FacturaloTransport`)

API REST de FacturaScripts en `https://<instancia>/api/3/…`: cabecera `Token: <clave>`, cuerpos
**form-urlencoded** (las líneas van como texto JSON en `lineas`), listas con `filter[campo]=valor` y `limit`.
Errores: `{error: …}` o `{status: error, message: …}`. Es la misma vía que usa Teleco (`red-telecom/teleco-viabilidad`,
`src/facturascripts-cliente.js`). La «API oculta» del plugin Facturalo solo esconde a quien no es staff los ajustes
de API en la interfaz: la API sigue activa con `default.enable_api=true`.

| Llamada | Para qué |
|---|---|
| `GET clientes/{codcliente}` y `GET clientes?filter[cifnif]=…` | encontrar el cliente antes de crearlo |
| `POST clientes` | alta del cliente (una vez por propietario) |
| `GET productos?filter[referencia]=…`, `POST productos` o `PUT productos/{id}` | artículo con referencia como producto |
| `GET albaranclientes?filter[numero2]=ALB-…` | encontrar un albarán ya enviado (antes de cada envío) |
| `POST crearAlbaranCliente` | albarán con sus líneas, pendiente de facturar. **No factura.** Respuesta `{doc: {idalbaran, codigo, …}, lines}` |
| `GET albaranclientes/{id}` | estado del albarán |
| `GET doctransformations?filter[model1]=AlbaranCliente&filter[iddoc1]={id}&filter[model2]=FacturaCliente` | factura en la que entró el albarán (también si se agruparon varios) |
| `GET facturaclientes/{id}` | código y fecha de esa factura |

## Qué falta para activarlo de verdad

1. **Instancia de Factúralo para CLARTO.** Cada empresa tiene su propia instalación (empresa única) en
   `https://<slug>.facturalo.redtelecom.app`, creada por el agente MASTER (`deploy/fs-tpv/master/`,
   `docs/FACTURALO-CONTROL-MASTER.md`). El slug `clarto` está **reservado** (en el repositorio de Factúralo «Clarto»
   es el portal de gestoría, teleco-viabilidad): hace falta otro, p. ej. `clarto-sol`. A 25/11/2026 el agente
   MASTER no estaba instalado; si no, el alta se hace a mano con `deploy/fs-tpv/provision-fs-tenant.sh` y
   `fs-bootstrap-tenant.sh`. Datos de la empresa (CLARTO SOLUTIONS SL o el titular actual, NIF, dirección), ejercicio
   y series.
2. **Clave de API** (`FS_API_KEY`, la crea `fs-bootstrap-tenant.sh` en `/etc/fs/tenants/<slug>.secrets`; no viaja en
   la respuesta del MASTER). La pone el propietario en el servidor del panel (`FACTURADOR_API_KEY`), nunca en el
   repositorio. La clave por defecto (`teleco-bridge`, `fullaccess=0`) **no basta**: staff debe darle en
   `api_access` estos permisos:
   * `clientes`: GET, POST (ya los tiene);
   * `productos`: GET, POST, PUT (ya los tiene);
   * `facturaclientes`: GET (ya lo tiene);
   * **nuevos**: `crearAlbaranCliente` POST, `albaranclientes` GET, `doctransformations` GET.
   O una clave propia para CLARTO con exactamente esos permisos (mejor que reutilizar `teleco-bridge`).
3. **Valores de la instancia**: `codserie` (¿`A`?), `codpago` (¿`CONT`, transferencia…?), `codalmacen` (¿`ALG`?) y que
   existan los impuestos `IVA21`, `IVA10`, `IVA4` e `IVA0`.
4. **Flujo de facturación en Factúralo**: el equipo factura los albaranes allí (estado «Facturar»); la factura nace
   en **Boceto** y, al emitirla («Emitida»), VERI\*FACTU la registra (plugin `VerifactuFacturalo`). El panel solo lee
   el número. Confirmar que es así como se quiere trabajar (y no factura directa desde el panel).
5. **VERI\*FACTU** de la instancia de CLARTO: modo pruebas o real, certificado y fecha de inicio (lo configura
   Factúralo, no el panel).
6. **Red**: la API no tiene lista blanca de IP (el comentario del vhost dice «solo Teleco» pero no lo aplica). Si se
   quiere, permitir solo la IP del servidor del panel. Factúralo bloquea la IP 10 minutos tras 5 fallos
   de clave o de permiso: probar primero con `--prueba`.
7. Con todo eso, en el servidor (lo hace el propietario con un script, sin escribir la clave en ningún sitio más):
   `FACTURADOR_DRIVER=facturalo`, `FACTURADOR_URL=https://<slug>.facturalo.redtelecom.app`, `FACTURADOR_API_KEY`,
   `FACTURALO_CODSERIE`, `FACTURALO_CODPAGO`, `FACTURALO_CODALMACEN` y, solo al final, `FACTURADOR_ENABLED=true`. Las
   variables deben pasar al contenedor en `compose.yaml` de pwb (hoy no están). Después, un albarán de prueba con un
   cliente ficticio contra la instancia real, y comprobar en Factúralo el albarán, el cliente y el producto.

## Enganche con Formularios y firma

`App\Albaranes\Albaran::$conformityRequester` (callable, se fija al arrancar): recibe el albarán, inicia la
solicitud de firma con su PDF (`App\Albaranes\AlbaranPdf::render($albaran)`) y devuelve el id de la solicitud, que
se guarda en `signature_request_id`. Al firmarse, la firma llama a `$albaran->markConformitySigned($requestId)`.
Mientras no esté conectado, el botón explica cómo seguir con la firma en papel.

## Prueba

```
php artisan clarto:albaranes-prueba
```

Factúralo simulado con `Http::fake`, en una transacción que se deshace (no queda nada): líneas de texto libre con IVA
del 21 y del 10 %, artículos hechos a mano (con y sin referencia, uno que pide conformidad), PDF con base, IVA y
total, alta del cliente con código estable y búsqueda previa por código y NIF, cliente existente en Factúralo con el
mismo NIF reutilizado, producto dado de alta una sola vez, formulario con `Token`, serie y forma de pago, fallo y
reintento sin duplicar cliente ni albarán (búsqueda por `numero2`), envío sin respuesta y reintento que lo
encuentra, «no factura al confirmar», fecha futura → hoy, factura emitida enlazada por `doctransformations` en la
ficha del propietario, Factúralo caído al consultar, producto rechazado.

## Configuración

`config/facturador.php`: `FACTURADOR_DRIVER` (`simulado` | `facturalo`), `FACTURADOR_ENABLED`, `FACTURADOR_URL`,
`FACTURADOR_API_KEY`, `FACTURADOR_TIMEOUT` (20 s), `FACTURALO_CODSERIE`, `FACTURALO_CODPAGO`, `FACTURALO_CODALMACEN`.
Migraciones `2026_11_21_000010_create_clarto_albaranes_tables.php` (`clarto_albaranes`, `clarto_albaran_lines`,
`clarto_billing_clients`) y `2026_12_16_000001_free_text_lines_and_articles_on_clarto_albaranes.php`
(`clarto_billing_articles`; `article_id`, `vat_pct`, `requires_conformity` en las líneas). El recurso de artículos
se registra en `modules/real-estate-application/.../AdminPanelProvider.php` (regenerar el parche de módulos al
desplegar).
