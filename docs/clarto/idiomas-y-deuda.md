# CLARTO · Idiomas (es, en, tr) y deuda técnica

## Qué hay

**Panel.** Castellano por defecto (también para las cuentas nuevas), inglés y turco. Cada usuario elige su idioma
en el selector de la barra superior; se guarda en su perfil (`users.locale`) y en la sesión. `APP_LOCALES=es,en,tr`
limita los idiomas ofrecidos. Traducciones: `lang/{es,tr}/filament.php` (textos propios del panel),
`lang/{es,tr}.json` (Jetstream/Fortify), `lang/{es,tr}/*.php` de laravel-lang (validación, contraseñas, auth),
`lang/vendor/filament*` (Filament) y `lang/{en,es,tr}/clarto.php` (estados del inmueble, que los módulos
mostraban en crudo).

**Textos públicos del inmueble.** Pertenecen al **inmueble** (`real_estate_properties`), que es lo que publica el
catálogo. El anuncio (`real_estate_listings`) solo tiene `title` y `channel_content` libre y no se usa todavía.

| Campo | es | en / tr |
|---|---|---|
| Título | `real_estate_properties.title` | `clarto_property_texts.title` |
| Descripción corta | `clarto_property_texts.summary` (no existía) | `clarto_property_texts.summary` |
| Descripción larga | `real_estate_properties.description` | `clarto_property_texts.description` |
| Título SEO | `clarto_property_texts.seo_title` (no existía) | `clarto_property_texts.seo_title` |
| Descripción SEO | `clarto_property_texts.seo_description` (no existía) | `clarto_property_texts.seo_description` |

Referencia, precio, superficie, estado y galería son únicos por inmueble. El castellano sigue en las columnas del
inmueble porque el resto de módulos (portales, valoraciones, búsquedas) las leen.

Se editan en la ficha, pestaña **Textos públicos** (Español · English · Türkçe), con un contador de campos
pendientes por idioma. Cada idioma se guarda por separado. Publicar no depende de inglés ni de turco.

`php artisan clarto:textos-idioma [--apply]` comprueba el idioma de los títulos y descripciones ya guardados antes
de tratarlos como castellano; si detecta inglés o turco, con `--apply` los copia a ese idioma (sin borrar nada).

**API del catálogo.** `GET /api/v1/catalogo/inmuebles?idioma=en` y `/inmuebles/{ref}?idioma=tr`. Sin `idioma`, castellano
(respuesta compatible con la actual). Por campo: el texto del idioma pedido o, si falta, el castellano.
`idiomas_textos` dice el idioma real de cada texto devuelto, `traduccion_completa` si título y descripción larga
están en el idioma pedido, e `idiomas_completos` (ficha) qué idiomas lo están. Los nombres de tipo, operación,
extras y etiquetas de características siguen en castellano: la web debe traducirlos a partir de sus claves.

## Deuda técnica registrada

### Traducción asistida por IA de los textos públicos (pendiente)

- **Ahora:** la edición en los tres idiomas es manual.
- **Propuesta:** generar borradores de traducción (en, tr) a partir del castellano. Opciones: las herramientas de IA
  de Lovable, o un proveedor registrado en `Liberu\Localization\Contracts\TranslationProviderRegistry`
  (el módulo `localization-mymemory` es un ejemplo, desactivado).
- **Cómo guardarlas:** como fila de `clarto_property_texts` con `source = 'ia'` (la columna ya existe), editable en la
  misma pestaña y marcada como «propuesta». No se publican hasta que una persona la revisa (`source` pasa a
  `manual`); una traducción revisada nunca se sobrescribe con una propuesta nueva.
- **Antes de decidir:** evaluar coste, calidad en turco, tratamiento de datos (los textos no llevan datos personales)
  y si Lovable puede escribir en Liberu solo a través de una API con permisos limitados.

### Otros

- Los nombres de tipo, operación, extras y etiquetas de la ficha que devuelve la API están en castellano.
- La web aún no tiene rutas en inglés o turco (ver `docs/inmuebles/README.md` del repositorio de la web).
- Los paquetes oficiales no traducen todos sus textos al castellano ni al turco: lo que queda en inglés se corrige
  en `lang/` del host (no en `modules/`, que reescribe Composer).
