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
3. **Toda la información del anuncio** (requisito del propietario, 22/11/2026), en feed y en web:

   | Dato | Dónde queda |
   |---|---|
   | Precio, operación, tipo, m² construidos, habitaciones, baños | Campos de la ficha |
   | Año de construcción · certificado energético (letra) | `year_built` · `energy_rating` |
   | Consumo (kWh/m² año), emisiones (letra y kg CO2/m² año) | `epc` (`calificacion_consumo`, `consumo_kwh_m2_ano`, `calificacion_emisiones`, `emisiones_kg_co2_m2_ano`) |
   | Ascensor, garaje, trastero, piscina, terraza, A/A, calefacción, amueblado… | `features` con nombres canónicos (los lee el catálogo); garaje ⇒ `has_parking` |
   | Gastos de comunidad | `service_charge` (€/mes) |
   | Dirección tal como se publica, CP, coordenadas, municipio/zona/provincia | `address` (calle si se publica; si no, zona y municipio), `postal_code`, `latitude/longitude`, `city/region/district` si coinciden. El catálogo público no expone la dirección |
   | Descripción y título | Castellano en la ficha; **inglés y turco como traducciones en BORRADOR** (`clarto_property_texts`, fuente `importado`; la web solo lee aprobadas; nunca se pisa un texto del equipo) |
   | Fotos | **Todas, a su mayor resolución**, en el orden del anuncio (la 1.ª es la portada) |
   | Planos · vídeos YouTube/Vimeo · visita virtual | Documentos `floorplan` (descargados) · `video` (enlace) · `virtual_tour_url` |
   | Lo demás: superficie útil y de parcela, planta, orientación, estado, IBI, otros idiomas, otros vídeos/visitas, cualquier otro dato | **«Otros datos»** (`clarto_imported_properties.extra`), visibles en la pestaña «Colaboradores» de la ficha del inmueble (bloque «Datos importados del colaborador») |

   - Feed Kyero: todos los elementos v3 y **cualquier otro elemento** del `<property>` se lee por su nombre
     (`floor`, `orientation`, `community_fees`, `ibi`…). Feed genérico: los campos sin correspondencia también se leen
     por su nombre (`ListingFields`: vocabulario español/inglés de etiquetas y características).
   - Web: JSON-LD (incluidos `amenityFeature` / `additionalProperty` y el resto de propiedades del anuncio), tablas y
     listas de datos «Etiqueta: valor» del contenido principal (nunca cabecera, menú ni pie), listas de características
     y la misma ficha en otros idiomas (`hreflang`, con la misma pausa y límite).
4. **Fotos, planos y vídeos sincronizados** con el origen: se descargan una sola vez al disco público
   (`inmuebles/importados/{colaborador}/`), se **añaden las nuevas, se quitan las que ya no están** (registro y archivo)
   y se **reordenan** como en el anuncio; las fotos que suba el equipo se conservan, detrás. En web se elige la mayor
   variante de cada imagen (enlace al original, `data-zoom-image`/`data-large`, `srcset` mayor, tamaño en la URL) y se
   descartan miniaturas duplicadas, logos, iconos y fotos de otros inmuebles (si el JSON-LD trae 3+ fotos, esa es la
   galería). Límite de seguridad: 100 por tipo y 15 MB por archivo (JPEG/PNG/WebP/GIF; planos también PDF).
5. **Retirados**: un anuncio que ya no está en el origen pasa a «retirado» y el inmueble a `withdrawn` (nunca se borra;
   uno vendido/alquilado no se toca). Si reaparece, vuelve como `draft`. **Solo se retira tras una lectura completa que
   encontró algo**: un feed caído o vacío, una página que falla o llegar al límite de páginas no retiran nada.
6. **Auditoría** (`activity_log`, log `colaboradores`): `importado`, `importacion_actualizado` (valor anterior y nuevo), `importacion_otros_datos`,
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

- **Ningún dato personal del propietario** (ni del agente o la agencia): las claves personales (propietario, owner,
  contacto, teléfono, email, nombre, agente, vendedor, DNI…) se descartan enteras, también en «otros datos»; los nodos
  JSON-LD de personas y de la agencia no se leen; cabecera, menú y pie de la web no se leen; correos y teléfonos se
  borran de títulos, descripciones, características y «otros datos» (`[…]`).
- La dirección que se guarda es la **del inmueble tal como la publica el anuncio**; se queda en el panel (el catálogo
  público no expone direcciones).
- Toda petición pasa por `ImportHttp`: solo http(s), nunca a direcciones privadas/reservadas (también tras redirecciones).
- **Nada de colaboradores va a GoHighLevel** (la prueba lo comprueba).

## Marca blanca (propietario, 22/11/2026)

«En la ficha interna debe constar que el inmueble es de un colaborador y de quién, con filtros; en la web es marca
blanca: el cliente no debe notar nada.»

**Panel (interno).** «Inmueble de colaborador» = importado (`clarto_imported_properties`) o aportado a mano por un
colaborador (asignación activa «aportado»).
- Aviso destacado **encima** de la ficha del inmueble (`PropertyOrigin::notice()`): «Inmueble de colaborador: *nombre*
  (origen: feed/web/manual · ref. del colaborador · última sincronización) · Abrir la ficha del colaborador», recordatorio
  de marca blanca y los avisos de revisión pendientes, con «Marcar como revisado».
- Listado de inmuebles: columna **Colaborador** (insignia + origen) y filtros **Origen** (propio de CLARTO / de
  colaborador), **Colaborador** (con búsqueda) y **Origen de importación** (feed / web / manual).
- Tres líneas en el módulo `real-estate-properties-filament` (`PropertyResource`: aviso, columna, filtros) → van en el parche de módulos.

**clarto.es / `/api/v1/catalogo`.** El catálogo ya era una lista blanca de campos (nunca notas internas, «otros datos»,
asignaciones ni rutas de archivo) y la referencia pública es siempre la de CLARTO (`RE-AAAA-NNNNN`). Además:
- **Textos limpiados al importar** (`PublicTextCleaner`), en título, descripción, traducciones y características: se
  quita la frase o segmento entero que lleve teléfono, correo, web o el nombre/marca del colaborador (nombre, razón
  social sin «SL/SA», y la marca de su dominio, p. ej. `inmo-ficticia.es` → «inmo ficticia»), y las menciones «ref. XXX»
  y su referencia. El **original** queda en «otros datos» («Título/Descripción original del colaborador») solo para el
  equipo, y el inmueble queda marcado **«Revisar textos»** con lo que se quitó.
- **Visita virtual del colaborador**: no se pone en la ficha (llevaría su marca y su enlace); queda en «otros datos» y
  el aviso «Revisar la visita virtual» hasta que el equipo decida. Los vídeos no los publica el catálogo.
- **Fotos**: siempre descargadas y servidas por URL firmada del catálogo, re-codificadas a WebP (sin los metadatos
  originales); nunca enlazadas a la web del colaborador. `WatermarkCheck` marca **«Revisar fotos (posible marca de agua
  del colaborador)»** si el nombre/marca aparece en los metadatos de una imagen o si la misma marca aparece en el mismo
  sitio de varias fotos (comparación de bordes en una rejilla 6×6). Solo avisa: no oculta nada.
- Planos en PDF (importados): el catálogo no los ofrece (no se pueden servir como WebP).

## Quitar marcas de agua (propietario, 22/11/2026)

«Debemos usar alguna IA que quite la marca de agua de las fotos.» Una marca de agua es información para la gestión de
derechos (art. 162 LPI; art. 7 Directiva 2001/29/CE): quitarla solo es lícito con el consentimiento del titular.

- **Ajuste por colaborador** «Autoriza a retirar sus marcas de agua de las fotos» (junto a la fecha de autorización,
  desactivado por defecto). Sin él (o sin la fecha) **no se limpia nada**: el inmueble solo queda con «Revisar fotos».
  Nunca se limpian fotos de inmuebles propios de CLARTO, aportados a mano ni subidas por el equipo: solo las fotos
  importadas de ese colaborador (`metadata.origen`). `WatermarkRemoval::refusal()` lo comprueba al pedir, al procesar y al aprobar.
- **Motor**: IOPaint/LaMa en su propio contenedor (`iopaint`, despliegue: PR del bundle), CPU, red interna sin salida a
  internet; ninguna foto sale del servidor y no hay API de pago. `IOPAINT_URL` vacío = función apagada.
- **Máscara**: la detección de la marca blanca (`WatermarkCheck::mask()`: los bordes presentes en ≥ 60 % de las fotos,
  agrupados en recuadros). La respuesta del modelo se pega **solo dentro de la máscara**: fuera, cada píxel es el
  original (versión limpia en PNG, sin pérdidas).
- **Cola** `marcas-agua` (después de `default`, una foto cada vez, 85 s como máximo): se encola sola cuando la
  importación detecta la marca y el colaborador lo autoriza, o con «Quitar la marca de agua (IA)» en la ficha.
- **Revisión** (pestaña «Colaboradores» del inmueble, «Revisión de fotos»): original y versión limpia **lado a lado**,
  «Aprobar», «Descartar» y «Aprobar todas». Las imágenes se sirven solo al equipo (`colaboradores/marcas-agua/{id}/…`).
  Al aprobar, la versión limpia pasa a ser la foto pública en el mismo lugar de la galería y la copia con marca sale del
  disco público; **el original se conserva siempre en el disco privado** (`marcas-agua/{equipo}/{inmueble}/`). Reimportar
  no deshace lo aprobado. Cada paso queda en la auditoría (`marca_agua_solicitada|procesada|aprobada|descartada|error`).
- Tabla `clarto_photo_cleanups` (migración `2026_12_08_000001`, que añade también `watermark_removal_allowed`).
- Medido en el VPS de staging: ~5 s por foto de 1600×1200 (2 CPU), 1,2–1,6 GB de memoria; ver el README del bundle.

## Programación y comandos

- `clarto:importar-colaboradores --diario` a las 05:30 (proceso propio, `runInBackground`).
- `clarto:importar-colaboradores` cada minuto **solo si hay** «Importar ahora» pendientes (la acción marca
  `requested_at`; así un rastreo de minutos no choca con los límites del worker de colas).
- `clarto:importar-colaboradores --colaborador=ID` para lanzarlo a mano (misma puerta). Bloqueo por colaborador
  (`Cache::lock`) para no solapar ejecuciones.

## Configuración (`config/importacion.php`)

`IMPORTACION_USER_AGENT`, `IMPORTACION_WEB_PAUSA` (5), `IMPORTACION_WEB_MAX_PAGINAS` (150), `IMPORTACION_FOTOS_MAX` (100,
límite de seguridad por tipo), `IMPORTACION_IA` (clase `ListingAi`), `IMPORTACION_IA_MODELO` (vacío = el modelo gratuito
por defecto). Ninguna es obligatoria.

## Archivos

- Migraciones `2026_12_05_000001` (dos tablas nuevas) y `2026_12_06_000001` (`review`: avisos de revisión). `app/Collaborators/Import/*` (`ListingFields` = vocabulario de
  etiquetas, características y claves personales), `app/Filament/Collaborators/CollaboratorImportUi.php`,
  `lang/{es,en}/importacion.php`, `database/fixtures/importacion/*` (datos ficticios de la prueba).
- Archivos compartidos tocados (pocas líneas): `Collaborator::import()`, `CollaboratorResource::form()` (sección),
  `EditCollaborator` (acción + `unset($data['import'])`), `ClartoCollaboratorsServiceProvider` (comandos, programación,
  enlace `ListingAi`), `PropertyCollaborationTab` (bloque «Datos importados del colaborador»), `CatalogoController` (sin planos PDF) y `modules/real-estate-properties-filament/…/PropertyResource.php` (aviso, columna y filtros: parche de módulos).

## Prueba

`php artisan clarto:importacion-prueba` — 56 comprobaciones con datos **ficticios**, sitios **simulados** (`Http::fake`
con los fixtures locales, nunca webs reales), IA simulada y base de datos revertida: la puerta sin autorización (cero
peticiones, ni manual ni programada), guardar la sección desde la ficha; Kyero con 13 fotos y todos los datos (borrador,
fuera del catálogo, vínculo, todas las fotos en orden, foto a 169.254… bloqueada, plano PDF, vídeo, visita virtual,
características, certificado energético, año, comunidad, CP, coordenadas, «otros datos», inglés en borrador, alemán en
«otros datos», bloque visible en la ficha del inmueble, sin datos personales, auditoría); segunda lectura sin cambios;
resincronización de fotos (nueva portada, foto quitada, sin duplicar, foto del equipo conservada); cambio en origen
respetando ediciones; retirado sin borrar; feed caído/vacío sin retirar; reaparición como borrador; JSON genérico con
campos sin correspondencia; web (robots.txt, User-Agent, pausas, JSON-LD → tabla de datos → OpenGraph → IA, galería a
máxima resolución sin miniaturas/logos/otros inmuebles, plano, vídeo, visita, versión en inglés por hreflang, sin datos
del propietario ni de la agencia, límite de páginas); direcciones internas bloqueadas; programación; colaborador de baja
y ninguna llamada a GoHighLevel.

Ejecutada el 22/11/2026 en el contenedor de staging (imagen `6b6f7de1` + rama) sobre una copia de la base de datos:
importacion 74/74 (incluye portales y quitar marcas de agua: el ajuste lo decide todo, imagen con marca sintética, cambia dentro de la máscara e idéntica fuera, original privado, aprobación lado a lado) y las otras 21 suites `clarto:*-prueba` en verde (colaboradores 46/46, solicitudes 81/81, documentos 30/30, modulos 38/38, pisos 19/19…).

Marca blanca en la prueba: textos limpiados (castellano, inglés, nombre comercial «Inmobiliaria Ejemplo Costa»,
título «… | marca»), originales en «otros datos», avisos de textos/visita/fotos (marca repetida en una esquina y marca
en los metadatos; fotos limpias sin aviso), aviso destacado en la ficha y «Marcar como revisado», inmueble aportado a
mano (origen «manual»), filtros y columna del listado, y **respuestas reales del catálogo** (`show` es/en de cuatro
inmuebles de colaborador, con la traducción aprobada, e `index`) sin nombre, marca, referencias, origen, URLs, vídeo
ni visita del colaborador; referencia propia de CLARTO; fotos por URL firmada; la foto servida es WebP sin los metadatos.
