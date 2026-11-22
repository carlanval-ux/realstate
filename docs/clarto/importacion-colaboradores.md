# Importación de inmuebles de colaboradores (rama `feat/colaboradores-importacion`)

Trae a CLARTO los inmuebles de un colaborador desde **su feed** o **su página web**. Se configura en la ficha del
colaborador (sección «Importación de inmuebles»). Queda **construida pero inactiva**: ninguna importación se ejecuta
mientras no se registre la **fecha de la autorización por escrito** del colaborador. A 22/11/2026 no hay ningún acuerdo
firmado con colaboradores.

## Regla de la autorización (la puerta)

- `App\Collaborators\Import\CollaboratorImports::run()` es el **único** punto de entrada (programada, «Importar ahora»,
  CLI). Sin `authorized_at` devuelve `sin_autorizacion` **antes de hacer ninguna petición HTTP** y lo deja anotado
  en la ficha. También se niega si el módulo «Colaboradores» está apagado, el colaborador está de baja o falta la
  configuración.
- El botón «Importar ahora» está desactivado (con la explicación) mientras no haya fecha, y la acción vuelve a
  comprobarlo en el servidor.
- El documento de la autorización (PDF o imagen, opcional) se guarda en el disco privado `local`
  (`colaboradores/autorizaciones/{equipo}`), nunca en el público.

## Ajustes en la ficha (tabla `clarto_collaborator_imports`, uno por colaborador)

| Campo | Valores |
|---|---|
| Origen | Ninguno · Feed · Su página web |
| Feed | URL + formato: **Kyero XML v3**, **XML genérico** o **JSON genérico** con correspondencia de campos (ruta con puntos por campo: lista de inmuebles, referencia, precio, operación, tipo, m², habitaciones, baños, municipio, zona, título, descripción, fotos) |
| Web | Página de listado **o sitemap** (recomendado) + patrón opcional de las fichas con `*` (`/inmueble/*`) |
| Programación | Diaria (05:30) · Solo manual |
| Autorización | Fecha (obligatoria para importar) + documento opcional |
| Última ejecución | Fecha, estado (correcta / parcial / error / sin autorización) y recuento: leídos, nuevos, actualizados, sin cambios, retirados, fotos, errores, peticiones, leídos con IA |

idealista/OpenNavigator no se incluye como formato propio: su XML se importa con «XML genérico» y la correspondencia.

## Qué hace una importación

1. **Lee** el feed (`FeedParser`) o recorre la web (`WebCrawler`).
2. **Crea o actualiza** un inmueble por colaborador + referencia externa (tabla `clarto_imported_properties`). En web la
   clave es la URL de la ficha (la referencia que lea la IA no es estable); la referencia del colaborador queda en la nota.
   - Nuevo: estado **`draft`** → **no aparece en `/api/v1/catalogo` ni en clarto.es**. Publicarlo es decisión manual del
     equipo en cada ficha (cambiar el estado a disponible).
   - Vinculado al colaborador con una asignación «aportado» **sin avisos** (sale en su pestaña «Colaboración» y en su
     panel de colaborador) y con una nota interna: origen, referencia, URL del anuncio y fecha.
   - Siguientes lecturas: solo se escriben los campos que **cambiaron en el origen**; lo que el equipo edite en el resto
     se respeta. Un inmueble que el equipo borró no se vuelve a crear.
3. **Fotos**: se descargan una sola vez al disco público (`inmuebles/importados/{colaborador}/`) como fotos del inmueble
   (máx. 20 por inmueble, 8 MB, solo JPEG/PNG/WebP/GIF).
4. **Retirados**: un anuncio que ya no está en el origen pasa a «retirado» y el inmueble a `withdrawn` (nunca se borra;
   uno vendido/alquilado no se toca). Si reaparece, vuelve como `draft`. **Solo se retira tras una lectura completa que
   encontró algo**: un feed caído o vacío, una página que falla o llegar al límite de páginas no retiran nada.
5. **Auditoría** (`activity_log`, log `colaboradores`): `importado`, `importacion_actualizado` (valor anterior y nuevo),
   `importacion_retirado`, `importacion_reaparecido`, cada ejecución (`importacion_ok|parcial|error|sin_autorizacion`),
   la solicitud manual y los cambios de ajustes (incluida la fecha de autorización).

## Modo web (rastreo educado)

- **robots.txt** respetado (grupo `ClartoBot` o `*`, Allow/Disallow con `*` y `$`, la regla más larga gana) y su
  `Crawl-delay` si es mayor que la pausa.
- **Una petición cada 5 s** (`IMPORTACION_WEB_PAUSA`), 1 s entre fotos; **User-Agent** `ClartoBot/1.0 (+https://clarto.es; …)`.
- **Límite de 150 peticiones por ejecución** (`IMPORTACION_WEB_MAX_PAGINAS`), solo el mismo dominio. Sitemap índice → sitemaps hijos.
- Extracción por orden: **JSON-LD schema.org** (RealEstateListing, Product, Offer, Apartment, House…; nunca los nodos de
  la agencia ni personas) → **OpenGraph** / `product:price` → operación y tipo deducidos del título/URL → **IA** solo
  para lo que falte (`App\Collaborators\Import\ListingAi`, por defecto `OpenRouterListingAi`: modelos gratuitos de
  OpenRouter a través del cliente del correo entrante, nunca de pago). Sin patrón, solo van a la IA las páginas que
  muestran un precio. Si la IA gratuita se agota, el resto de la ejecución sigue sin IA.

## Datos personales y seguridad

- Solo se leen campos del anuncio: precio, operación, tipo, m², habitaciones, baños, municipio/zona, título,
  descripción, fotos y referencia. **Ninguna dirección exacta** (la dirección del inmueble queda como «zona, municipio»).
- Correos y teléfonos se borran de títulos y descripciones (`[…]`). Datos del propietario o de la agencia del feed no se leen.
- Toda petición pasa por `ImportHttp`: solo http(s), nunca a direcciones privadas/reservadas (también tras redirecciones).
- **Nada de colaboradores va a GoHighLevel** (la prueba lo comprueba).

## Programación y comandos

- `clarto:importar-colaboradores --diario` a las 05:30 (proceso propio, `runInBackground`).
- `clarto:importar-colaboradores` cada minuto **solo si hay** «Importar ahora» pendientes (la acción marca
  `requested_at`; así un rastreo de minutos no choca con los límites del worker de colas).
- `clarto:importar-colaboradores --colaborador=ID` para lanzarlo a mano (misma puerta). Bloqueo por colaborador
  (`Cache::lock`) para no solapar ejecuciones.

## Configuración (`config/importacion.php`)

`IMPORTACION_USER_AGENT`, `IMPORTACION_WEB_PAUSA` (5), `IMPORTACION_WEB_MAX_PAGINAS` (150), `IMPORTACION_FOTOS_MAX` (20),
`IMPORTACION_IA` (clase `ListingAi`), `IMPORTACION_IA_MODELO` (vacío = el modelo gratuito por defecto). Ninguna es obligatoria.

## Archivos

- Migración `2026_12_05_000001` (dos tablas nuevas). `app/Collaborators/Import/*`, `app/Filament/Collaborators/CollaboratorImportUi.php`,
  `lang/{es,en}/importacion.php`, `database/fixtures/importacion/*` (datos ficticios de la prueba).
- Archivos compartidos tocados (una o dos líneas): `Collaborator::import()`, `CollaboratorResource::form()` (sección),
  `EditCollaborator` (acción + `unset($data['import'])`), `ClartoCollaboratorsServiceProvider` (comandos, programación, enlace `ListingAi`).

## Prueba

`php artisan clarto:importacion-prueba` — 33 comprobaciones con datos **ficticios**, sitios **simulados** (`Http::fake`
con los fixtures locales, nunca webs reales), IA simulada y base de datos revertida: la puerta sin autorización (cero
peticiones, ni manual ni programada), guardar la sección desde la ficha, Kyero (borrador, fuera del catálogo, vínculo,
campos, fotos, foto a 169.254… bloqueada, sin datos personales, auditoría), segunda lectura sin cambios, cambio en
origen respetando ediciones, retirado sin borrar, feed caído/vacío sin retirar, reaparición como borrador, JSON genérico,
web (robots.txt, User-Agent, pausas, JSON-LD → OpenGraph → IA, límite de páginas), direcciones internas bloqueadas,
programación, colaborador de baja y ninguna llamada a GoHighLevel.

Ejecutada el 22/11/2026 en el contenedor de staging (imagen `6b6f7de1` + rama) sobre una copia de la base de datos:
importacion 33/33, colaboradores 46/46, solicitudes 81/81.
