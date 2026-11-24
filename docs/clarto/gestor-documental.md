# Documentación de la empresa (gestor documental interno)

Archivo de la documentación oficial **propia** de CLARTO en **carpetas y subcarpetas** (contratos con proveedores,
fiscal, escrituras, poderes, contratos de colaboración, identidad de socios…), solo para los **responsables** de la
empresa, con **aprobación por votos** de los documentos que lo necesiten. Menú **Organización → Documentación de la
empresa**, solo en el panel `/admin` (rutas `…/documentacion-empresa` y `…/documentacion-carpetas`). No toca `modules/`
ni `themes/`.

## Cómo está hecho

| Pieza | Dónde |
|---|---|
| Tablas `clarto_company_document_folders` (árbol por empresa con `parent_id`), `clarto_company_documents` (carpeta, título, persona o entidad, fecha, caducidad, notas, archivos JSON, estado, aprobaciones necesarias, gestor…), `clarto_company_document_votes` (conforme / no conforme + comentario, uno por persona) y `clarto_company_document_logs` (ver/descargar) | `database/migrations/2026_12_10_000001_*` |
| Reglas: acceso, alta/edición con comprobación de archivos, caducidad, pedir aprobación, votar, umbral, cerrar como aprobado/firmado | `App\Documents\CompanyDocument` |
| Carpetas: iniciales, rutas, subcarpetas, sin ciclos, sin borrar con contenido | `App\Documents\CompanyDocumentFolder` |
| Avisos (campana + correo) en el idioma de cada destinatario | `App\Documents\CompanyDocumentNotice` |
| Votos y registro de accesos | `App\Documents\CompanyDocumentVote`, `App\Documents\CompanyDocumentLog` |
| Política (documentos y carpetas): solo responsables | `App\Policies\CompanyDocumentPolicy` |
| Lista (búsqueda; filtros carpeta con subcarpetas, estado, caducidad; marcas de caducidad; estado con recuento) + «Nuevo documento» + «Carpetas» | `App\Filament\Resources\CompanyDocumentResource` + `Pages\ManageCompanyDocuments` |
| Ficha (datos, archivos Ver/Descargar, aprobación con quién respondió qué, registro de accesos) + «Pedir aprobación», «Responder», «Cerrar aprobación», Editar, Borrar | `Pages\ViewCompanyDocument` |
| Carpetas (crear, renombrar, mover; borrar solo vacías) | `App\Filament\Resources\CompanyDocumentFolderResource` (fuera del menú; botón «Carpetas») |
| Archivos | ruta `clarto.documentacion.archivo` (`routes/clarto-documentacion.php`, `CompanyDocumentFileController`) |
| Registro sin tocar módulos, aviso diario de caducidad, comando de prueba | `App\Providers\ClartoCompanyDocumentsServiceProvider` (desde `ClartoServiceProvider::register()`) |
| Textos es/en/tr | `lang/{es,en,tr}/documentacion.php` |

## Quién tiene acceso (decisión del propietario)

Solo los **responsables** de la empresa, con los roles que ya existen en el panel (no hay permiso asignable):

- rol Spatie **`admin`** o **`super_admin`** en ese equipo (`model_has_roles.team_id`);
- el **titular** (owner) de la empresa: propietario Jetstream del equipo (`teams.user_id`). En el panel no existe un rol
  «owner»; los roles del equipo son `super_admin`, `admin`, `staff` y los de cliente;
- los superadministradores (`super_admin` en cualquier equipo), coherente con el `Gate::before` de Shield.

Además tiene que ser personal del panel (`hasAdminAccess`), no colaborador y no estar desactivado. Es la regla de
`ImprovementRequest::isTeamAdmin`. Todos los responsables pueden crear, editar, pedir aprobación, cerrarla y borrar. El
rol `staff` y los colaboradores no ven el menú; lista, ficha y carpetas dan 403 y los archivos 404. Otra empresa nunca
ve nada. Fuera de la búsqueda global.

## Carpetas

- Árbol por empresa (`parent_id`). La primera vez que un responsable abre la lista o las carpetas se crean siete carpetas
  que se pueden renombrar: Contratos con proveedores, Documentación fiscal, Documentación de la empresa, Contratos de
  colaboración, Escrituras, Poderes y Documentos de identidad de socios.
- Se crean, renombran y mueven («Dentro de»). Una carpeta no puede ir dentro de sí misma ni de una subcarpeta suya, ni
  dentro de una carpeta de otra empresa. Una carpeta con subcarpetas o documentos **no se borra**: el borrado se
  bloquea en el modelo (`deleting` devuelve `false`) y no hay cascada.
- Cada documento está en una carpeta; se mueve editándolo. El filtro de carpeta incluye sus subcarpetas.

## Aprobación

Estados: ninguno, **Pendiente de aprobación** (`pendiente`), **Aprobado** (`aprobado`) y **Firmado** (`firmado`).
Sirve para cualquier documento, no depende de la carpeta, porque las carpetas se renombran.

1. **Pedir aprobación** (ficha): quien lo pide pasa a ser el **gestor** y fija las **aprobaciones necesarias** (de 1
   al número de los demás responsables). Se borran los votos anteriores. Se avisa a **todos los demás responsables**
   (no al gestor) por campana y por correo.
2. **Responder**: cada responsable que no es el gestor elige **Conforme** o **No conforme**, con un comentario
   opcional. Puede cambiarlo mientras siga pendiente. Solo cuentan los votos de quien sigue siendo responsable.
3. La lista y la ficha muestran el progreso («2 de 3 aprobaciones necesarias (5 responsables)») y quién respondió qué.
   Si los «No conforme» impiden llegar al umbral, no se hace nada especial: se ve en el recuento.
4. Al alcanzar el umbral se avisa **una vez** al gestor, por campana y correo. El documento **no cambia solo**.
5. **Cerrar aprobación** (cualquier responsable): **Aprobado**, o **Firmado** subiendo la copia firmada (obligatoria;
   se añade a los archivos). Los votos se conservan. Un documento firmado no se vuelve a cerrar; para otra ronda se
   vuelve a **pedir aprobación**.

## Avisos (campana y correo)

`CompanyDocumentNotice` va por `database` (campana de Filament, ya activa en `/admin`) y `mail` (el correo saliente
configurado en el panel). Nunca GHL ni WhatsApp. Los textos se generan en el idioma de cada destinatario
(`User::preferredLocale`, es/en/tr; si no tiene, castellano).

| Aviso | A quién |
|---|---|
| Pendiente de aprobación (título, carpeta, quién lo pide, aprobaciones necesarias) | todos los responsables menos el gestor |
| Ya tiene las aprobaciones | el gestor |
| Caduca pronto / caducado (tarea diaria `clarto-documentacion-caducidad`, 08:00, una vez por documento; vuelve a avisar si cambia la fecha) | todos los responsables |

El correo lleva un botón a la ficha del panel, que exige iniciar sesión. Nunca lleva adjuntos ni el contenido del
documento.

## Archivos

- Disco privado `local` (`storage/app/private`). Se suben a `gestor-documental/subidas/{usuario}` y al guardar se
  comprueban en el servidor: solo rutas de la carpeta de subida del propio usuario, tipo por **contenido** (finfo)
  PDF/JPG/PNG/WebP, ≤ 20 MB, ≤ 10 por vez (`UploadPolicy` del módulo Files). Se mueven a
  `gestor-documental/{empresa}/{documento}/{aleatorio}-{nombre}`.
- Solo se sirven por `filament/clarto/documentacion/{documento}/archivos/{índice}` (sesión + `throttle` + política
  `view`). La URL no lleva el nombre ni la ruta en disco. Sin acceso: 404. Cabeceras `nosniff`, `noindex`,
  `private, no-store`. **Ver** = `inline`; **Descargar** (`?descargar=1`) = `attachment`.
- La ruta propia del disco (`/storage/{ruta}`, `serve => true`) exige URL firmada: sin firma falla. El formulario no
  muestra los archivos existentes en el `FileUpload` (son casillas «Quitar…»), así que Filament no genera URLs
  temporales que se saltarían el registro.
- Sin versiones: sustituir es subir el nuevo y quitar el antiguo, que se borra del disco. Al borrar el documento se
  borran sus archivos y sus votos; el registro de accesos se conserva.

## Registro de accesos (RGPD)

Cada **Ver** y cada **Descargar** crea una fila con usuario, acción, nombre del archivo, IP y fecha. La ficha muestra
las 200 últimas. Las peticiones denegadas no se registran.

## Aislamiento

Ningún otro código usa estos modelos: no llegan al asistente IA (solo indexa Procesos), Procesos, GHL, WhatsApp, la web
ni la API. La batería lo comprueba: los recursos solo están en el panel `admin`, ninguna ruta `api/*` los sirve, el
índice del asistente no los contiene, ningún archivo de `app/` fuera de los del gestor menciona `CompanyDocument` ni sus
tablas, y los avisos solo usan campana y correo.

## Copias de seguridad

`deploy/clarto-staging/scripts/backup.sh` (bundle PWB) copia el volumen `liberu_storage`, montado en `storage/app`.
Incluye `storage/app/private/gestor-documental`, y la base de datos (`VACUUM INTO`) incluye carpetas, votos y registro.
Ese script no está programado en el VPS: la política acordada es la copia diaria automática de OVH, que también cubre el
volumen. La copia contiene documentos de identidad sin cifrar aparte, así que hay que guardarla con el mismo cuidado.

## Prueba

```bash
php artisan clarto:gestor-documental-prueba
```

65 comprobaciones con empresas, usuarios y archivos ficticios, en una transacción que se deshace, con el disco privado
simulado y los avisos simulados (`Notification::fake`: no se envía nada).

- **Acceso**: administración, superadministración y titular sí; `staff`, otra empresa y sin sesión no; no existe permiso
  asignable.
- **Carpetas**: las 7 iniciales una sola vez; subcarpeta y renombrado con ruta; sin ciclos ni carpetas de otra empresa;
  mover un documento; filtro con subcarpetas; carpeta con contenido no se borra y la vacía sí.
- **Archivos al subir**: PDF + PNG por contenido en el disco privado; rechazados el binario con extensión `.pdf`, el
  archivo ajeno, la carpeta ajena y el alta sin archivo.
- **Caducidad**: marcas; aviso por campana y correo solo a responsables, sin repetir; tarea programada.
- **Sustituir archivo.**
- **Aprobación**:
  - el umbral fuera de rango se rechaza;
  - el aviso «pendiente» llega por los dos canales a todos los responsables menos al gestor, y no al `staff` ni a otra
    empresa;
  - el correo lleva título, carpeta, quién lo pide, aprobaciones y enlace, sin adjuntos;
  - no votan ni el gestor ni el `staff`;
  - recuento «1 de 2 … (3 responsables)», y «No conforme» no suma;
  - al llegar al umbral se avisa una vez al gestor y el documento no avanza solo;
  - cambiar el voto no repite el aviso;
  - «Firmado» exige la copia firmada; con umbral 1 se puede cerrar como «Aprobado».
- **Ruta de archivos**: responsables 200 y registro; `staff` 404 sin registro; otra empresa 404; sin sesión ≠ 200;
  índice inexistente 404; la **descarga directa por la ruta del disco falla**; la ruta exige `auth`.
- **Páginas**: lista con rutas de carpeta y recuento; ficha con quién respondió qué; carpetas; 403 para el `staff` y sin
  menú.
- **Aislamiento**: ver la sección anterior.
- **Borrar**: se van archivos y votos; el registro se conserva.

## Fuera de alcance

Versiones, cifrado adicional de los archivos en disco, aviso por WhatsApp, recordatorios a quien no ha votado y
caducidad de los documentos de clientes (eso es el módulo de documentos de las fichas).
