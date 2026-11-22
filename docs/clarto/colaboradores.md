<!-- Copia del contrato compartido (orquestacion/colaboradores/contrato.md) a fecha 21/11/2026. -->
# Contrato «Colaboradores» (rama `feat/colaboradores`)

Lo mantiene la sesión «colaboradores». La sesión «solicitudes» construye encima. Si algo no te cuadra, pídelo:
no edites estos archivos, añade lo tuyo en tu propio proveedor.

## Datos (migración `2026_12_01_000001`; «solicitudes» usa `2026_12_02_*`)

| Tabla | Modelo | Qué es |
|---|---|---|
| `clarto_collaborators` | `App\Collaborators\Collaborator` | Colaborador externo: `kind` persona\|empresa, `name`, `legal_name`, `tax_id`, `contacts` (json `[{name, role, email, phone}]`), `email`, `whatsapp` (E.164), `locale` (es\|en\|tr), `owner_id` (responsable interno, users.id), `user_id` (usuario del panel, único), `status` pendiente\|activo\|baja, `invited_at`, `activated_at`, `deactivated_at`, `notes` (interna), `created_by`, `updated_by`, `team_id`. |
| `clarto_collaborator_properties` | `App\Collaborators\CollaboratorProperty` | Asignación inmueble ↔ colaborador. `relation` aportado\|comercializacion\|busqueda, `authorised_price`, `availability`, `commercial_status`, `assigned_by/at`, `revoked_by/at/reason`. Activa = `revoked_at` null. Varias por inmueble. |
| `clarto_property_updates` | `App\Collaborators\PropertyUpdate` | Seguimiento del inmueble: nota interna (`visibility`=interna) o novedad compartida (compartida). |
| `clarto_collaborator_notices` | `App\Collaborators\CollaboratorNotice` | Avisos por correo + WhatsApp (uno por destinatario y asunto). **Úsalos también para solicitudes.** |

Rol Spatie `colaborador` (`CollaboratorAccess::ROLE`), uno por equipo, **sin permisos**. El usuario del colaborador
**no pertenece a ningún equipo** (sin `team_user`, `current_team_id` null): todo lo que filtra por equipo le devuelve vacío.

## Acceso — `App\Collaborators\CollaboratorAccess` (único punto, en servidor)

```php
CollaboratorAccess::isCollaborator(?User): bool          // usuario encerrado en el área de colaboradores (no staff)
CollaboratorAccess::isStaff(?User): bool                 // super_admin|admin|staff (hasAdminAccess)
CollaboratorAccess::collaboratorFor(?User): ?Collaborator // null si staff, sin ficha o dado de baja
CollaboratorAccess::canSeeProperty(?User, Property|int): bool
CollaboratorAccess::scopeProperties(Builder $q, ?User): Builder
CollaboratorAccess::assignment(?User, Property|int): ?CollaboratorProperty
CollaboratorAccess::canSee(?User|Collaborator, Model $record): bool   // genérico (Property, PropertyUpdate, registrados)
CollaboratorAccess::scope(Builder $q, ?User|Collaborator): Builder    // genérico; modelo desconocido ⇒ vacío
CollaboratorAccess::register(string $modelClass, CollaboratorScope $scope): void
```

- Denegar por defecto: un modelo no registrado ⇒ `canSee` false y `scope` vacío. Un colaborador de baja no ve nada.
- Para staff, `canSee*`/`scope*` responden false/vacío: el staff sigue con sus comprobaciones de equipo.
- **Punto de extensión para solicitudes**: implementa `App\Collaborators\CollaboratorScope`
  (`scope(Builder, Collaborator)`, `canSee(Collaborator, Model)`) y regístralo en el `boot()` de tu proveedor:
  `CollaboratorAccess::register(SearchRequest::class, new SearchRequestScope);` (idem respuestas/propuestas).
  `canSeeRequest`/`scopeRequests` = `canSee($user, $request)` / `scope($query, $user)`. `scope()` ya añade el filtro
  `team_id` del colaborador antes de llamar a tu `scope()`. Tus modelos deben tener `team_id`.

## Avisos — `App\Collaborators\CollaboratorNotices`

```php
CollaboratorNotices::send(Collaborator $c, Model $subject, string $dedupeKey, string $emailSubject, string $emailBody, array $whatsappBlocks, ?int $userId = null): CollaboratorNotice
CollaboratorNotices::dispatch(CollaboratorNotice $n): CollaboratorNotice   // reintento
CollaboratorNotices::cancelOpen(Collaborator $c, string $subjectType, iterable $subjectIds, string $reason): int
CollaboratorNotices::retryOpen(): int   // programado cada 10 min
```

- Envía por el registro único `App\Communications\Communications` (correo del panel + WAHA; `related` = el aviso,
  `source` = `colaboradores`). Claves de comunicación `colaborador-aviso:{id}:{canal}:{bloque}` ⇒ nunca duplica.
- `dedupeKey` lo eliges tú y debe ser estable por envío lógico (p. ej. `solicitud:{id}:v{version}:colaborador:{id}`).
  Misma clave ⇒ mismo aviso, no se reenvía lo ya enviado.
- Antes de cada intento comprueba `CollaboratorAccess::canSee($colaborador, $subject)`; sin acceso ⇒ `cancelado`.
  Por eso `$subject` debe ser un modelo registrado (o Property/PropertyUpdate).
- Estados: pendiente | enviado | parcial | error | cancelado; por canal `email_status`/`whatsapp_status`
  (enviado | error | sin_destino). Los textos llegan ya redactados en el idioma del colaborador (`$c->locale`);
  tono B2B: «tú», firma «Equipo CLARTO». Varios bloques de WhatsApp se envían en orden; si uno falla, los
  siguientes esperan al reintento.
- Respuestas entrantes: `Collaborator::findByAddress($teamId, $phone, $email)` (null si no hay exactamente uno).
- Auditoría: `Collaborator::log($modelo, 'evento', [...])` ⇒ activity_log `colaboradores`.

## Nada de colaboradores en GoHighLevel (decisión del propietario, 21/11/2026)

«lo de los colaboradores no debería salir del SaaS, no debe estar en GHL». Reglas:
- No crees Parties (contactos) para colaboradores ni para sus contactos de empresa; la ficha es `Collaborator`.
- Los avisos salen por `CollaboratorNotices` (correo del panel + WAHA), nunca por GHL.
- `Collaborator::ownsAddress($teamId, $phone, $email)` identifica cualquier dirección de un colaborador (también las
  de sus contactos de empresa y los dados de baja). Los ganchos de GHL ya la usan: `GhlWhatsApp::inbound` (respuestas
  de WhatsApp → nota/etiqueta en GHL) y `NurturePlan::partySaved` («cliente-clarto»). Si «solicitudes» añade algún
  envío o sincronización hacia GHL, debe comprobarla antes.
- Prueba: `clarto:colaboradores-prueba` comprueba que ningún flujo de colaboradores llama a GHL (con control positivo).

## Panel de colaboradores (`/colaboradores`, id `colaboradores`)

- Panel Filament propio, sin tenant, login y recuperación de contraseña propios. Solo entran colaboradores
  habilitados (`User::canAccessPanel`); a `/admin` y `/app` no entran.
- `App\Http\Middleware\CollaboratorFence` (grupos `web` y `api`): un colaborador solo alcanza `colaboradores/*`,
  `api/v1/colaboradores/*`, Livewire, recursos estáticos y salir. Todo lo demás ⇒ 403 (JSON) o redirección al panel.
  Si añades rutas para colaboradores, ponlas bajo `colaboradores/...` o `api/v1/colaboradores/...`.
- **Para añadir pantallas sin tocar el proveedor**: el panel descubre recursos en
  `app/Filament/Colaboradores/Resources` (namespace `App\Filament\Colaboradores\Resources`) y páginas en
  `app/Filament/Colaboradores/Pages`. Toda consulta de esas pantallas debe pasar por `CollaboratorAccess::scope()`
  (en `getEloquentQuery()`), y la búsqueda global usa esa misma consulta.
- **Autorización en los recursos del panel**: Filament comprueba primero la política Shield del modelo y el rol
  «colaborador» no tiene permisos ⇒ sin más, todo da 403. Sobrescribe en tu recurso
  `getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null)` y decide SOLO con `CollaboratorAccess`
  (ver `App\Filament\Colaboradores\Resources\MyPropertyResource`): `viewAny` = `collaboratorFor($user) !== null`,
  `view` = `canSee($user, $record)`, `create`/acciones propias = lo que tu módulo permita, resto `denyAsNotFound()`.
- `StaffOnlyPaths` ya deja pasar `colaboradores` y `colaboradores/*` (el panel tiene su propio login).
- Invitación: `App\Collaborators\Collaborations::invite(Collaborator)` crea/enlaza el usuario y envía el enlace de
  activación (AccessLink, al panel de colaboradores).

## Módulo CLARTO

Clave `colaboradores` (manifiesto `app/Modules/manifests/clarto-colaboradores`). Apagado ⇒ no se dan de alta
colaboradores, no se comparten novedades ni se envían avisos, y el panel de colaboradores se cierra; el historial
sigue visible para el equipo. «Solicitudes» puede declarar su propia clave o comprobar `ClartoModules::enabled('colaboradores')`.

## Archivos compartidos (bloques propios)

- `ClartoServiceProvider::register()`: bloque «Colaboradores» ⇒ `$this->app->register(ClartoCollaboratorsServiceProvider::class)`.
- `PropertyResource`: una línea `\App\Filament\Collaborators\PropertyCollaborationTab::tab()`.
- `AdminPanelProvider`: `CollaboratorResource` añadido desde `ClartoCollaboratorsServiceProvider` (no se toca el módulo).
- `lang/{es,en,tr}/colaboradores.php` (propio). Solicitudes: usa tu propio archivo de idioma.
- `RequireStaffLogin`: `_auth/check` responde 401 a colaboradores (no ven `/storage`).
## Pruebas

`php artisan clarto:colaboradores-prueba` (46 comprobaciones, datos ficticios, WAHA y correo simulados, base de datos
revertida): invitación y activación, el rol solo no da acceso, cada colaborador ve solo lo suyo (listado, búsqueda
global, ficha, API, adjuntos, exportación), enlace directo ajeno 404, /admin y /app 403, API general 403, /storage
cerrado, aviso por correo y WhatsApp una sola vez (reintento sin duplicar), nota interna nunca visible, visita con
fecha y estado, oferta revisada antes del envío, retirada que revoca y cancela avisos pendientes, baja, auditoría y ninguna llamada a GoHighLevel en los flujos de colaboradores.

## Importación de inmuebles

La ficha del colaborador tiene la sección «Importación de inmuebles» (feed o web, inactiva sin autorización por
escrito). Ver [importacion-colaboradores.md](importacion-colaboradores.md).
