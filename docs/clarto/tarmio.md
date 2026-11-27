# Tareas en TARMIO desde Liberu

Cada acción pendiente de un expediente de Liberu (nuevo propietario desde GHL, firma pendiente, entrada o salida,
avería, albarán con error de envío) se convierte en **una** tarea de TARMIO con título, responsable, vencimiento y
enlace directo al registro de Liberu. Si cambia la fecha o el responsable se actualiza **la misma** tarea; los
reintentos y los eventos repetidos no duplican.

## Estado

**Envíos reales desactivados.** `TARMIO_DRIVER=simulado` (por defecto): las tareas se registran en Liberu con ids
`sim-…` y solo se escribe una línea en el log. Al activar `http`, las tareas simuladas pendientes se crean de verdad
en la siguiente sincronización. Prueba: `php artisan clarto:tarmio-prueba` (TARMIO y GHL simulados con
`Http::fake`, base de datos revertida; no envía nada).

## Lo que hemos visto de TARMIO (solo lectura, `C:\dev\tarmio`)

* App TanStack Start (Lovable) sobre **Supabase**. No tiene API REST propia: se escribe en la tabla `tasks` vía la
  API de Supabase (PostgREST) con el JWT de un usuario; las RLS aplican como ese usuario. También expone un servidor
  MCP (`/mcp`, OAuth de Supabase) con `list_organizations`, `list_projects`, `list_tasks`, `create_task`,
  `update_task`, `add_task_comment` — pensado para asistentes, no para integraciones servidor-servidor.
* Empresa = `organizations` (`organization_id` obligatorio en la tarea); proyecto opcional (`project_id` nulo = bandeja
  de la empresa). Usuarios = `profiles` (id = `auth.users.id`, con `email`); un miembro ve los perfiles de los
  demás miembros de su empresa. Pertenencia y rol: `organization_memberships`.
* `tasks`: `title`, `description`, `due_date` (fecha), `assignee_id`, `status` (`pendiente`, `en_curso`,
  `en_revision`, `bloqueada`, `completada`), `priority`, `public_ref` (`TSK-00042`, por trigger) y
  **`source_provider` + `source_external_id`** (referencia externa, con índice único por empresa).
* Permisos: insertar/actualizar exige ser miembro de la empresa; con rol `manager` puede tocar cualquier tarea de ella.
* **Sin tokens de API ni webhooks salientes.** Por eso Liberu entra con un usuario de integración (contraseña → JWT,
  cacheado hasta que caduca) y nunca lee el estado de TARMIO.

## Cómo está hecho

* `App\Tarmio\TarmioTask` (tabla `clarto_tarmio_tasks`): una fila por **registro de origen + tipo de acción**, con
  índice único (`source_type`, `source_id`, `kind`). Guarda el id y la referencia de la tarea en TARMIO, el último
  contenido enviado (huella), estado `pendiente | sincronizada | error`, último error e intentos.
* `TarmioTask::sync(...)` actualiza la fila y, si algo cambió, encola `App\Tarmio\SyncTarmioTask` (tras el commit;
  5 intentos con espera 1 min → 1 h). Mismo contenido = no encola nada.
* El trabajo, bajo un bloqueo por fila: si ya hay id → `PATCH`; si no, busca por `source_external_id`
  (`tipo:registro:id`, p. ej. `averia:maintenance_request:12`) por si se creó justo antes de una caída; si no
  existe → `POST`. Nunca envía `status`: completar la tarea en TARMIO no cambia el expediente en Liberu, y Liberu no
  la reabre.
* Cliente detrás de `App\Tarmio\TarmioClient`: `HttpTarmioClient` (Supabase) y `SimulatedTarmioClient`. Se elige en
  `config/tarmio.php` y se registra en el bloque «TARMIO» de `ClartoServiceProvider`.
* **Datos que salen de Liberu:** título (con la referencia del inmueble, sin nombres), responsable (id de TARMIO
  resuelto por e-mail), vencimiento, enlace al panel y la referencia técnica. Nada de documentos, DNI, teléfonos,
  e-mails de clientes ni importes. Si el responsable no existe en TARMIO, la tarea se crea sin asignar y la fila lo
  indica en `error`.

## Disparadores

| Acción | Estado | Origen |
|---|---|---|
| Nuevo propietario (GHL ganada) | **activo** | `GhlHandoff` procesado → tarea `alta_propietario` sobre el inmueble en borrador, enlace a su ficha, vence a 2 días hábiles, responsable `TARMIO_RESPONSABLE_CAPTACION` o el dueño del equipo |
| Firma pendiente | a cablear (módulo formularios y firma) | ver abajo |
| Entrada / salida | a cablear (módulo alquileres) | ver abajo |
| Avería | a cablear (módulo alquileres o donde se gestione `MaintenanceRequest`) | ver abajo |
| Albarán con error de envío | a cablear (módulo albaranes) | ver abajo |

### Cómo lo llama cada módulo

Un solo punto de entrada, idempotente; llamarlo en cada guardado es correcto:

```php
use App\Tarmio\TarmioTask;

TarmioTask::sync(
    $registro,                       // el modelo de Liberu (avería, alquiler, firma, albarán…)
    'entrada',                       // tipo: alta_propietario | firma | entrada | salida | averia | albaran
    'Entrada (check-in) · '.$inmueble->reference(),   // sin nombres ni datos personales
    $responsable?->email,            // e-mail del usuario de Liberu; null = sin asignar
    $fechaEntrada,                   // DateTimeInterface|null
    $urlDelRegistroEnElPanel,        // p. ej. XResource::getUrl('edit', ['record' => $registro], panel: 'admin', tenant: $team)
);
```

* Un registro con dos acciones (entrada y salida) usa dos `kind` distintos → dos tareas.
* Llamar desde un observer `saved` del modelo o desde la acción que crea/cambia la fecha; envolver en
  `try { … } catch (\Throwable $e) { report($e); }` si está dentro de una transacción de negocio, como hace GHL.
* Cuando la acción deja de estar pendiente en Liberu no se toca la tarea (el equipo la cierra en TARMIO). Si hace
  falta cerrarla automáticamente se añade más adelante.

## Activarlo (variables en el `.env` del servidor)

En staging lo hace `scripts/set-tarmio.sh` del paquete de despliegue (pwb, `deploy/clarto-staging`): pide la
contraseña sin mostrarla, encuentra sola la empresa CLARTO, comprueba permisos y **no** activa los envíos hasta
`--activar`. Variables que escribe:

```
TARMIO_DRIVER=simulado                                         # http con --activar
TARMIO_SUPABASE_URL=https://qqwikylpxwscagwuwwbh.supabase.co   # producción de TARMIO (tarmio.app)
TARMIO_SUPABASE_KEY=sb_publishable_eWU8…                       # clave publicable, pública en tarmio.app
TARMIO_USUARIO=<correo del usuario de integración>
TARMIO_PASSWORD=…
TARMIO_ORGANIZATION_ID=<uuid de la empresa CLARTO; el script lo averigua>
TARMIO_PROJECT_ID=<uuid del proyecto, opcional; vacío = bandeja de la empresa>
TARMIO_RESPONSABLE_CAPTACION=<e-mail del responsable de altas de propietario>
```

Requiere el worker de colas en marcha (ya existe en staging).

### Comprobado contra TARMIO real (27/11/2026, solo lectura, sin sesión)

* **La URL de `C:\dev\tarmio\.env` (`yydxadsypttzxwbmrmqj`) ya no existe** (NXDOMAIN; era el proyecto de Lovable).
  TARMIO se sirve desde `tarmio.app` (OVH) y su web usa `qqwikylpxwscagwuwwbh.supabase.co`.
* En producción existen las columnas que usa Liberu (`tasks.source_provider`, `source_external_id`, `public_ref`,
  `created_by`, `due_date`, `assignee_id`; `profiles.email`; `organization_memberships.role`): PostgREST las acepta
  y rechaza una inexistente. Sin sesión no se ve ninguna fila (RLS correcta).
* El inicio de sesión por correo y contraseña está habilitado.
* **Ya existe el índice único** `tasks_source_identity_key (organization_id, source_provider, source_external_id)`
  (migración `dealer_integration_001`): un duplicado devuelve 409 y Liberu actualiza la tarea existente.
* **`lookup_user_by_email` se retira** en la migración `user_lifecycle_001_contract` de TARMIO (hoy aún responde).
  Liberu ya no la usa: busca el responsable en `profiles` por e-mail (exacto, sin distinguir mayúsculas) y exige que
  sea miembro de la empresa CLARTO; si no, la tarea se crea sin asignar y la fila lo indica en `error`.
* Insertar sin proyecto exige ser miembro de la empresa; con proyecto, poder escribir en él. Actualizar cualquier
  tarea de la bandeja exige rol owner/admin/**manager**.
* **El usuario de integración no debe tener verificación en dos pasos**: con un factor verificado una sesión por
  contraseña (aal1) no cuenta y TARMIO le niega el acceso a la empresa (`app.org_access_ok`).
* Una contraseña puesta antes de confirmar el correo se borra al confirmarlo (`user_lifecycle_001_contract`): el
  usuario debe crearse aceptando la invitación (o fijar la contraseña después, en Ajustes).
* Sin el usuario de integración no se pudo ver la empresa ni sus proyectos: `set-tarmio.sh` los lista al conectar,
  y `--prueba` crea una tarea ficticia y la borra.

## Lo que hay que pedir / proponer en TARMIO

1. **Usuario de integración** con correo propio, miembro **solo** de la empresa CLARTO con rol **Manager**, sin
   verificación en dos pasos. Instrucciones para el propietario: `docs/clarto/tarmio-alta.md`.
2. Que los responsables de Liberu existan en TARMIO **con el mismo e-mail** y sean miembros de CLARTO.
3. ~~Índice único~~: ya existe (ver arriba).
4. Propuesta: sustituir el usuario con contraseña por un token de servicio limitado. TARMIO ya tiene esa pieza para
   Red Telecom (`POST /api/public/integrations/dealer/v1`, firma HMAC, empresa fijada en servidor, `ensure_task`
   idempotente — `docs/DEALER_INTEGRATION_001.md` de TARMIO); añadir un principal para CLARTO/Liberu evitaría la
   contraseña. Liberu solo cambiaría `HttpTarmioClient`.
5. Propuesta: mostrar el enlace de la descripción como botón «Abrir en CLARTO Gestión Inmobiliaria».
