# Correo entrante (info@clarto.es)

Módulo CLARTO «Correo entrante» (`clarto-correo`, rama `feat/correo-entrante`). Lee el buzón general por IMAP,
**solo lectura**, guarda una copia de cada correo y lo reparte según lo que sea. Nunca responde, nunca reenvía fuera
y nunca marca como leído, mueve ni borra nada en el buzón real.

## Qué hace con cada correo

1. **Lectura** — `clarto:correo-sondear` (programado cada minuto; respeta el intervalo del panel). Abre la bandeja con
   `EXAMINE` (sesión de solo lectura) y descarga con `BODY.PEEK[]`; el cliente (`App\InboundMail\ImapMailbox`) solo
   puede enviar órdenes de una lista blanca (sin `STORE`, `MOVE`, `EXPUNGE`, `SELECT`…). Cursor UIDVALIDITY + último UID;
   idempotente por Message-ID. **La primera lectura empieza en el correo más reciente**: no importa el histórico.
   Si el servidor cambia UIDVALIDITY, relee desde la última lectura sin duplicar.
2. **Copia** — cabeceras, texto, HTML saneado (sin scripts, eventos ni imágenes remotas) cifrados en la base de datos;
   adjuntos (hasta 20 MB cada uno) en el disco privado. Conservación: 730 días por defecto (24 meses, como la política
   de privacidad), publicidad y automáticos 30 días.
3. **Clasificación determinista primero** (la primera señal que aparezca decide; dos categorías distintas al mismo
   nivel = conflicto → revisión):
   - *hilo*: `In-Reply-To`/`References` apuntan a un correo nuestro (`<clarto-com-{id}@…>` de cualquier comunicación,
     `<clarto-val-VAL-…>` de los informes de valoración — ahora se sellan al enviarse);
   - *referencia*: `SOL-AAAA-NNNNNN` o `VAL-NNNNNN` del equipo en asunto o texto;
   - *regla*: correcciones del equipo convertidas en regla de remitente o de dominio;
   - *remitente*: colaborador (con solicitudes enviadas → solicitud), contacto/propietario de la empresa, solicitante de
     una valoración (contacto GHL que entró por la web);
   - *cabeceras*: `List-Unsubscribe`, `Precedence: bulk`, marca de spam → publicidad.
4. **IA solo para el resto** (`App\Ai\AiClient`, proveedor configurado): se envían **solo el asunto y un extracto
   breve** sin la parte citada y con correos, enlaces, teléfonos, IBAN, DNI/NIE/CIF y números largos sustituidos.
   Nunca adjuntos. Devuelve categoría, área y confianza; por debajo de 0,7 → revisión. Si dice «cliente/propietario/
   solicitud/colaborador» sin que exista el expediente → revisión.
5. **Reparto**

| Categoría | Destino |
|---|---|
| Respuesta a solicitud de inmueble | Servicio de Solicitudes (`SearchRequests::inboundMail`, forzado al equipo del buzón): vinculada, o a «Respuestas por vincular» |
| Propietario / valoración, Cliente con expediente | Cronología de comunicaciones del contacto (y de la valoración) |
| Consulta nueva (inmobiliaria, alquiler, gestoría, mercantil, extranjería, fiscal, otros), Colaborador sin solicitud, Proveedor/factura, Administración/notificación oficial, Otros | «Entrada» con tarea TARMIO al responsable del área (solo categoría, área y número; ni remitente ni asunto) |
| Publicidad / spam | Archivo del panel |
| Acuses de lectura/entrega, rebotes, respuestas automáticas (`Auto-Submitted`, `multipart/report`, MAILER-DAEMON, asuntos típicos) | Archivo; nunca cuentan como respuesta (en una solicitud quedan como «acuse») |
| Confianza baja o señales contradictorias | «Pendiente de revisar» con la categoría sugerida |

El endpoint HTTP `POST /api/v1/solicitudes/correo-entrante` sigue funcionando; ambos caminos usan la misma clave de
idempotencia, así que un correo que llegue por los dos se registra una vez.

## Pantallas (Venta y alquiler → Correo entrante)

- **Pendiente de revisar**: «Confirmar» la sugerencia o «Clasificar» (categoría, área, contacto). La corrección puede
  recordarse para el remitente o para todo su dominio (no para dominios públicos como gmail.com).
- **Entrada**: consultas y avisos por área; «Atendido» al terminar; «Reclasificar».
- **Enrutados** y **Archivo**: historial; desde el archivo se puede reclasificar.
- **Ajustes** (administradores): leer el buzón sí/no (solo una empresa), intervalo, IA sí/no, responsable de cada área
  (usuario de TARMIO), días de conservación, «Probar conexión», «Leer ahora».

## Permisos

Solo personal de la empresa (los colaboradores nunca). Ven la bandeja: super administradores, administradores del
equipo y quien tenga `correo.ver` en ese equipo. Cuerpos y adjuntos: administradores y `correo.contenido`. Otra
empresa no ve nada (página, consulta y descarga de adjuntos filtradas por equipo).

## Lo que tiene que hacer el propietario

1. Desplegar (orquestador) Liberu `feat/correo-entrante` y el bundle PWB `feat/correo-entrante` (añade `MAIL_IN_*`).
2. En el servidor, con la contraseña de info@ (se teclea oculta, nunca se muestra):

   ```
   scripts/set-imap.sh info@clarto.es
   ```

   (opcional `imap.hostinger.com 993`; comprobar con `scripts/set-imap.sh --test`).
3. En el panel: Correo entrante → Ajustes → activar «Leer el buzón», indicar los responsables de cada área y guardar.
4. Decidir si la IA clasifica (activada por defecto; usa el modelo de traducción configurado en Inteligencia artificial).

## Pruebas

`php artisan clarto:correo-prueba` (buzón IMAP falso en un par de sockets — el cliente real habla con él —, IA falsa,
TARMIO simulado, datos ficticios, todo se deshace): 29 comprobaciones — respuesta a solicitud vinculada, respuesta a
valoración en la cronología, consulta nueva a «Entrada» con tarea TARMIO, publicidad archivada sin IA, ambiguo a
revisión, corrección → regla de dominio, relectura idempotente, buzón nunca modificado, respuesta automática ignorada,
otra empresa sin acceso, permisos ver/contenido, pantallas, HTML saneado, conservación.

## Límites conocidos

- No consulta GHL en vivo por cada correo: el «contacto GHL» se reconoce por las valoraciones recibidas de la web.
- Un buzón y una carpeta (`MAIL_IN_FOLDER`, INBOX); 50 correos por lectura (`MAIL_IN_LOTE`).
- Completar en TARMIO la tarea de una entrada no la marca «Atendido» (TARMIO no se lee de vuelta).
