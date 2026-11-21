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
  de la empresa). Usuarios = `profiles` (id = `auth.users.id`); la RPC `lookup_user_by_email(_email)` devuelve el id
  de perfil por e-mail y solo la pueden usar owner/admin/manager de alguna empresa.
* `tasks`: `title`, `description`, `due_date` (fecha), `assignee_id`, `status` (`pendiente`, `en_curso`,
  `en_revision`, `bloqueada`, `completada`), `priority`, `public_ref` (`TSK-00042`, por trigger) y
  **`source_provider` + `source_external_id`** (referencia externa, sin índice único).
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

```
TARMIO_DRIVER=http
TARMIO_SUPABASE_URL=https://<ref>.supabase.co          # el de TARMIO
TARMIO_SUPABASE_KEY=sb_publishable_…                  # clave publicable de TARMIO
TARMIO_USUARIO=integracion-liberu@clarto.es            # usuario de integración (ver abajo)
TARMIO_PASSWORD=…
TARMIO_ORGANIZATION_ID=<uuid de la empresa CLARTO en TARMIO>
TARMIO_PROJECT_ID=<uuid del proyecto, opcional>
TARMIO_RESPONSABLE_CAPTACION=<e-mail del responsable de altas de propietario>
```

Requiere el worker de colas en marcha (ya existe en staging). Después: `php artisan migrate` y
`php artisan config:cache` si se usa caché de configuración.

## Lo que hay que pedir / proponer en TARMIO

1. **Usuario de integración** (p. ej. `integracion-liberu@clarto.es`) con contraseña, miembro de la empresa CLARTO
   con rol **manager** (lo necesita `lookup_user_by_email` y para actualizar tareas reasignadas). Ids de la empresa
   CLARTO y del proyecto donde caerán las tareas.
2. Que los responsables de Liberu existan en TARMIO **con el mismo e-mail** en su perfil.
3. Propuesta (no imprescindible): índice único parcial
   `create unique index on public.tasks (organization_id, source_provider, source_external_id) where source_external_id is not null;`
   — garantiza en TARMIO lo que hoy garantiza Liberu (fila única + bloqueo + búsqueda previa).
4. Propuesta: sustituir el usuario con contraseña por un token de servicio limitado (una ruta `/api/public/tareas`
   con clave propia que haga upsert por `source_external_id`); Liberu solo cambiaría `HttpTarmioClient`.
5. Propuesta: mostrar el enlace de la descripción como botón «Abrir en CLARTO Gestión Inmobiliaria».
