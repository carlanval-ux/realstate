# Solicitudes de inmuebles

Encargo del propietario (21/11/2026), apartados 3–5 y la parte de solicitudes de 6–7. Construido sobre el contrato de
«Colaboradores» (`orquestacion/colaboradores/contrato.md`): colaboradores, acceso (`CollaboratorAccess`), avisos
(`CollaboratorNotices`) y panel `/colaboradores`.

## Qué hace

- **Bandeja interna** (Venta y alquiler → Solicitudes de inmuebles): título, operación, tipo, municipio, zonas,
  dirección, superficie mín./máx., presupuesto, habitaciones, características, imprescindibles, preferencias, plazo de
  necesidad, fecha límite (obligatoria, 21 días por defecto, zona horaria de la empresa), descripción, enlaces de
  ejemplo, adjuntos (disco privado), responsable interno y destinatarios (uno, varios o todos los activos).
- Al crearla queda en **Borrador**. Desde su ficha: **Previsualizar** (correo y WhatsApp tal y como los recibirá cada
  colaborador, en su idioma), **Enviar a colaboradores**, **Enviar actualización** (sube la versión, reenvía el
  contenido completo con los cambios al principio a quien ya la recibió), **Cambiar estado / registrar resultado**.
- Estados: Borrador → Abierta (al enviar) → En evaluación (primera propuesta) → Cubierta (al elegir propuesta) /
  Cerrada. Seguimiento por colaborador: Sin enviar · Enviada · Respondida · Retirada, con su última respuesta. La
  entrega o lectura (correo/WhatsApp) se muestra aparte y **nunca** cuenta como respuesta.
- Referencia correlativa por empresa: `SOL-2026-000123`, en panel, correo, WhatsApp y TARMIO.
- **Envíos**: un `CollaboratorNotice` por colaborador y envío (copia exacta de correo + bloques de WhatsApp, resultado
  por canal). Nunca se envía dos veces un canal o bloque; «Reintentar» solo repite lo fallido; antes de cada intento se
  comprueba otra vez el acceso (colaborador de alta y destinatario no retirado). Los avisos abiertos se reintentan cada
  10 minutos (programación de «Colaboradores»). Retirar a un destinatario cancela sus envíos pendientes.
- **Mensajes completos**: texto base del propietario, «tú», firma «Equipo CLARTO», en es/en/tr según el colaborador;
  todos los datos rellenados, referencia y fecha límite, y el enlace al panel al final. WhatsApp largo en bloques
  ordenados `(1/n)` (`SOLICITUDES_WHATSAPP_BLOQUE`, 3500 caracteres por defecto).
- **Colaborador** (`/colaboradores/solicitudes`): solo las solicitudes que se le enviaron (no borradores ni retiradas)
  y solo sus respuestas. Responder: Tengo un inmueble que encaja / Puedo buscarlo o conseguirlo / Necesito
  aclaraciones / No puedo atenderlo, con descripción, ubicación, precio, características, enlaces, fotos y plazo.
  Un enlace directo a una solicitud ajena da 404 (servidor).
- **Respuestas por correo y WhatsApp**: se vinculan solas solo si es seguro — WhatsApp que cita nuestro mensaje,
  correo que responde a nuestro hilo (`Message-ID <clarto-com-{id}@dominio>`), o la referencia de una única solicitud
  enviada a ese colaborador. Si no, van a **Respuestas por vincular** (y salen de «Mensajes sin asignar»). Los acuses
  de lectura/entrega y respuestas automáticas se anotan en el historial, no como respuesta.
- **Comparar propuestas**: tabla con colaborador, respuesta, precio, ubicación, plazo, enlaces y fotos; clasificar,
  pedir aclaración (por ambos canales, completa), **Crear inmueble** (borrador del equipo con los datos de la
  propuesta) o **Vincular inmueble existente**; la solicitud pasa a Cubierta y se continúa en la ficha del inmueble.
- **TARMIO**: «Seguimiento de la solicitud» (vence en la fecha límite) y «Revisar respuestas» (al llegar una).
- **Auditoría**: pestaña Historial (creada, asignada, modificada, enviada, actualización, aclaración, reintento,
  respuesta, vinculada, acuse, retirado, estado, inmueble), con usuario y fecha.
- Módulo CLARTO `solicitudes` (Plataforma → Módulos). Apagado (o «Colaboradores» apagado) no se envía nada nuevo.

## Correo entrante

No había lectura de buzón en el panel. Las respuestas por correo entran por
`POST /api/v1/solicitudes/correo-entrante` (cabecera `X-Solicitudes-Clave` = `SOLICITUDES_CORREO_ENTRANTE_CLAVE`),
JSON `{from, subject, text, message_id, in_reply_to, references, auto_submitted, content_type}`, desde el reenviador
del buzón de respuestas (p. ej. un script IMAP o el «parse» del proveedor). Idempotente por Message-ID. Sin clave
configurada responde 503 y el módulo lo indica como configuración pendiente.

## Pruebas

`php artisan clarto:solicitudes-prueba` (proveedores simulados, base de datos revertida): dos colaboradores ficticios,
contenido completo por ambos canales, respuestas por WhatsApp y correo vinculadas, una ambigua a la bandeja, acuses
que no cuentan, reintento sin duplicar, acceso solo a lo propio y enlace directo bloqueado, propuesta → inmueble.
