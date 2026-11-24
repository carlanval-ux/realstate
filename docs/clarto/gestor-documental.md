# Documentación de la empresa (gestor documental interno)

Archivo de la documentación oficial **propia** de CLARTO en **carpetas y subcarpetas** (contratos con proveedores,
fiscal, escrituras, poderes, contratos de colaboración, identidad de socios…), solo para los **responsables** de la
empresa, con **aprobación por votos** de los que lo necesiten. Cada ficha es un **expediente**: una **explicación**
opcional, varios archivos (PDF, imagen, Word, Excel u OpenDocument) con descripción y un **hilo de anotaciones**. Menú **Organización → Documentación de la empresa**, solo en el panel `/admin`
(rutas `…/documentacion-empresa` y `…/documentacion-carpetas`). No toca `modules/` ni `themes/`.

En la interfaz se llama **expediente** (en: *case file*; tr: *dosya*, y sus archivos *belge*). En el código, las clases
y tablas siguen llamándose `CompanyDocument*`.

## Cómo está hecho

| Pieza | Dónde |
|---|---|
| Tablas `clarto_company_document_folders` (árbol por empresa con `parent_id`), `clarto_company_documents` (carpeta, título, persona o entidad, fecha, caducidad, archivos JSON con descripción, estado, regla y aprobaciones necesarias, gestor…), `clarto_company_document_votes` (conforme / no conforme + comentario, uno por persona) y `clarto_company_document_logs` (ver/descargar) | `database/migrations/2026_12_10_000001_*` |
| Tabla `clarto_company_document_annotations` (expediente, autor, texto, adjunto JSON, fecha; sin `updated_at`). Convierte las antiguas notas en la primera anotación y elimina la columna `notes` | `database/migrations/2026_12_11_000001_*` |
| Estado nulo → `disponible` (solo datos; la vuelta atrás lo devuelve a nulo) | `database/migrations/2026_12_12_000001_*` |
| Columna `explanation` (texto, opcional) en `clarto_company_documents` | `database/migrations/2026_12_13_000001_*` |
| Columna `approval_rule` (`numero` por defecto / `mayoria`) en `clarto_company_documents` | `database/migrations/2026_12_14_000001_*` |
| Reglas: acceso, alta/edición con comprobación de archivos y descripciones, límite de 20, caducidad, anotar, «Disponible» por defecto, aprobación decidida en el alta, pedir aprobación, votar, umbral, cerrar como aprobado/firmado | `App\Documents\CompanyDocument` |
| Anotaciones: borrar solo su autor y en 15 minutos; al borrarla se borra el adjunto | `App\Documents\CompanyDocumentAnnotation` |
| Carpetas: iniciales, rutas, subcarpetas, sin ciclos, sin borrar con contenido | `App\Documents\CompanyDocumentFolder` |
| Avisos (campana + correo) en el idioma de cada destinatario | `App\Documents\CompanyDocumentNotice` |
| Votos y registro de accesos | `App\Documents\CompanyDocumentVote`, `App\Documents\CompanyDocumentLog` |
| Política (documentos y carpetas): solo responsables | `App\Policies\CompanyDocumentPolicy` |
| Lista (búsqueda en título, persona, explicación y anotaciones; filtros carpeta con subcarpetas, estado, caducidad; marcas de caducidad; estado con recuento) + «Nuevo expediente» + «Carpetas». Formulario con una subida múltiple (`filesField`) y, al editar, una fila por archivo existente (`fileItems` / `fromForm`) y, solo en el alta, «¿Requiere aprobación?» + «Regla» + «Aprobaciones necesarias» (`approvalFields`, también en «Pedir aprobación») | `App\Filament\Resources\CompanyDocumentResource` + `Pages\ManageCompanyDocuments` |
| Ficha (explicación arriba, datos, archivos por descripción con Ver/Descargar — Word, Excel y OpenDocument solo Descargar —, anotaciones con su botón «Borrar», aprobación con quién respondió qué, registro de accesos) + «Pedir aprobación», «Responder», «Cerrar aprobación», «Añadir anotación», Editar, Borrar | `Pages\ViewCompanyDocument` |
| Carpetas (crear, renombrar, mover; borrar solo vacías) | `App\Filament\Resources\CompanyDocumentFolderResource` (fuera del menú; botón «Carpetas») |
| Archivos y adjuntos de anotaciones | rutas `clarto.documentacion.archivo` y `clarto.documentacion.anotacion` (`routes/clarto-documentacion.php`, `CompanyDocumentFileController`) |
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

Estados: **Disponible** (`disponible`), **Pendiente de aprobación** (`pendiente`), **Aprobado** (`aprobado`) y
**Firmado** (`firmado`). Sirve para cualquier documento, no depende de la carpeta, porque las carpetas se renombran (no
hay campo «tipo de documento»: lo indica la carpeta).

**Disponible** es el estado por defecto (`$attributes` del modelo): el expediente queda a disposición de los
responsables, sin aprobación y sin avisos. La migración `2026_12_12_000001_*` pasa a `disponible` los expedientes con
estado nulo (los de antes de este cambio); el resto conserva el suyo, y la columna no cambia.

Lista y filtro de estado distinguen los cuatro, con etiquetas de colores distintos: Disponible gris, Pendiente naranja,
Aprobado azul y Firmado verde. La ficha de un «Disponible» muestra su estado y explica que se puede pedir la aprobación;
el progreso, el gestor y las respuestas solo aparecen cuando ha habido aprobación.

0. **En el alta** («Nuevo expediente»), **¿Requiere aprobación?** (No por defecto). Con «Sí» aparece **Aprobaciones
   necesarias** con su **Regla** (ver «Regla de aprobación» abajo; con «Número de aprobaciones», de 1 al número de los
   demás responsables, por defecto todos). Al guardar se llama a
   `requestApproval` con quien lo sube como gestor: queda **Pendiente de aprobación** y salen los avisos de campana y
   correo, como en el paso 1. El número se comprueba antes de guardar nada (`saveFor`), así que un número fuera de
   rango no deja un expediente a medias ni consume los archivos subidos. Si quien lo sube es el **único responsable**
   (nadie más puede votar), la opción aparece desactivada con un texto que lo explica, y el servidor también lo
   rechaza. La pregunta solo está en el alta: al editar no aparece y, si llegara, se ignora.
1. **Pedir aprobación** (ficha; para un «Disponible», o para otra ronda tras cerrar la anterior): quien lo pide pasa a
   ser el **gestor** y fija las **aprobaciones necesarias** con la **Regla**: un número (de 1 al número de los demás
   responsables) o la **mayoría absoluta** de los que votan. Se borran los
   votos anteriores. Se avisa a **todos los demás responsables** (no al gestor) por campana y por correo.
2. **Responder**: cada responsable que no es el gestor elige **Conforme** o **No conforme**, con un comentario
   opcional. Puede cambiarlo mientras siga pendiente. Solo cuentan los votos de quien sigue siendo responsable.
3. La lista y la ficha muestran el progreso («2 de 3 aprobaciones necesarias (5 responsables)», o con mayoría
   «2 de 3 aprobaciones (mayoría absoluta de 5)») y quién respondió qué.
   Si los «No conforme» impiden llegar al umbral, no se hace nada especial: se ve en el recuento.
4. Al alcanzar el umbral se avisa **una vez** al gestor, por campana y correo. El documento **no cambia solo**.
5. **Cerrar aprobación** (cualquier responsable): **Aprobado**, o **Firmado** subiendo la copia firmada (obligatoria;
   se añade a los archivos). Los votos se conservan. Un documento firmado no se vuelve a cerrar; para otra ronda se
   vuelve a **pedir aprobación**. Un «Disponible» no se cierra: primero hay que pedir la aprobación.

### Regla de aprobación

Columna `approval_rule` (migración `2026_12_14_000001_*`, valor por defecto `numero`, así que los expedientes de antes
quedan con número fijo):

- **Número de aprobaciones** (`numero`): el gestor elige cuántas, de 1 al número de los que votan (los demás
  responsables; el gestor no vota).
- **Mayoría absoluta** (`mayoria`): más de la mitad de los que votan, `floor(votantes / 2) + 1` (`CompanyDocument::majority`):
  5 → 3, 4 → 3, 3 → 2, 1 → 1. El formulario lo muestra junto a la opción («Mayoría absoluta: 3 de 5»). El número se
  calcula **al pedir la aprobación** y se guarda en `approvals_required`, así que el recuento, el aviso al gestor y el
  cierre funcionan igual que con un número fijo. Si después cambia el número de responsables, la cifra no se recalcula
  (el «de N» del progreso sí muestra los que votan ahora); para recalcularla, se vuelve a pedir la aprobación.

Una regla desconocida se rechaza en el servidor. Sin regla en los datos (formularios o llamadas antiguas) es `numero`.
Los avisos no cambian: dicen las aprobaciones necesarias y cuántos votan.

## Avisos (campana y correo)

`CompanyDocumentNotice` va por `database` (campana de Filament, ya activa en `/admin`) y `mail` (el correo saliente
configurado en el panel). Nunca GHL ni WhatsApp. Los textos se generan en el idioma de cada destinatario
(`User::preferredLocale`, es/en/tr; si no tiene, castellano).

| Aviso | A quién |
|---|---|
| Pendiente de aprobación (título, carpeta, quién lo pide, aprobaciones necesarias), al pedirla en la ficha o en el alta | todos los responsables menos el gestor |
| Expediente «Disponible» | nadie (no hay aviso) |
| Ya tiene las aprobaciones | el gestor |
| Caduca pronto / caducado (tarea diaria `clarto-documentacion-caducidad`, 08:00, una vez por documento; vuelve a avisar si cambia la fecha) | todos los responsables |

El correo lleva un botón a la ficha del panel, que exige iniciar sesión. Nunca lleva adjuntos ni el contenido del
documento.

## Archivos

- **Hasta 20 por expediente** (antes 10 por vez): se cuentan los que ya tiene más los nuevos, también al subir la
  copia firmada. Los adjuntos de las anotaciones no cuentan.
- **Subida múltiple**: en el alta, un solo campo «Archivos» (`filesField()`: `FileUpload` `multiple()`, el mismo de
  la copia firmada) donde se eligen o arrastran varios a la vez. Cada uno se guarda con su **nombre original**
  (`storeFileNamesIn('new_file_names')`) y la ficha lo muestra así. No se pide descripción al subir.
- **Al editar**: arriba, la lista de los archivos que ya tiene, una fila por archivo con su nombre original (repeater
  `items` solo en edición: índice + descripción; sin «añadir»). Borrar una fila quita el archivo. Debajo, el mismo
  campo múltiple, «Añadir archivos», no obligatorio.
- La **descripción** corta y opcional (≤ 200 caracteres; p. ej. «Anexo I») solo se escribe en las filas de los
  archivos existentes, al editar. Se guarda en el JSON `files` (`description`) y la ficha la muestra en lugar del
  nombre cuando la hay. Las descripciones que ya existían se conservan y se ven igual.
- Disco privado `local` (`storage/app/private`). Se suben a `gestor-documental/subidas/{usuario}` y al guardar se
  comprueban en el servidor: solo rutas de la carpeta de subida del propio usuario, tipo por **contenido**, ≤ 20 MB
  (`UploadPolicy` del módulo Files). Se mueven a `gestor-documental/{empresa}/{expediente}/{aleatorio}-{nombre}`.
- **Tipos admitidos** (`CompanyDocument::MIME_TYPES`): PDF, JPG, PNG, WebP, Word (`.doc`, `.docx`), Excel (`.xls`,
  `.xlsx`) y OpenDocument (`.odt`, `.ods`). El tipo lo da libmagic (`finfo->file()` sobre el archivo entero en el disco
  local), nunca la extensión. Los formatos que son un zip (`.docx`, `.xlsx`, `.odt`, `.ods`) se abren siempre con
  `ZipArchive`, también cuando libmagic dice `application/zip` u `octet-stream` (pasa según el orden de las entradas):
  Word = `[Content_Types].xml` + `word/document.xml`; Excel = `[Content_Types].xml` + `xl/workbook.xml`; OpenDocument
  = su entrada `mimetype`. Se rechazan los que traen **macros** (`word/vbaProject.bin`, `xl/vbaProject.bin`,
  `Basic/script-lc.xml`), cualquier otro zip y un texto con extensión `.docx`. Solo se lee el índice del zip y, como
  mucho, 100 bytes de `mimetype`: no se descomprime, ejecuta ni convierte nada. `.doc` y `.xls` (formato binario
  antiguo) se aceptan solo si libmagic los reconoce como Word / Excel; no se mira si llevan macros.
- En el navegador, el campo de subida admite esos tipos **y** sus extensiones (`.docx`…), con un mapa extensión →
  tipo para FilePond (`mimeTypeMap`), por si el sistema no da tipo a un `.docx`. La misma lista es la regla
  `mimetypes` que Filament aplica al guardar, y lleva también `application/zip`, porque libmagic puede ver un `.docx`
  como zip; quien decide es la comprobación anterior.
- Solo se sirven por `filament/clarto/documentacion/{expediente}/archivos/{índice}` (sesión + `throttle` + política
  `view`). La URL no lleva el nombre ni la ruta en disco. Sin acceso: 404. Cabeceras `nosniff`, `noindex`,
  `private, no-store`. **Ver** = `inline`; **Descargar** (`?descargar=1`) = `attachment`. **Word, Excel y
  OpenDocument se sirven siempre como `attachment`**, aunque se pida sin `?descargar`, y la ficha solo les pone
  «Descargar»; en el registro cuentan como «ha descargado».
- La ruta propia del disco (`/storage/{ruta}`, `serve => true`) exige URL firmada: sin firma falla. El formulario no
  pone los archivos existentes en un `FileUpload` (sus filas solo tienen la descripción), así que Filament no genera
  URLs temporales que se saltarían el registro.
- Sin versiones: sustituir es añadir el nuevo y borrar la fila del antiguo, que se borra del disco. Al borrar el
  expediente se borran sus archivos, sus votos y sus anotaciones con sus adjuntos; el registro de accesos se conserva.

## Explicación

- Campo **Explicación** (texto largo, opcional, ≤ 10 000 caracteres, `CompanyDocument::MAX_EXPLANATION`) en los
  formularios de alta y de edición: qué es el expediente y por qué se sube. Columna `explanation`.
- Se guarda sin los espacios del principio y del final. Más de 10 000 caracteres se rechaza también en el servidor.
- En la ficha va **la primera**, con sus saltos de línea (texto escapado, `white-space: pre-line`). Si está vacía, no
  aparece.
- El buscador de la lista también busca en ella.
- No sustituye a las anotaciones: la explicación se reescribe al editar; las anotaciones siguen siendo el hilo con
  fecha y autor. Las descripciones cortas de cada archivo no cambian.

## Anotaciones

- Hilo en la ficha, del más antiguo al más reciente. Cada anotación tiene fecha, autor, texto (≤ 5000 caracteres) y,
  si se quiere, **un** adjunto.
- Puede anotar **cualquier responsable**, también en expedientes aprobados o firmados. No se avisa a nadie por cada
  anotación.
- **No se editan.** Solo su autor puede borrarla, y solo en los **15 minutos** siguientes
  (`CompanyDocumentAnnotation::DELETE_MINUTES`). El botón **Borrar** solo aparece en ese caso. Al borrarla se borra
  también su adjunto del disco.
- El adjunto pasa por la misma comprobación que los archivos del expediente (tipo por contenido, también Word, Excel
  y OpenDocument, 20 MB, subida propia); los Word, Excel y OpenDocument también se sirven solo como descarga. Se guarda en el mismo disco privado, en `gestor-documental/{empresa}/{expediente}/anotaciones/`. Se sirve
  por el mismo controlador y la misma política en `filament/clarto/documentacion/{expediente}/anotaciones/{anotación}`,
  con 404 sin acceso, y cada acceso queda en el mismo registro.
- La búsqueda de la lista también busca en el texto de las anotaciones.

### Las notas de antes

La migración `2026_12_11_000001` convierte el campo `notes` de cada expediente existente en su **primera anotación**.
Autor: quien creó el expediente (`created_by`); fecha: su `created_at`. Las notas vacías o con solo espacios no crean
anotación. Después **elimina la columna `notes`** en la misma migración. En SQLite, la base de datos del panel, la
copia y el borrado van dentro de la transacción del migrador: si algo falla, las notas siguen donde estaban. La vuelta
atrás (`down`) recrea `notes` con el texto de todas las anotaciones, de la más antigua a la más reciente, y borra la
tabla. Los adjuntos de las anotaciones se quedan en el disco sin referencia.

El 24/11/2026 la base de datos real de staging tenía **0 expedientes**, así que allí la migración no convierte nada. La
batería la prueba con notas ficticias.

## Registro de accesos (RGPD)

Cada **Ver** y cada **Descargar**, sea de un archivo del expediente o del adjunto de una anotación, crea una fila con
usuario, acción, nombre del archivo, IP y fecha. La ficha muestra las 200 últimas. Las peticiones denegadas no se
registran.

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

152 comprobaciones con empresas, usuarios y archivos ficticios, en una transacción que se deshace, con el disco privado
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
- **Regla «Mayoría absoluta»** (empresa ficticia con titular y 5 administradores):
  - `majority` da 1, 2, 2, 3, 3, 4 para 1…6 votantes;
  - alta con mayoría y 5 que votan: regla guardada y 3 necesarias aunque llegue otro número; aviso «pendiente» a los 5;
  - con 2 «Conforme» y 1 «No conforme» no hay aviso; al llegar a 3, aviso único al gestor (campana y correo) y el
    expediente sigue pendiente; se cierra a mano;
  - lista y ficha muestran «… aprobaciones (mayoría absoluta de 5)»;
  - con 4 que votan (un administrador desactivado), «Pedir aprobación» real (Livewire): selector «Regla», número por
    defecto limitado a 1-4, «Mayoría absoluta: 3 de 4»; con mayoría desaparece el número y queda pendiente con 3;
  - el formulario de alta muestra la regla con «Sí» y oculta el número con la mayoría;
  - número fijo: 0 y 5 de 4 se rechazan, 4 se acepta; regla desconocida rechazada; sin regla, `numero`;
  - migración `down` + `up` (solo SQLite): los expedientes existentes quedan con `numero`.
- **Ruta de archivos**: responsables 200 y registro; `staff` 404 sin registro; otra empresa 404; sin sesión ≠ 200;
  índice inexistente 404; la **descarga directa por la ruta del disco falla**; la ruta exige `auth`.
- **Páginas**: lista con rutas de carpeta y recuento; ficha con quién respondió qué; carpetas; 403 para el `staff` y sin
  menú.
- **Aislamiento**: ver la sección anterior.
- **Borrar**: se van archivos y votos; el registro se conserva.
- **Expedientes**:
  - alta con 3 archivos de golpe (subida múltiple) con sus nombres originales y sin descripción, también con el
    formulario real (Livewire, archivos subidos de verdad); el alta solo tiene ese campo, obligatorio, y la edición la
    lista de existentes más «Añadir archivos»;
  - al editar, quitar un archivo borrando su fila (también en el formulario real) y describir los que quedan;
  - la ficha muestra la descripción y no el nombre; sin descripción se ve el nombre;
  - 20 archivos sí; el 21 no, ni al editar, ni en el alta, ni como copia firmada; al editar cuentan los que ya tiene
    más los nuevos (18 + 3 no, 18 + 2 sí).
- **Anotaciones**:
  - hilo cronológico con autor, texto y adjunto; anota cualquier responsable (también el titular);
  - adjunto PDF por contenido en el disco privado; rechazados el binario disfrazado, la subida ajena, el texto vacío,
    el `staff` y otra empresa.
- **Adjunto**:
  - responsables 200 (`inline` y `attachment`), con registro;
  - `staff` y otra empresa 404 y sin sesión ≠ 200, sin registro;
  - 404 por la URL de otro expediente o de una anotación sin adjunto; la ruta exige `auth`.
- **Borrar anotaciones**: otra persona no; pasados 15 minutos, tampoco su autor; su autor dentro de plazo sí, y el
  adjunto se borra. Al borrar el expediente se van sus anotaciones y adjuntos.
- **Migración de notas**: `down` + `up` de verdad dentro de la transacción (solo en SQLite, donde la DDL se deshace;
  en otro motor se omite con `SKIP`). Las notas pasan a primera anotación con autor y fecha; las vacías no; la columna
  desaparece.
- **Aprobación en el alta**:
  - sin aprobación (o sin la pregunta) queda «Disponible», sin gestor ni umbral y sin ningún aviso;
  - con aprobación queda «Pendiente», gestiona quien lo sube y se avisa por campana y correo a los otros 3
    responsables (ni a quien lo sube ni al `staff`);
  - 0, más que los demás responsables o sin número se rechaza antes de guardar: ni expediente, ni avisos, y la subida
    sigue para reintentar; al editar la pregunta no cuenta;
  - con un solo responsable no se puede pedir (servidor) y en el formulario la opción sale desactivada con su
    explicación;
  - el formulario de alta (Livewire) tiene la pregunta con No por defecto y su ayuda, «Aprobaciones necesarias» solo
    con «Sí» y rechaza 4 de 3; el de edición no la tiene;
  - ficha «Disponible»: estado, explicación y «Pedir aprobación», sin «Responder» ni «Cerrar aprobación»; no se cierra
    sin pedir antes la aprobación; «Pedir aprobación» desde ahí la deja pendiente y avisa a los demás;
  - cuatro estados con cuatro colores; el filtro de estado separa «Disponible» de «Pendiente»; la lista muestra la
    etiqueta;
  - migración: nulo → `disponible` sin tocar los demás estados, y vuelta atrás (solo datos: vale en cualquier motor).
- **Word, Excel y OpenDocument** (archivos reales generados con `ZipArchive` en la prueba):
  - `.docx`, `.xlsx` y `.odt` aceptados con su tipo, junto al expediente; el `.docx` aunque libmagic lo vea como zip;
  - rechazados y borrados: texto plano con extensión `.docx`, un zip cualquiera, un `.docx` con macros y un `.xlsx`
    sin `[Content_Types].xml`;
  - adjunto Word en una anotación aceptado, uno falso rechazado;
  - Word y Excel servidos como `attachment` (también por «Ver»), con su tipo, `nosniff` y sin caché; el PDF sigue
    `inline`; el registro los cuenta como «descargado»; el `staff` recibe 404;
  - la ficha solo pone «Descargar» al Word y «Ver» + «Descargar» al PDF;
  - el campo de subida lleva tipos, extensiones y el mapa de FilePond.
- **Explicación**: guardada sin espacios sobrantes y con su salto de línea; más de 10 000 caracteres rechazado; en la
  ficha arriba (antes de archivos y anotaciones) con los saltos de línea, y sin apartado si está vacía; el buscador la
  encuentra; formularios de alta y edición (Livewire) con el campo, y editarla conserva los archivos; migración
  `down` + `up` (solo SQLite).

## Fuera de alcance

Versiones, cifrado adicional de los archivos en disco, aviso por WhatsApp, recordatorios a quien no ha votado,
caducidad de los documentos de clientes (eso es el módulo de documentos de las fichas), avisos por cada anotación,
editar anotaciones y buscar por la descripción de los archivos. Tampoco: ver Word o Excel en el navegador
(convertirlos a PDF), buscar dentro del contenido de los archivos, mirar macros en `.doc` / `.xls` antiguos, ni admitir
PowerPoint, `.rtf`, `.txt`, `.csv` o `.docm` / `.xlsm`.
