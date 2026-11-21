# Comunicaciones con clientes

Cada ficha de cliente tiene sus comunicaciones al estilo de «Conversations» de GoHighLevel: enviar WhatsApp, SMS o
correo, y una línea de tiempo con todo lo enviado y recibido. GoHighLevel sigue llevando la captación; esto es para
los clientes que ya gestiona el equipo (propietarios de oportunidades ganadas, inquilinos, ocupantes, viajeros…).

## Dónde está

* **Ficha del propietario (360)**: pestaña **Comunicaciones** y, arriba, **Contactar** (Enviar WhatsApp / SMS / correo)
  y **Comunicaciones** (panel lateral). En la edición del propietario, los mismos botones.
* **Ficha de contacto** (Contactos; inquilinos, ocupantes y viajeros de Alquileres son contactos): **Contactar**,
  **Comunicaciones** y la sección **Comunicaciones** bajo el formulario.
* **Encargo (expediente)**: **Contactar** y **Comunicaciones** con su propietario.
* **Contactos → Conversaciones**: bandeja con los clientes (último mensaje, no leídos) y la conversación al lado.
* **Contactos → WhatsApp por vincular**: WhatsApp de números que no son de un cliente o lo son de varios.
* **Ajustes de la plataforma → WhatsApp**: línea de la empresa y textos reutilizables (ver `whatsapp.md`).

## Un solo registro

Tabla `clarto_communications` (antes `clarto_whatsapp_messages`; migración `2026_11_25_000001`, conserva filas y
claves). Una fila por mensaje: equipo, cliente (`party_id`), canal (`whatsapp|sms|email`), dirección (`in|out`),
teléfono E.164 o correo, asunto, texto, estado (`pendiente|enviado|entregado|leido|error`, entrantes `recibido`,
`sin_vincular`, `descartado`), motivo del error, finalidad, origen, autor, relacionado (encargo, ocupación, solicitud
de firma), `read_at` (entrantes leídos) y clave de idempotencia.

Quién escribe en él:

| Origen | Cómo | Canal |
|---|---|---|
| Panel (fichas, bandeja) | `App\Communications\Communications::send()` | WhatsApp · SMS · correo |
| WhatsApp entrante | webhook WAHA → `App\WhatsApp\WhatsApp::receive()` | WhatsApp |
| Alquileres (impagos, «Comunicar») | `RentalCommunicationLog` (interfaz `CommunicationLog`) → `send()` | el que pida el flujo |
| Formularios y firma (SMS con enlace) | `Signing::sendSms()` → `Communications::record()` (enlace enmascarado) | SMS |
| Correos del sistema a un cliente | escucha de `MessageSent` → `record()`: **solo el asunto** | correo |

## Canales

* **WhatsApp**: línea de la empresa (WAHA). Si no está conectada, el envío queda **No enviado** con el motivo.
* **SMS**: `App\Signing\SmsGateway` (Red Telecom o simulado, `FIRMA_SMS_DRIVER`). Máximo 160 caracteres. Mientras
  `FIRMA_SMS_ONLY_TEST_DESTINATIONS` esté activo, solo a `FIRMA_SMS_TEST_DESTINATIONS`; al resto, **No enviado**.
* **Correo**: buzón del panel (Ajustes de la plataforma → Correo saliente). Las **respuestas llegan a ese buzón**,
  no al panel (no hay lectura de correo entrante).

## Reglas

* **Equipo**: cada fila lleva `team_id`; la ficha, la bandeja y los envíos solo aceptan contactos del equipo actual
  (otra empresa: 404 / rechazo).
* **Finalidad**: servicio, cobro, incidencia, contrato, viajeros, liquidación, firma — siempre; **comercial** solo si
  el contacto tiene consentimiento (`consent_at`).
* **Datos mínimos**: de los correos del sistema solo se guarda el asunto; los enlaces de firma se guardan enmascarados.
* **Sin duplicados**: una clave de idempotencia por envío (doble clic o reintento no reenvía); los eventos y mensajes
  de WhatsApp se deduplican (ver `whatsapp.md`).
* **Fallos visibles**: lo que no sale queda en la ficha como **No enviado** con el motivo.

## Prueba

```bash
php artisan clarto:comunicaciones-prueba   # proveedores simulados, transacción revertida
php artisan clarto:whatsapp-prueba
php artisan clarto:alquileres-prueba       # el aviso de impago llega a la ficha del inquilino
```

## Pendiente del propietario

* **SMS**: alta y acreditación del remitente con Red Telecom; credenciales en `.env` (`FIRMA_SMS_DRIVER=redtelecom`,
  `REDTELECOM_SMS_USER/PASSWORD/SENDER`), IP del servidor en su lista y, para clientes reales,
  `FIRMA_SMS_ONLY_TEST_DESTINATIONS=false`.
* **Correo**: el buzón de envío (Correo saliente) y quién lee sus respuestas; si se quiere ver las respuestas en la
  ficha, hace falta un buzón con IMAP o un reenvío entrante (no incluido).
* **WhatsApp**: escanear el QR con la línea de pruebas (ver `whatsapp.md`).
