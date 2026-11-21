# WhatsApp en el panel (WAHA)

El equipo escribe y recibe WhatsApp desde la ficha del propietario y desde el expediente, con la línea de la
empresa. GoHighLevel sigue llevando la captación y sus automatizaciones; el panel usa WhatsApp para los expedientes
abiertos cuando una oportunidad se gana en GHL y para los clientes que el equipo ya gestiona.

## Arquitectura

```
móvil de la línea ──(dispositivo vinculado por QR)── WhatsApp
                                                        │
                       red interna de Docker            │
liberu-app ──X-Api-Key──► waha:3000 (WAHA Core, GOWS) ◄─┘
     ▲                         │
     └── webhook firmado ──────┘  POST http://liberu-app/api/v1/whatsapp/webhook
         (HMAC SHA-512)
```

* **WAHA** (`devlikeapro/waha`, imagen fijada en `compose.yaml` del bundle): servicio `waha` **sin puertos
  publicados ni ruta en Caddy**. Solo `liberu-app` lo alcanza, con `X-Api-Key` (`WAHA_API_KEY`). La sesión
  vinculada se guarda en el volumen `waha_sessions`.
* **Webhook**: el panel configura la sesión con su URL interna, la clave HMAC (`WAHA_WEBHOOK_HMAC_KEY`), los
  eventos `message`, `message.ack`, `session.status` y reintentos exponenciales. El panel rechaza lo que llega sin
  firma válida (401) o a través del proxy público (403).
* **Una línea por equipo** (`clarto_whatsapp_lines`): sesión WAHA, estado, número conectado y textos
  reutilizables. WAHA Core solo admite la sesión `default` (una línea); para varios equipos hace falta WAHA Plus
  y `WAHA_SESSION=team-{team}`.
* **Mensajes** (`clarto_whatsapp_messages`): enviados y recibidos, por equipo y teléfono en E.164, vinculados al
  contacto (Party) y, si se enviaron desde un expediente, al encargo.
* **Vínculos teléfono → contacto** (`clarto_whatsapp_links`): se crean al enviar desde una ficha o al resolver la
  cola, y mandan sobre la coincidencia por teléfono.
* **Eventos procesados** (`clarto_whatsapp_events`): id de evento único.

Código: `app/WhatsApp/` (cliente WAHA, teléfonos, lógica), `app/Models/WhatsApp*.php`,
`app/Http/Controllers/WhatsAppWebhookController.php`, `app/Filament/Pages/WhatsAppSettings.php`,
`app/Filament/Pages/WhatsAppQueue.php`, `app/Filament/WhatsAppConversation.php`.

## Sin duplicados

* **Enviar**: cada formulario de envío lleva una clave de idempotencia; un doble clic o una petición repetida
  devuelve el mensaje ya enviado y no lo reenvía. No se envía nada con campos `{…}` sin completar.
* **Recibir**: un evento se procesa una sola vez (id de evento único, en la misma transacción que sus efectos: si
  algo falla, WAHA reintenta). Un mensaje se guarda una sola vez por equipo e id de WhatsApp, aunque llegue en
  otro evento.

## Vinculación de las conversaciones

Un mensaje entrante se asocia por **equipo + teléfono normalizado (E.164)**:

1. al contacto vinculado a ese número (por un envío desde su ficha o por la cola);
2. si no, al único contacto del equipo con ese teléfono;
3. si lo tienen **varios contactos** (o ninguno), va a **Contactos → WhatsApp por vincular**: el equipo elige el
   contacto (y ese número queda vinculado) o lo descarta. Los números desconocidos no crean contactos: la captación
   de leads sigue en GoHighLevel.

## Dónde se usa

* **Ajustes de la plataforma → WhatsApp** (Comunicaciones; solo administradores): vincular la línea por QR (el QR se
  obtiene en el servidor y se refresca solo), ver número y estado, reconectar, enviar una prueba, desconectar y
  editar los textos reutilizables (visita, seguimiento, enlace de firma).
* **Ficha del contacto / propietario** y **expediente (encargo)**: botón **WhatsApp** con la conversación y el
  formulario de envío (destinatario y texto revisables; texto reutilizable rellenado con la ficha). Para la ficha
  360 del propietario: `App\Filament\WhatsAppConversation::tab()` (o `::section()`).
* Campos de los textos: `{nombre}`, `{nombre_completo}`, `{referencia}`, `{direccion}`, `{fecha_visita}` (próxima
  visita), `{agente}`, `{empresa}`, `{enlace_firma}`. Otros módulos añaden campos con
  `App\WhatsApp\WhatsApp::placeholder('enlace_firma', fn ($party, $instruction) => …)`.

## Prueba automática

```bash
php artisan clarto:whatsapp-prueba
```

WAHA simulado (`Http::fake` con estado), webhook por la ruta real, todo en una transacción que se deshace: sesión
(alta, QR, estado), envío desde la ficha y desde el expediente, envío repetido sin reenvío, acuse de entrega,
respuesta entrante vinculada a la propietaria correcta, teléfono compartido → cola, número desconocido → cola sin
crear contactos, resolución, webhook duplicado sin duplicados, firma no válida, entrada por el proxy,
desconexión y reconexión.

## Prueba real (lo que tiene que hacer CLARTO)

1. Tener a mano el **móvil de la línea de prueba** con WhatsApp (o WhatsApp Business) instalado y activo.
2. Entrar en https://panel.clarto.es como administrador → **Ajustes de la plataforma → WhatsApp** →
   **Vincular línea**.
3. En el móvil: WhatsApp → Ajustes → **Dispositivos vinculados** → **Vincular un dispositivo** y escanear el QR
   del panel. En unos segundos el estado pasa a **Conectada** y aparece el número.
4. **Enviar prueba** a un móvil propio y comprobar que llega.
5. Abrir un contacto con ese móvil propio como teléfono → **WhatsApp** → elegir un texto, revisarlo y **Enviar
   WhatsApp**. Responder desde el móvil: la respuesta aparece en la conversación del contacto.
6. Para dejar de usarla: **Desconectar** (el dispositivo desaparece de «Dispositivos vinculados»).

Aviso: WAHA usa WhatsApp Web (no la API oficial de Meta). Conviene una línea dedicada y mensajes a contactos que
esperan el mensaje; el envío masivo puede hacer que WhatsApp bloquee el número.
