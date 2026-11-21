# Formularios y firma electrónica

El equipo prepara un documento desde el **propietario** (pestaña **Firmas** de la ficha 360, cuando esté fusionada
la rama de Propietarios), el **inmueble** (pestaña **Firmas** de la ficha) o el **encargo** (botón **Preparar
documento para firma**): elige un **Formulario** (o adjunta un PDF), ve los campos rellenados con los datos del
expediente y los que faltan, abre la **vista previa** y solo entonces lo **envía a firma** a uno o varios firmantes.
Desde la solicitud (menú **Firmas**) consulta el estado, envía el enlace o un recordatorio por SMS y descarga el PDF
enviado, el PDF firmado y el registro de auditoría.

## Estado

**Envíos reales desactivados.** `FIRMA_DRIVER=simulado` y `FIRMA_SMS_DRIVER=simulado` por defecto: el envío se
registra igual (versión, valores, hash, firmantes) pero nada sale del servidor. Los SMS, aun con el conector real,
solo van a los **números de prueba** (`FIRMA_SMS_TEST_DESTINATIONS`) mientras `FIRMA_SMS_ONLY_TEST_DESTINATIONS`
siga activo. La única plantilla sembrada es **ficticia** (`prueba-hoja-de-encargo`, «PRUEBA · Hoja de encargo
ficticia»); los documentos definitivos en castellano se añadirán cuando estén aprobados.

Prueba de extremo a extremo: `php artisan clarto:firma-prueba` (DocuSeal y SMS simulados con `Http::fake`, webhooks
por la ruta HTTP real, transacción deshecha, ficheros en un disco temporal). 29 comprobaciones.

## Arquitectura

| Pieza | Dónde |
|---|---|
| Catálogo «Formularios» | `App\Signing\FormTemplate` (`clarto_form_templates`), pantalla `App\Filament\Resources\FormTemplateResource` (menú *Encargos y documentos → Formularios*) |
| Preparar y enviar | `App\Filament\Pages\SendForSignature` (`/admin/{equipo}/enviar-a-firma?propietario=&inmueble=&encargo=&formulario=`) |
| Solicitudes | `App\Signing\SignatureRequest`, `SignatureSigner`, `SignatureDocument`, `SignatureEvent`, `SmsMessage`; pantalla `SignatureRequestResource` (menú *Firmas*) |
| Flujo | `App\Signing\Signing`: datos → valores, faltantes, PDF, envío, conciliación, webhooks, documentos, SMS |
| Proveedor de firma | interfaz `App\Signing\SignatureProvider` → `DocusealProvider` (real) / `SimulatedSignatureProvider` |
| SMS | interfaz `App\Signing\SmsGateway` → `RedTelecomSms` (API del dealer) / `SimulatedSms` |
| Webhook | `POST /api/v1/firma/docuseal` → `App\Http\Controllers\DocusealWebhookController` |
| Configuración | `config/firma.php` (solo variables de entorno del servidor) |
| Enganches en módulos | `AdminPanelProvider` (registro de la página y los dos recursos), `PropertyResource` (pestaña Firmas), `EditInstruction` (botón) |
| Ficha 360 | `ClartoServiceProvider` registra la pestaña en `App\Owners\OwnerProfileSections` si esa clase existe |

### Decisión: DocuSeal detrás de una interfaz

El diseño de Red Telecom (`red-telecom/teleco-viabilidad`, `docs/FIRMA-API-CONTRATO.md`) prevé una **«Firma API»
compartida** a la que las aplicaciones llaman en vez de tener claves de DocuSeal. Ese servicio **no está desplegado**.
Para no bloquear a CLARTO, el panel habla con DocuSeal directamente, pero solo a través de `SignatureProvider`
(crear, consultar, documentos, descargar, verificar webhook). Cuando la Firma API exista se añade una tercera
implementación (`FirmaApiProvider`) y se cambia `FIRMA_DRIVER`; el modelo de datos, las pantallas y la prueba no
cambian. Cada solicitud guarda su `driver` y `environment`, así que lo ya enviado se sigue conciliando con el
proveedor con el que se envió.

### Versiones y lo que queda guardado

* Un formulario tiene clave, **versión**, estado (borrador / activo / retirado), roles de firmante, **nivel de firma**
  (simple / avanzada), origen (**plantilla de DocuSeal** con id por entorno, o **PDF subido**), texto de vista previa
  y campos con su **dato de origen**.
* Al primer envío la versión queda **bloqueada** (`used_at`): solo cambia su estado; cualquier cambio de contenido es
  una **nueva versión** (botón *Nueva versión*). El modelo lo impide también fuera de la pantalla.
* Cada solicitud guarda: copia exacta de la definición enviada (`template_snapshot`), clave y versión, **valores**,
  el **PDF** en disco privado y su **SHA-256**, nivel de firma, proveedor y entorno, id de la solicitud en DocuSeal,
  estado por solicitud y por firmante (pendiente → enviado → visto → firmado / rechazado).
  - Origen PDF o PDF adjunto: el hash es del PDF exacto enviado a DocuSeal (`/submissions/pdf`).
  - Origen plantilla de DocuSeal: DocuSeal compone el documento con la plantilla (id fijo por versión) y los valores;
    el hash guardado es el de nuestra vista previa con esos mismos valores, y al completarse se guarda además el
    PDF firmado con su propio hash.
* El enlace de firma de cada firmante se guarda **cifrado** (abre el documento).

### Webhooks y conciliación

`X-Docuseal-Signature: <ts>.<hex>` = HMAC-SHA256 de `<ts>.<cuerpo crudo>` con el secreto, ventana de 300 s,
comparación en tiempo constante; 401 uniforme. Cada evento se guarda una vez (`clarto_signature_events.event_key`
único): una entrega repetida responde 200 «duplicado» y no hace nada. El evento es **solo un aviso**: el estado se
actualiza tras `GET /submissions/{id}` autenticado, y nunca retrocede (un evento tardío no reabre nada). Si la
conciliación falla, se borra el evento y se responde 503 para que DocuSeal reintente. Al completarse, se descargan
(sin token, solo de hosts de DocuSeal por https) el PDF firmado (`GET /submissions/{id}/documents`) y el registro de
auditoría (`audit_log_url`) a `firma/firmados/{solicitud}/` en el disco privado, con su SHA-256; un documento ya
guardado no se vuelve a descargar.

### Acceso

Formularios, envío, solicitudes y descargas: solo personal del panel con rol de administrador (`isAdmin()`; el panel
`/admin` ya exige administrador o superadministrador). Los ficheros están en el disco privado (`local`) y solo se
descargan desde la solicitud; no hay URL pública.

### SMS

Mensajes **individuales y profesionales** (sin envíos masivos ni marketing): enlace de firma y **recordatorio
manual**, firmante a firmante, desde la solicitud. Texto en ASCII ≤ 160 caracteres (un solo SMS); en el registro el
enlace se sustituye por «[enlace de firma]». Cada envío queda en `clarto_sms_messages` (aceptado / rechazado /
bloqueado / error, id del proveedor).

**SMS y código de verificación (OTP).** DocuSeal envía el código de `require_phone_2fa` con **su propio** servicio de
SMS; su API no permite usar otro proveedor para ese código, y el dealer (SmsEasySms) no tiene endpoint de OTP. Por eso:

* la casilla *Código por SMS* de un firmante activa `require_phone_2fa` en DocuSeal;
* la verificación del código se registra (`otp_verified_at`) **solo cuando DocuSeal la confirma** en la solicitud
  (`submission_events` con `phone_verified`; nombre del evento **no verificado** con la cuenta real);
* un SMS nuestro nunca cuenta como verificación ni **sube el nivel de firma**: el nivel lo fija el formulario
  (la firma avanzada exige la plantilla con verificación de identidad en DocuSeal UE).

Usar el SMS del dealer para el código exigiría: DocuSeal autoalojado con un proveedor de SMS configurable (hoy solo
admite los suyos) o generar y comprobar el código nosotros (y entonces no sería evidencia de DocuSeal); ninguna de las
dos está decidida.

## Mapa de datos

Los campos leen rutas con puntos del contexto del expediente; `a|b` = la primera con valor. Disponibles:

| Ruta | Dato |
|---|---|
| `propietario.name` · `.email` · `.phone` · `.metadata.*` | Propietario (Party) |
| `propietario.perfil.tax_id` · `.fiscal_address` · `.fiscal_city` · `.representative_name` · … | Ficha 360 (`clarto_owner_profiles`, rama Propietarios); nunca el IBAN |
| `inmueble.referencia` · `.address` · `.postal_code` · `.municipio` · `.area_sqft` · `.bedrooms` · `.bathrooms` · `.price` · `.operacion` (venta/alquiler) | Inmueble |
| `encargo.subject` · `.terms.*` | Encargo (Instruction) |
| `hoy` | Fecha de hoy (dd/mm/aaaa, Madrid) |

Se rellenan solos al elegir el formulario; desde el inmueble se deducen su último encargo y el propietario de ese
encargo. Los números se formatean en español (185.000). El personal puede corregir cualquier valor antes de la vista
previa; si cambia algo después, hay que volver a verla para poder enviar.

Firmantes por defecto según el rol del formulario: `owner` → propietario, `staff` → usuario que envía, `other` → a mano.

Plantilla ficticia sembrada: roles `propietario` (DocuSeal «Propietario») y `agencia` («Agencia»); campos nombre,
DNI/NIE (`propietario.perfil.tax_id|propietario.metadata.documento`), dirección, referencia, superficie, municipio,
operación, precio, encargo y fecha, con los nombres de campo de DocuSeal de la tabla de la plantilla.

## Variables del servidor

| Variable | Por defecto | Uso |
|---|---|---|
| `FIRMA_DRIVER` | `simulado` | `docuseal` para envíos reales |
| `FIRMA_DISK` | `local` | disco privado de PDFs |
| `DOCUSEAL_ENVIRONMENT` | `sandbox` | `sandbox` (api.docuseal.com, solo datos ficticios) / `production` (api.docuseal.eu) |
| `DOCUSEAL_API_KEY_SANDBOX` / `_PROD` | — | cabecera `X-Auth-Token` de la cuenta de ese entorno |
| `DOCUSEAL_WEBHOOK_SECRET_SANDBOX` / `_PROD` | — | secreto HMAC del webhook de ese entorno |
| `DOCUSEAL_SEND_EMAIL` | `false` | que DocuSeal envíe además el correo con el enlace |
| `FIRMA_SMS_DRIVER` | `simulado` | `redtelecom` para SMS reales |
| `REDTELECOM_SMS_URL` | `https://sms.redtelecom.es/Api/rest/message` | API REST del dealer |
| `REDTELECOM_SMS_USER` / `_PASSWORD` | — | HTTP Basic |
| `REDTELECOM_SMS_SENDER` | `CLARTO` | remitente (≤ 11 alfanuméricos) |
| `FIRMA_SMS_ONLY_TEST_DESTINATIONS` | `true` | solo números de prueba |
| `FIRMA_SMS_TEST_DESTINATIONS` | — | lista separada por comas (`34600000000`, sin «+») |

Con `DOCUSEAL_SEND_EMAIL=false` el enlace llega al firmante **solo** por el SMS que se envía desde la solicitud:
hasta tener SMS reales, activar el correo de DocuSeal para cualquier envío real.

## Qué falta para activar la firma y los SMS reales

**DocuSeal (owner)**
1. Cuenta de **pruebas** (nube global, Test Mode) y su API key → `DOCUSEAL_API_KEY_SANDBOX`.
2. En esa cuenta, webhook a `https://panel.clarto.es/api/v1/firma/docuseal` con los eventos `form.viewed`,
   `form.started`, `form.completed`, `form.declined`, `submission.completed`, `submission.expired`,
   `submission.archived`, y su secreto **HMAC** → `DOCUSEAL_WEBHOOK_SECRET_SANDBOX`.
3. Plantilla de pruebas en DocuSeal con roles «Propietario» y «Agencia» y los campos de la plantilla ficticia; su id
   en el formulario (campo *Id de plantilla de DocuSeal (pruebas)*), en una versión nueva.
4. Prueba real en sandbox con datos ficticios y dominios reservados (`example.com`): enviar, firmar, comprobar
   webhook, documentos y hash; cierra lo marcado *no verificado* (forma exacta de eventos, `phone_verified`, hosts de
   descarga, disponibilidad de `audit_log_url` en el plan).
5. Producción: cuenta en la **nube UE** (`api.docuseal.eu`, la única con AdES), plan con API, tarifas por escrito,
   DPA/subencargados, marca y remitente del correo, plantillas propias (las de firma **avanzada** con «Verify ID» en
   modo AES), webhook y claves `_PROD`, `DOCUSEAL_ENVIRONMENT=production`, `FIRMA_DRIVER=docuseal`.
6. Decisión del owner: plazo de caducidad por documento, copia al firmante al completar, retención en DocuSeal.
7. Los textos **definitivos y aprobados** de cada documento (hoja de encargo, etc.) como plantillas de DocuSeal o PDF.

**SMS del dealer (Red Telecom)**
1. Credenciales API (usuario y contraseña) → `REDTELECOM_SMS_USER` / `REDTELECOM_SMS_PASSWORD` — **no acreditadas**.
2. Alta de la IP del servidor (57.129.192.99) en la lista de IPs permitidas del proveedor — **no hecha**.
3. Remitente alfanumérico autorizado («CLARTO») y saldo de créditos.
4. Números de prueba → `FIRMA_SMS_TEST_DESTINATIONS`; solo cuando el owner lo decida,
   `FIRMA_SMS_ONLY_TEST_DESTINATIONS=false`.
5. Opcional: URL de acuse (DLR, `dlr-url` con `%i`/`%d`/`%s`) — no implementada; hoy se guarda «aceptado» y el id.

**Despliegue (orquestador)**: migración `2026_11_21_000008`, los tres ficheros de módulos tocados (parche) y las
variables anteriores. Nada de lo anterior cambia el código.
