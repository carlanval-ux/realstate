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

### Traducción asistida por IA de los textos públicos (hecho)

- **Proveedor:** el que se configura en *Ajustes de la plataforma → Inteligencia artificial* (`AiSetting`: OpenRouter
  u OpenAI, clave cifrada en el panel, modelo de traducción propio). No hay un segundo proveedor: `localization-mymemory`
  y Lovable quedan descartados para esto.
- **Qué hace:** «Traducir el castellano aprobado a EN y TR» (o «Traducir al … con IA» en cada idioma) crea, a partir del
  castellano **aprobado**, una fila de `clarto_property_texts` por idioma con `status = 'borrador'` y `source = 'ia'`,
  con el modelo usado y las comprobaciones de cifras y lugares en `notes`. Se edita en la misma pestaña («Borrador de
  IA (no publicado)»). Al proveedor solo va el texto del anuncio: ni dirección, ni propietario, ni notas internas.
- **No se publica sola:** la API del catálogo solo lee filas `aprobado`. Al pulsar **Aprobar borrador** pasa a `aprobado`
  con `source = 'manual'` (revisada por una persona; `model` se guarda como referencia).
- **Lo revisado no se sobrescribe:** la IA nunca escribe filas `aprobado`. Si una persona corrige un borrador de IA, pasa
  a `source = 'manual'` y ninguna propuesta nueva lo sustituye (ni los borradores importados de colaboradores,
  `source = 'importado'`): hay que aprobarlo o descartarlo antes. «Traducir el castellano aprobado» además se salta el
  idioma cuya traducción aprobada está completa y al día; si el castellano cambia después (`review_required_at`), la
  nueva propuesta llega como borrador y la traducción aprobada sigue publicada hasta que se apruebe otra.
- **Sin clave:** el módulo «Idiomas y traducción» sale «Pendiente de configurar»; en la ficha aparece el aviso y los
  botones de IA explican qué falta y dónde se pone, sin guardar ni enviar nada.
- **Modelos propios:** los modelos locales pequeños se midieron lentos e imprecisos (ver la nota de medición), así que
  se usa el proveedor configurado. Punto de extensión: `App\Ai\Contracts\TextCompleter`. Enlazar otra implementación
  en el contenedor (por ejemplo un servidor propio compatible con OpenAI) basta para que la usen los botones, el
  estado del módulo y `PropertyTextWriter`.
- **Pruebas:** `clarto:modulos-prueba`, apartado «4b. Traducción asistida por IA», con el proveedor simulado
  (`Http::fake`) y datos ficticios.

### Otros

- Los nombres de tipo, operación, extras y etiquetas de la ficha que devuelve la API están en castellano.
- La web aún no tiene rutas en inglés o turco (ver `docs/inmuebles/README.md` del repositorio de la web).
- Los paquetes oficiales no traducen todos sus textos al castellano ni al turco: lo que queda en inglés se corrige
  en `lang/` del host (no en `modules/`, que reescribe Composer).
