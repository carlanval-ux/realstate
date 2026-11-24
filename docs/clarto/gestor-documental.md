# Documentación de la empresa (gestor documental interno)

Archivo de la documentación oficial **propia** de CLARTO (escrituras, contratos de colaboración, poderes, documentos de
identidad de los socios y otra documentación oficial), solo para el **equipo directivo**. Menú **Organización →
Documentación de la empresa**, solo en el panel `/admin` (ruta `…/documentacion-empresa`). No toca `modules/` ni
`themes/`.

## Cómo está hecho

| Pieza | Dónde |
|---|---|
| Tablas `clarto_company_documents` (empresa, título, categoría, persona o entidad, fecha, caducidad, notas, archivos JSON, aviso enviado) y `clarto_company_document_logs` (documento, usuario, `ver`/`descargar`, archivo, IP, fecha) + permiso `documentacion.ver` | `database/migrations/2026_12_10_000001_*` |
| Reglas: acceso, alta/edición con comprobación de archivos, sustituir/quitar archivos, caducidad, avisos, conceder acceso | `App\Documents\CompanyDocument` (`canAccess`, `saveFor`, `notifyExpiring`, `grantDirectAccess`) |
| Registro de accesos | `App\Documents\CompanyDocumentLog` (solo se crea, nunca se edita; se conserva aunque se borre el documento) |
| Política: ver/crear/editar = equipo directivo; borrar = solo administración | `App\Policies\CompanyDocumentPolicy` |
| Lista (búsqueda en título, persona y notas; filtro por categoría y por caducidad; marca roja «Caducado» y naranja «caduca en N días») + «Nuevo documento» + «Gestionar acceso» | `App\Filament\Resources\CompanyDocumentResource` + `Pages\ManageCompanyDocuments` |
| Ficha (datos, archivos con Ver/Descargar, registro de accesos) + Editar/Borrar | `Pages\ViewCompanyDocument` |
| Archivos | ruta `clarto.documentacion.archivo` (`routes/clarto-documentacion.php`, `CompanyDocumentFileController`) |
| Registro sin tocar módulos, aviso diario, comando de prueba | `App\Providers\ClartoCompanyDocumentsServiceProvider` (registrado desde `ClartoServiceProvider::register()`) |
| Textos es/en/tr | `lang/{es,en,tr}/documentacion.php` |

## Quién tiene acceso

- **Por defecto**: superadministradores y **administradores de la empresa** (rol `admin`/`super_admin` de Spatie en ese
  equipo, o titular Jetstream del equipo; la misma regla que `ImprovementRequest::isTeamAdmin`). Nadie más.
- **Permiso `documentacion.ver`** (guard `web`, creado por la migración sin asignarlo a ningún rol): da acceso en el
  equipo donde se tenga, directamente o por un rol de ese equipo. Se lee en las tablas pivote filtrando por `team_id`
  (no con `hasPermissionTo`, que depende del equipo activo y no lo hay en la ruta de descarga ni en el planificador).
- **Cómo lo concede el propietario**: botón **Gestionar acceso** de la lista (solo administración): casillas con el
  personal de la empresa; guarda el permiso directo en `model_has_permissions` para ese equipo. Se añadió porque la
  pantalla de Roles de Shield tiene desactivada la pestaña de permisos personalizados (`custom_permissions => false`).
  También vale asignarlo a un rol por base de datos.
- Las cuentas de colaborador y el resto del personal no ven el menú (`canViewAny` falso), la lista y la ficha dan 403
  y los archivos 404. Otra empresa nunca ve nada.
- Fuera de la búsqueda global (`$isGloballySearchable = false`).

## Archivos

- Disco privado `local` (`storage/app/private`). Se suben a `gestor-documental/subidas/{usuario}` y al guardar se
  comprueban **en el servidor**: solo rutas de la carpeta de subida del propio usuario, tipo por **contenido** (finfo)
  PDF/JPG/PNG/WebP, ≤ 20 MB, ≤ 10 por vez (`UploadPolicy` del módulo Files). Se mueven a
  `gestor-documental/{empresa}/{documento}/{aleatorio}-{nombre}`.
- Solo se sirven por `filament/clarto/documentacion/{documento}/archivos/{índice}` (sesión + `throttle` + política
  `view`). La URL no lleva el nombre del archivo ni la ruta en disco. Sin acceso: 404 (no revela que existe). Cabeceras
  `nosniff`, `noindex`, `private, no-store`. **Ver** = `inline`; **Descargar** (`?descargar=1`) = `attachment`.
- La ruta propia del disco (`/storage/{ruta}`, `serve => true`) exige URL firmada: sin firma falla. El formulario de
  edición no muestra los archivos existentes en el `FileUpload` (son casillas «Quitar…»), así que Filament no genera
  URLs temporales que se saltarían el registro.
- **Sin versiones**: sustituir es subir el nuevo y marcar el antiguo para quitarlo (se borra del disco). Al borrar el
  documento se borran sus archivos; el registro de accesos se conserva.

## Registro de accesos (RGPD)

Cada **Ver** y cada **Descargar** crea una fila con usuario, acción, nombre del archivo, IP y fecha. La ficha muestra
las 200 últimas («Registro de accesos»). Las peticiones denegadas no se registran.

## Caducidad y avisos

- Marca en la lista y en la ficha: **Caducado** (rojo) si la fecha ya pasó; **caduca en N días** (naranja) si faltan
  60 días o menos. Filtro «Caducados o caducan en menos de 60 días».
- Aviso en la **campana** (notificación de base de datos de Filament, ya activada en `/admin` por «Incidencias y
  mejoras»): tarea diaria `clarto-documentacion-caducidad` a las 08:00 (`liberu-scheduler`), **una vez por documento**
  al entrar en la ventana de 60 días (o si ya está caducado), a cada miembro de la empresa con acceso. Si cambia la
  fecha de caducidad, vuelve a avisar. Sin correo ni WhatsApp.

## Aislamiento

Ningún otro código usa estos modelos: no llegan al asistente IA (solo indexa Procesos), Procesos, GHL, WhatsApp, la web
ni la API. La batería lo comprueba: el recurso solo está en el panel `admin`, ninguna ruta `api/*` los sirve, el índice
del asistente no los contiene y ningún archivo de `app/` fuera de los del gestor menciona `CompanyDocument` ni sus tablas.

## Copias de seguridad

`deploy/clarto-staging/scripts/backup.sh` (bundle PWB) copia el volumen `liberu_storage`, montado en `storage/app`:
incluye `storage/app/private/gestor-documental`, y la base de datos (`VACUUM INTO`) incluye las tablas y el registro.
Ojo: ese script no está programado en el VPS (la política acordada es la copia diaria automática de OVH, que también
cubre el volumen). La copia contiene documentos de identidad sin cifrar aparte: hay que guardarla con el mismo cuidado.

## Prueba

```bash
php artisan clarto:gestor-documental-prueba
```

43 comprobaciones con empresas, usuarios y archivos ficticios, en una transacción que se deshace y con el disco privado
simulado: permiso creado; acceso por defecto (administración sí; personal, otra empresa y sin sesión no); personal sin
permiso no crea; alta con PDF + PNG detectados por contenido, en el disco privado y nada en el público; binario con
extensión `.pdf`, archivo de otra persona, categoría falsa y sin archivo rechazados; marcas de caducidad; aviso en la
campana solo a quien tiene acceso, sin repetir, y tarea programada; sustituir archivo; ruta de archivos (administración
ver inline, superadministración descargar, registro con quién/qué/cuándo; personal 404 sin registro, otra empresa 404,
sin sesión ≠ 200, índice inexistente 404, **descarga directa por la ruta del disco sin controlador falla**, la ruta
exige `auth`); «Gestionar acceso» (personal no puede, administración concede solo a su empresa, con permiso ve y queda
registrado, no borra, retirar → 404); páginas (lista y ficha para administración, 403 para el personal, sin menú);
aislamiento (solo `/admin`, sin búsqueda global, sin API, fuera del índice del asistente, sin otras referencias en
`app/`); borrar (archivos fuera, registro conservado).

## Fuera de alcance

Versiones, flujos de aprobación, cifrado adicional de los archivos en disco, aviso por correo, caducidad de los
documentos de clientes (eso es el módulo de documentos de las fichas).
