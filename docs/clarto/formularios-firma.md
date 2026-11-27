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
por la ruta HTTP real, transacción deshecha, ficheros en un disco temporal). 39 comprobaciones.

Comprobación del servidor, solo lectura: `php artisan clarto:firma-comprobar` (la lanza `scripts/set-docuseal.sh` de
pwb). Dice si DocuSeal acepta la clave, si está el secreto del webhook y si cada formulario activo apunta a una
plantilla de DocuSeal que existe, no está archivada y tiene sus roles y campos. Nunca muestra la clave ni el secreto.

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

### Decisión: la misma cuenta de DocuSeal que Teleco, llamada directamente

Revisado en `red-telecom/teleco-viabilidad` el 27/11/2026 (`docs/DOCUSEAL-CONFIGURACION.md`,
`docs/FIRMA-API-DISENO.md`, `docs/FIRMA-API-CONTRATO.md`, fichas `DOCUSEAL-API-001`, `FIRMA-API-001`,
`FIRMA-DOCUMENTOS-001`):

* Teleco usa **DocuSeal en la nube, no autoalojado**. Pruebas: cuenta en Test Mode en la nube global
  (`api.docuseal.com`), ya probada contra la API real el 23/11. Producción: cuenta en la nube UE
  (`api.docuseal.eu`, la única con firma avanzada). El plan Pro está pagado desde el 25/11.
* La **«Firma API» compartida** (`/firma/v1/…`, un único motor para todas las apps) es un **diseño sin rutas
  montadas**: su contrato v0.1.0 dice «Sin rutas montadas, sin proveedor real, sin envíos. NOT DEPLOYED». Hoy no hay a
  qué llamar.

**Elección: (a)**. Liberu llama directamente a la misma cuenta de DocuSeal del grupo: misma nube y mismas consolas, con
claves y webhook propios en el servidor del panel. Las URL de la API siguen fijas (`api.docuseal.com` /
`api.docuseal.eu`), porque la clave nunca debe viajar a otro sitio. No hace falta `DOCUSEAL_URL`, porque no hay
servidor propio. Los hosts de descarga se pueden ampliar con `DOCUSEAL_DOWNLOAD_HOSTS` si la cuenta real sirve los
PDF desde otro dominio. Por qué es la vía más simple y segura:

* Funciona ya, sin esperar a un servicio que no existe. Lo único que cambia es la configuración del servidor.
* Teleco no tiene que tocar nada.
* El panel sigue hablando con DocuSeal solo a través de `SignatureProvider`. Cuando la Firma API esté desplegada, se
  añade `FirmaApiProvider` y se cambia `FIRMA_DRIVER`. Datos, pantallas y pruebas no cambian. Cada solicitud guarda
  su `driver` y su `environment`, así que lo ya enviado se sigue conciliando con el proveedor con el que salió.

**Consecuencias de compartir la cuenta (ya resueltas en el código):**

* Un webhook de DocuSeal recibe los eventos de **toda la cuenta**: al panel le llegan también los de Teleco, y a Teleco
  los del panel. El panel responde 200 «huérfano» a lo que no es suyo y de eso **solo guarda el tipo y los ids**. De
  sus propios eventos tampoco guarda correos, IP, navegador, valores ni enlaces. Teleco también trata como huérfano lo
  que no es suyo.
* Las plantillas de CLARTO conviven con las de Teleco en la misma cuenta. Para no mezclarlas, créalas en una carpeta
  «CLARTO Inmobiliaria». El panel usa el id de plantilla de cada versión de formulario, nunca un nombre.
* La cuenta de **pruebas** está en la nube global, fuera de la UE. Con `DOCUSEAL_ENVIRONMENT=sandbox`, el panel
  **rechaza** antes de enviar cualquier correo que no sea de un dominio reservado (`example.com/.net/.org`, `.test`,
  `.example`, `.invalid`, `.localhost`) o un alias `+test`, y cualquier móvil que no esté en
  `FIRMA_SMS_TEST_DESTINATIONS`. Es la misma regla que aplica Teleco.
* Si Teleco y CLARTO usan la misma clave de la API, al rotarla hay que cambiarla en los dos servidores.

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
único): una entrega repetida responde 200 «duplicado» y no hace nada. Del evento se guarda solo el tipo, la hora, los
ids y las marcas de estado (nunca correos, IP, navegador, valores ni enlaces). De los eventos de otras empresas de la
cuenta compartida, solo el tipo y los ids. El evento es **solo un aviso**: el estado se
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
| `DOCUSEAL_SEND_EMAIL` | `false` (`true` en el servidor, por `compose.yaml`) | que DocuSeal envíe además el correo con el enlace |
| `DOCUSEAL_DOWNLOAD_HOSTS` | — | hosts extra (separados por comas, con sus subdominios) desde los que descargar PDFs firmados, solo si la cuenta real los sirve desde otro dominio |
| `FIRMA_SMS_DRIVER` | `simulado` | `redtelecom` para SMS reales |
| `REDTELECOM_SMS_URL` | `https://sms.redtelecom.es/Api/rest/message` | API REST del dealer |
| `REDTELECOM_SMS_USER` / `_PASSWORD` | — | HTTP Basic |
| `REDTELECOM_SMS_SENDER` | `CLARTO` | remitente (≤ 11 alfanuméricos) |
| `FIRMA_SMS_ONLY_TEST_DESTINATIONS` | `true` | solo números de prueba |
| `FIRMA_SMS_TEST_DESTINATIONS` | — | lista separada por comas (`34600000000`, sin «+») |

Con `DOCUSEAL_SEND_EMAIL=false` el enlace llega al firmante **solo** por el SMS que se envía desde la solicitud.
Mientras no haya SMS reales, el servidor deja el correo de DocuSeal activado (`true` en `compose.yaml`).

## Activar la firma real: pasos para el propietario

Usa las mismas cuentas de DocuSeal que Teleco. Hazlo primero en **pruebas** y después en **producción**. En cada
entorno:

1. **Clave de la API.** Entra en la consola de esa cuenta (pruebas: `console.docuseal.com`, en Test Mode; producción:
   `console.docuseal.eu`), ve a **API** y copia el *Access token*. No lo pegues en ningún chat.
2. **Webhook.** En **Webhooks → New Webhook**:
   - URL: `https://panel.clarto.es/api/v1/firma/docuseal`
   - Eventos: `form.viewed`, `form.started`, `form.completed`, `form.declined`, `submission.completed`,
     `submission.expired`, `submission.archived`
   - En **Security → HMAC**, copia el secreto (`whsec_…`).

   El webhook de Teleco se queda como está.
3. **Guardar en el servidor.** Por SSH, en `/srv/clarto-staging/pwb/deploy/clarto-staging`:

   ```
   sudo bash scripts/set-docuseal.sh pruebas      # o: produccion
   ```

   Te pide la clave y el secreto sin mostrarlos, elige ese entorno y comprueba la conexión. Las firmas reales siguen
   **desactivadas**.
4. **Plantillas.** Crea en DocuSeal (carpeta «CLARTO Inmobiliaria») la plantilla de cada documento, con los roles
   «Propietario» y «Agencia» y los nombres de campo del formulario. Después, en el panel, ve a **Formularios →
   Nueva versión** y pon el id de la plantilla en el campo del entorno. Vuelve a comprobar:

   ```
   sudo bash scripts/set-docuseal.sh --test
   ```

5. **Activar.** Ejecuta `sudo bash scripts/set-docuseal.sh --activar`. Para volver a simular, usa `--desactivar`;
   para borrar las claves, `--off`.

En **pruebas**, el panel solo deja enviar a correos ficticios (`…@example.com` o un alias `+test`) y a los móviles de
prueba. Prueba completa: envía, firma, mira que la solicitud pase a «Completada» y que se guarden el PDF firmado y el
registro de auditoría. Así se cierra lo que sigue **sin verificar** con la cuenta real: forma exacta de los eventos,
`phone_verified`, hosts de descarga y si el plan incluye `audit_log_url`. Si la descarga falla por el host, añádelo
con `DOCUSEAL_DOWNLOAD_HOSTS` en `.env`.

Antes de **producción** (decisiones tuyas):
- que el contrato de tratamiento (DPA) de la cuenta de DocuSeal del grupo cubra a CLARTO SOLUTIONS SL;
- marca y remitente del correo en la consola UE;
- plazo de caducidad de cada documento, copia al firmante al completar y retención en DocuSeal;
- los textos **definitivos y aprobados** de cada documento. Las plantillas de firma **avanzada** llevan «Verify ID» en
  modo AES y solo existen en la nube UE.

Cambiar de entorno con el script desactiva las firmas reales hasta el siguiente `--activar`. Las solicitudes enviadas
en el otro entorno dejan de actualizarse desde el panel, así que termina o anula las de pruebas antes de pasar a
producción.

**SMS del dealer (Red Telecom)**
1. Credenciales API (usuario y contraseña) → `REDTELECOM_SMS_USER` / `REDTELECOM_SMS_PASSWORD` — **no acreditadas**.
2. Alta de la IP del servidor (57.129.192.99) en la lista de IPs permitidas del proveedor — **no hecha**.
3. Remitente alfanumérico autorizado («CLARTO») y saldo de créditos.
4. Números de prueba → `FIRMA_SMS_TEST_DESTINATIONS`; solo cuando el owner lo decida,
   `FIRMA_SMS_ONLY_TEST_DESTINATIONS=false`.
5. Opcional: URL de acuse (DLR, `dlr-url` con `%i`/`%d`/`%s`) — no implementada; hoy se guarda «aceptado» y el id.

**Despliegue (orquestador)**: migración `2026_11_21_000008`, los tres ficheros de módulos tocados (parche) y las
variables anteriores. Nada de lo anterior cambia el código.
