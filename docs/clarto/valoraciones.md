# Valoraciones automáticas

La web (clarto.es/inmuebles/valoraciones) pide a Liberu una **estimación automática orientativa** del precio de venta
de una vivienda. Liberu localiza el inmueble en Catastro, calcula precio central, intervalo y suficiencia de los datos,
genera un **informe PDF de marca CLARTO (dos páginas de contenido, con portada opcional)** que envía al cliente por correo y WhatsApp, registra contacto y **una oportunidad por solicitud**
en GoHighLevel y guarda todo con su histórico. Si los datos no permiten estimar, la solicitud queda guardada y pasa a
**revisión del equipo** con una explicación clara.

Módulo «Valoraciones automáticas» (`clarto-valoraciones`) en Configuración de la plataforma → Módulos. Código en
`app/Valuations/`, proveedor `ClartoValuationsServiceProvider` (registrado desde un bloque delimitado de
`ClartoServiceProvider::register()`), rutas en `routes/clarto-valoraciones.php`, migración `2026_11_25_000001`.
No toca ningún fichero de `modules/`.

## Qué es real y qué es simulado

| Pieza | Estado |
|---|---|
| Catastro (OVC Callejero y Coordenadas, sin clave) | **Real.** Las pruebas reproducen respuestas reales grabadas el 2026-11-21 (`resources/valoraciones/pruebas/catastro/`). |
| Precios públicos (`resources/valoraciones/precios.json`) | **Reales y fechados** (instantánea del 2026-11-21). En el cálculo solo entra lo de reutilización autorizada (MIVAU valor tasado). |
| Estimador CLARTO 1.0.0 | **Real**, determinista, documentado abajo. |
| idealista/data, Tinsa AVM | **Adaptadores preparados** (`ExternalAvm`): modo `off` (por defecto), `simulado` o `http`. Sin contrato: el modo `http` no se ha probado contra el proveedor. |
| Resumen con IA | Real (`App\Ai\AiClient`, OpenRouter del panel) cuando hay clave y responde; si no, plantilla determinista. |
| GoHighLevel | Real cuando están las variables `GHL_*`; en las pruebas, simulado. |

`datos_simulados: true` en la respuesta y un aviso en el PDF cuando ha respondido un proveedor en modo simulado.

## Metodología (Estimador CLARTO `1.0.0`, metodología v1)

Alineada con `orquestacion/valoraciones-investigacion.md` §1.4 y §4.1.

**Precio de cierre = €/m² base × m² construidos con comunes × Π(1 + ajuste) [+ importe fijo de anexos]**, redondeado a 1.000 €.

### 1. Inmueble y superficie

Con la referencia catastral que el usuario confirma se consulta `Consulta_DNPRC`, que separa las unidades: `VIVIENDA`,
`ELEMENTOS COMUNES` y anexos (`APARCAMIENTO`, `ALMACEN`…). `sfc` es el total de la referencia, **no** la vivienda.

* **Superficie de cálculo (pisos)** = vivienda + elementos comunes, **sin anexos**. Es la definición de las fuentes de
  precio: el MIVAU y las estadísticas notariales y registrales dan €/m² **construido**, normalmente con comunes.
* **Casa unifamiliar**: toda la construida de Catastro (garaje y almacenes forman parte de la casa y de su escritura);
  sin ajuste aparte por garaje o trastero. Si la `VIVIENDA` de Catastro es menos de la mitad del total (Bellreguard
  `CL MAJOR 10`: 36 de 113 m²), no se adivina qué es vivienda: **revisión**.
* Las fuentes de precio publican €/m² «construido» sin aclarar si incluye comunes (investigación §2.2): se aplica a la
  construida con comunes y se declara como limitación en el informe.
* Útil declarada → construida con comunes: × 1,25 (supuesto razonado: útil ≈ 0,80–0,85 de la construida sin comunes,
  regla práctica de mercado, más ≈ 5–10 % de comunes).
* Diferencia declarado/Catastro ≤ 15 %: se usa Catastro. Si lo declarado coincide (± 8 %) con el **total** de Catastro
  con anexos, se explica y se usa vivienda + comunes. 15–30 %: se usa Catastro, se marca «superficies discrepantes»
  (sin los 10 puntos de coherencia, intervalo + 5 puntos). > 30 %: **revisión**.
* `cpt` (coeficiente de participación) no se usa (incoherente en edificios antiguos, §1.4).

### 2. Zona y €/m² base

Cadena de ámbitos: **código postal** (serie con ≥ 30 operaciones) → **municipio** (≥ 20) → sin dato: **revisión**.
La provincia (MIVAU 1.980,1 €/m²) es solo contexto: no da precio por sí sola. Solo entran las series de fuentes cuya
**reutilización automatizada está permitida** (`precios.json`, `uso: autorizado`); el resto, solo con
`VALORACIONES_FUENTES_PENDIENTES_LICENCIA=true` y marcadas «PRUEBA INTERNA» en el informe.

* Orden de tipo dentro del ámbito: compraventa > tasación > oferta.
* **MIVAU valor tasado** (hoy la única serie municipal autorizada): columna por antigüedad, «hasta 5 años» si el
  edificio tiene ≤ 5 años, si no «más de 5 años», último trimestre publicado (2026T2). Sin indexar con el IPV: no hay
  trimestre IPV posterior (no se extrapola).
* Tasación → cierre: factor **1,0** (supuesto razonado de la metodología v1: la tasación de compraventa se aproxima
  al precio pactado). Se muestra como limitación que las compraventas escrituradas/registradas declaran de media un
  7–14 % menos (contexto: Notariado; provincia, Registradores Open Data / MIVAU = 0,903). Oferta → cierre −10 %
  (supuesto; hoy no hay serie de oferta autorizada).
* **Excepciones de zona** (`codigos_postales.<cp>.excepcion`): si el dato municipal no representa la zona y no hay
  serie del CP utilizable → **revisión**. Hoy: Gandia **46730** (playa; Notariado 2.125 €/m² frente a 1.511 del municipio, +40 %).
  Bellreguard (sin serie MIVAU, < 25.000 hab.) va a revisión por falta de datos autorizados.

| Municipio | Serie usada (2026T2, MIVAU) | Hasta 5 años | Más de 5 años | Tasaciones |
|---|---|---|---|---|
| València (46250) | 2.972,8 | 3.464,9 | 2.957,4 | 2.735 |
| Sagunto/Sagunt (46220) | 1.628,6 | 2.176,1 | 1.612,3 | 366 |
| Gandia (46131) | 1.757,0 | 1.709,9 | 1.757,6 | 372 |
| Bellreguard (46048) | no publicado | — | — | — |

### 3. Ajustes (`InHouseEstimator::ADJUSTMENTS`)

Hipótesis iniciales de la metodología v1 (supuestos razonados de mercado, **no** derivados de una fuente
estadística), a calibrar con las operaciones cerradas de CLARTO y con el AVM cuando se contrate. Se aplican de forma
multiplicativa; si el producto se aleja más de ±30 % de 1 → **revisión**.

| Factor | Ajuste | Justificación / dato |
|---|---|---|
| Conservación | a reformar −15 %; buen estado 0; reformado +7 %; obra nueva +10 % | Supuesto: coste de reforma y prima de estado; declarado por el usuario |
| Planta y ascensor (pisos) | bajo −7 %; 1.ª–2.ª sin ascensor −3 %; 3.ª sin ascensor −10 % y −3 % por planta más; con ascensor 0; ático con ascensor +5 % | Supuesto: accesibilidad y luz; planta de Catastro o del usuario |
| Exterior | interior −5 % | Supuesto |
| Terraza | > 10 m²: +3 %; en zona de costa (Puerto de Sagunto 46520, Gandia 46730, Bellreguard) +5 % | Supuesto |
| Año de construcción (si no se declara reformada u obra nueva) | < 1960 −5 %; 1960–1980 −3 %; 1981–2005 0; ≥ 2006 +3 % | `ant` de Catastro (año original, no refleja reformas) |
| Tamaño | < 50 m² +5 %; 50–120 0; > 120 −5 %; > 180 −10 % | Supuesto: el €/m² baja con la superficie |
| Garaje / trastero (pisos) | importe fijo por municipio (`precios.json` `anexos.importes`, a fijar por CLARTO); mientras falte, +5 % / +1,5 % **provisional** | Supuesto; el informe lo indica |

### 4. Suficiencia (0–100) e intervalo

| Concepto | Puntos |
|---|---|
| Inmueble identificado en Catastro y confirmado | +30 |
| Superficies coherentes (diferencia ≤ 15 %) | +10 |
| Base del código postal (≥ 30 operaciones) / municipal ≥ 100 / municipal 20–99 | +30 / +20 / +10 |
| Dato de ≤ 6 meses / ≤ 12 meses | +15 / +8 |

Alta ≥ 70, media 45–69, baja 30–44, **< 30 → revisión**. Intervalo: ±8 % (alta), ±12 % (media), ±18 % (baja),
+2 puntos si la base es municipal (no CP), +5 si hay superficies discrepantes, al menos la mitad de la diferencia entre
fuentes, máximo ±25 %. El desglose de puntos y el margen se guardan en el cálculo y salen en el PDF.

### 5. Revisión del equipo (estado `revision`, sin precio)

No es vivienda (o Catastro no la da como residencial); falta la superficie o es < 20 o > 300 m²; discrepancia de
superficie > 30 %; zona con excepción; sin datos autorizados; suficiencia < 30; ajustes fuera de ±30 %. La solicitud
queda guardada, con su motivo, y se crea igualmente la oportunidad en GHL.

### 6. Oferta y cierre

El resultado es siempre **precio de cierre** (`tipo_precio: "cierre"`). No se calcula precio de oferta hasta tener
una serie de oferta con licencia (proveedor).

### 7. Ejemplo reproducible (prueba automática)

Puerto de Sagunto, `CL CASTELLON 2 Pl 01 Pt 02` (`9342915YJ3994S0002ZR`, 109 m², 1979, sin fila de comunes),
buen estado, con ascensor, exterior: 1.612,3 €/m² (MIVAU Sagunt, más de 5 años, 2026T2) × 109 m² × 0,97
(1960–1980) = 170.468 → **170.000 €**; suficiencia 30 + 10 + 20 + 15 = **75 (alta)**; intervalo ±(8 + 2) % →
**153.000–187.000 €**. Coincide con el ejemplo de la investigación (§4.1).

Cada cálculo (`clarto_valuation_runs`) guarda las entradas normalizadas, la base (fuente, ámbito, periodo, columna,
operaciones), los ajustes, el desglose de suficiencia, las fuentes (nombre, fecha, URL, licencia), `version_calculo`
y `version_datos` (versión de la instantánea `precios.json`).

## Fuentes (instantánea del 2026-11-21)

| Fuente | Tipo | Ámbito y fecha | Superficie | Licencia | En el cálculo |
|---|---|---|---|---|---|
| Sede Electrónica del Catastro (OVC Callejero y Coordenadas) | catastral | inmueble, en el momento de la consulta | construida por unidad | datos no protegidos de los servicios web libres; se cita «Dirección General del Catastro, consulta dd/mm/aaaa»; **uso comercial pendiente de confirmación** (consulta preparada, investigación §4.5) | sí (consulta automática) |
| MIVAU, valor tasado de vivienda libre (35103500 / 35101000) | tasación | municipios > 25.000 hab. y provincia, 2026T2 | construida | reutilización autorizada (Ley 37/2007), citar MIVAU | **sí**, desde instantánea fechada |
| Registradores Open Data, compraventas residencial por provincia | compraventa | provincia, 12 meses a 2026T2 | construida | ODbL 1.0 | solo contexto (limitación) |
| Consejo General del Notariado, Portal Estadístico | compraventa | municipio y CP, últimos 12 meses | construida | sin fines comerciales ni bases de datos sin autorización del CGN | **no** (solo prueba interna, marcada) |
| Fotocasa, índice de precios | oferta | municipio, 2026-10 | construida (anuncio) | términos no verificados | **no** (ídem) |

Las cifras se cargan desde una **instantánea manual fechada** (`resources/valoraciones/precios.json`, versión
`2026-11-21b`), no por consulta automática: actualizarla con cada trimestre del MIVAU y subir la versión.
Investigación completa: `orquestacion/valoraciones-investigacion.md` y `orquestacion/valoraciones-datos/`.

## Lo que hay que contratar para completar la valoración automática

1. **Licencia de datos del Consejo General del Notariado** (precio de cierre por municipio y código postal, también
   municipios pequeños como Bellreguard), **o** bien
2. **idealista/data** — «API de valoraciones y datos catastrales» + «API de testigos» (incluye cierres notariales
   desde mayo de 2026), con permiso expreso para mostrar el valor en la web y en un PDF con marca CLARTO; o
   **Tinsa AVM** vía API como segundo presupuesto. Preguntas y borradores: `orquestacion/valoraciones-datos/proveedores/INFORME-proveedores.md`.

Con 2, basta configurar el proveedor en el panel (modo `http`, URL, credenciales) y ajustar en `ExternalAvm` las
rutas de respuesta (`VALUE_PATHS`, `MIN_PATHS`, `MAX_PATHS`) a su documentación.

## API (servidor de la web → Liberu)

Contrato: `orquestacion/valoraciones-api.md`. Base `https://panel.clarto.es/api/v1/valoraciones`, cabecera
`X-Valoraciones-Clave`, la web reenvía la IP del visitante en `X-Cliente-Ip` (límite por IP y global).

* `GET /catastro?direccion=&municipio=&provincia=` o `GET /catastro?rc=` → `ok | varias | no_encontrada | catastro_no_disponible`.
* `POST /solicitudes` (idempotente por `request_id`; 201 nueva, 200 existente; 422 datos no válidos —el teléfono es
  **obligatorio** y se normaliza a E.164—; 403 módulo desactivado). Responde **sin resultado** (el navegador no lo
  muestra): `{estado: recibida|revision|error|procesando, referencia, envio: {email, whatsapp}, mensaje, motivo, datos_simulados}`.
* `GET /solicitudes/{request_id}` → la misma forma; reintenta lo pendiente (GHL, envíos).
* `GET /informes/{request_id}/{n}?expires&signature` — PDF con enlace firmado, **heredado**: ya no se envía; los enviados siguen sirviendo hasta caducar o hasta que el equipo revoque los enlaces de la solicitud (entonces 410). Los enlaces nuevos son los cortos de `valoraciones.clarto.es` (abajo).
* Panel: `/filament/clarto/valoraciones/{id}/informe/{n?}` para el personal autorizado.

El cálculo es síncrono; el resumen con IA, GHL y la entrega al cliente van **en cola** (`ProcessValuation`, el worker).

## Entrega al cliente (correo y WhatsApp)

Tras calcular, `ProcessValuation` (cola): resumen con IA (y PDF de nuevo), GoHighLevel y `ValuationDelivery`:

* **Correo** (buzón del panel, info@clarto.es): HTML de marca con el precio, el intervalo orientativo y el botón
  el enlace corto para consultar y descargar el informe (30 días), el **PDF adjunto** y el bloque «Solicitar una valoración
  personalizada con CLARTO». En `revision`/`error`: aviso de que el equipo la revisará, sin precio ni PDF.
* **WhatsApp** (línea de la empresa, `App\Communications`): texto del propietario (abajo) con el enlace corto; en `revision`, el aviso.
  Si la línea WAHA no está vinculada (QR) queda **`linea_no_vinculada`** y se reintenta sola.
* Estados por canal: `pendiente | enviado | error | linea_no_vinculada`, con fecha, intentos y error, en la solicitud
  (`delivery`, visible en el panel: columnas «Correo» y «WhatsApp» y sección «Envío al cliente») y en el registro de
  comunicaciones (en la ficha del contacto si el correo o el teléfono son de un único contacto; si no, ligado a la solicitud).
* Cada canal se envía **una vez por cálculo** (clave de idempotencia por solicitud, cálculo y canal): reintentar la
  petición, la cola o el panel no duplica correos, WhatsApp ni oportunidades.
* Reintento automático cada 15 minutos (`clarto:valoraciones-reenviar`, programado desde el proveedor) de lo pendiente
  de los últimos 7 días (máx. 40 intentos). Panel: «Reenviar al cliente» (lo pendiente) y «Enviar este cálculo al
  cliente» (un recálculo no se envía solo).
* Las solicitudes creadas **antes** de esta función (sin estado de envío, p. ej. VAL-000001 de pruebas) no se envían
  nunca automáticamente; solo con «Enviar este cálculo al cliente».

## Enlaces públicos del informe (`https://valoraciones.clarto.es/r/{codigo}`)

Encargo del propietario del 21/11/2026 (`orquestacion/encargo-enlaces-informe.md`).

* **Un solo generador** (`App\Valuations\ReportLinks`) para todos los productores: correo y WhatsApp de entrega,
  campos de GHL (`informe_url`, `informe_caduca`, `val_informe_url`, `val_informe_caduca`) y las acciones del panel.
  Las secuencias de nutrición por WhatsApp no llevan el enlace del informe.
* **Código**: 16 bytes de `random_bytes` (128 bits) en base64url sin relleno = 22 caracteres. No deriva de la
  referencia, del `request_id` ni de datos del cliente. **Se guarda solo su SHA-256** (`clarto_valuation_report_links`),
  ligado a la solicitud y al cálculo (versión del informe), con caducidad (`VALORACIONES_INFORME_DIAS`, 30), aperturas,
  revocación y si se escribió en GHL. Un mismo envío (GHL + correo + WhatsApp, en el mismo proceso) comparte un enlace;
  cada proceso nuevo crea el suyo. GHL no se reescribe mientras tenga un enlace vivo del mismo cálculo.
* **Resolución en el servidor** (`InformeEnlaceController`, ruta `r/{codigo}`): página de consulta (zona, referencia,
  intervalo, fechas, «Ver el informe», «Descargar el PDF») y `r/{codigo}/informe.pdf` (`?descargar=1` adjunto), servidos
  desde el mismo host, **sin redirecciones**. El PDF sale del mismo disco privado que antes. Otro host (p. ej. el panel)
  responde 404 en `/r/*`; Caddy solo reenvía `/r/*` de `valoraciones.clarto.es` y el resto da 404 (sin panel ni API).
* **Cabeceras** de todas las respuestas `/r/*` (también 404, 410 y 429): `X-Robots-Tag: noindex, nofollow, noarchive`
  (+ `<meta name="robots">`), `Referrer-Policy: no-referrer`, CSP `default-src 'none'` (CSS con *nonce*, logo en
  `data:`; sin JS, fuentes, analítica ni recursos de terceros), `Cache-Control: private, no-store`, `X-Frame-Options: DENY`.
  Sin sesión ni cookies.
* **Estados**: caducado o revocado → 410 con página clara; desconocido → 404; límite → 429 (30/min por IP y 600/min en
  total: `VALORACIONES_ENLACES_LIMITE_IP`, `VALORACIONES_ENLACES_LIMITE_GLOBAL`).
* **GET idempotente**: abrir el enlace no lo consume. Las vistas previas (WhatsApp, Facebook) y los escáneres de correo
  (Proofpoint, Mimecast, SafeLinks…) no cuentan como apertura (`ValoracionesController::isPreview`); la persona sí (L-15,
  `report_opens` y campo `informe_aperturas` de GHL). Solo cuenta la página, no el PDF.
* **Logs**: el código nunca se escribe entero. Registro de comunicaciones: `…/r/AbCd…` (`ReportLinks::redact`).
  Telescope descarta cualquier entrada que lo contenga. Apache registra `/r/[codigo-oculto]`; Caddy no tiene log de
  accesos. El panel muestra solo una «huella» (8 caracteres del hash).
* **Panel** (Valoraciones automáticas): «Nuevo enlace del informe» revoca los anteriores, crea uno (se muestra una vez
  para copiarlo), actualiza GHL y, si se marca, lo envía por correo y WhatsApp. «Revocar enlaces del informe» desactiva
  los cortos **y los firmados antiguos** de la solicitud y vacía el campo en GHL; nada vuelve a salir con enlace hasta
  crear uno nuevo (un recálculo posterior sí). Detalle: sección «Enlaces del informe».
* Host configurable: `VALORACIONES_ENLACES_HOST` (por defecto `valoraciones.clarto.es`). DNS: registro A
  `valoraciones` → IP del VPS (Hostinger).
* Pruebas: `php artisan clarto:enlaces-prueba`.

### Textos de entrega (tono «tú»; la web y el PDF siguen en «usted» por ahora)

WhatsApp, texto literal del propietario (`ValuationDelivery::whatsAppText`):

```
Hola, {{nombre}}. Ya tienes disponible la valoración orientativa de tu inmueble en {{zona}}.

Puedes consultar y descargar el informe hasta el {{fecha_caducidad}}:
{{enlace_corto}}

Es una estimación informativa. Si estás pensando en vender, podemos revisar contigo las características de la vivienda y preparar una valoración personalizada.

¿Te viene mejor que te llamemos por la mañana o por la tarde?

Equipo CLARTO
```

`{{zona}}` = municipio (nunca la dirección: el mensaje se ve con la pantalla bloqueada); `{{fecha_caducidad}}` =
caducidad del enlace, dd/mm/aaaa (Europa/Madrid). Solo si el cliente eligió franja en el formulario, la pregunta final
pasa a «Te llamaremos por la mañana/tarde. Si te viene mejor otro momento, respóndenos a este mensaje.». No se nombra
oficina (ninguna está confirmada por una persona). En `revision`: aviso de que el equipo la revisará, con la referencia.
El correo usa el mismo tono, el mismo enlace y la misma invitación (y el bloque comercial en «tú»).

## Informe PDF: formatos

«Formato del informe» en Configuración de la plataforma → Valoraciones automáticas (por defecto «Con portada»):

| Formato | Páginas | Qué es |
|---|---|---|
| Con portada (`portada`) | portada + 2 | Portada a sangre con foto, dirección, precio e intervalo; 2 páginas de contenido. El límite de 2 páginas se aplica al contenido. |
| Esencial ilustrada (`esencial`) | 2 | Las mismas 2 páginas de contenido, con la foto como cabecera de la primera. |
| Compacta (`compacta`) | 2 | Versión sobria anterior, con los textos corregidos. |

Contenido común: «Estimación orientativa» (en cabecera, pie y etiquetas), precio de cierre, «Intervalo orientativo»
(indicador semicircular y barra), datos del inmueble con iconos, resumen, cálculo, fuentes, «Observaciones» (la
primera: «Estimación orientativa: no es una tasación oficial (Orden ECO/805/2003)…») e invitación comercial con
«Solicitar una valoración personalizada con CLARTO», 960 30 31 31, WhatsApp 623 22 97 79, info@clarto.es.
La versión del cálculo y de los datos ya no se imprime: va en los metadatos del PDF (`Subject`) y en el cálculo guardado.

Recursos (sin dependencias nuevas; el lienzo `BrandPdf` embebe las fuentes):
* Tipografías **Manrope** (ExtraBold, Bold), **DM Sans** (Regular, Bold) y **Source Serif 4** (Italic), licencia **SIL
  OFL 1.1** (`resources/valoraciones/fonts/OFL-*.txt`), de los paquetes de Fontsource (WOFF 1.0 convertidos a TTF).
* Fotos de la web de CLARTO (`public/images/clarto`, **Unsplash License**, autores en `docs/design/asset-manifest.md`
  de la web): «valencia» (DaYsO) para València y «inmobiliaria» (Stefan Vibes) para el resto, con el pie «Imagen
  ilustrativa · Foto: …». No representan el inmueble.

### Afirmaciones comerciales que el propietario debe confirmar

Texto del propietario (21/11/2026), igual en los tres formatos y en el correo (`ReportData::SALES_*`): «VENDA CON
CLARTO · Vendemos su vivienda con lo último en tecnología. · Cerramos operaciones de más calidad en menos tiempo:
tecnología de última generación, vídeos profesionales de su inmueble y estrategias de venta de vanguardia, con un equipo
de abogados, asesores y gestores a su lado en cada paso», y los pilares «Lo último en tecnología», «Vídeo y estrategia
de vanguardia» y «Un equipo a su lado en cada paso». Por la normativa de publicidad (Ley 3/1991 de Competencia Desleal,
arts. 5 y 7; Ley 34/1988 General de Publicidad) cada afirmación debe poder acreditarse; el propietario confirma que es cierto:
1. «Cerramos operaciones de más calidad en menos tiempo»: es comparativa sin término de comparación; conviene tener
   datos propios que la respalden (plazos y condiciones medios de las ventas de CLARTO frente al mercado).
2. «Lo último en tecnología» / «tecnología de última generación»: superlativo; respaldarlo con lo que se usa de verdad
   (valoración con datos oficiales, difusión digital, firma electrónica, WhatsApp). La firma electrónica y el SMS siguen
   en modo simulado hasta contratar DocuSeal/SMS.
3. «Vídeos profesionales de su inmueble»: que se ofrecen en todas las ventas (o indicar en qué condiciones).
4. «Estrategias de venta de vanguardia» y «estrategia de precio y comercialización».
5. «Abogados, asesores fiscales y gestores de CLARTO: contratos, impuestos de la venta, documentación y firma ante
   notario. En toda España» (servicios y alcance geográfico).
6. Teléfono 960 30 31 31, WhatsApp 623 22 97 79 e info@clarto.es (tomados de clarto.es).

## GoHighLevel

Contacto (upsert, ahora siempre con teléfono) + oportunidad en el pipeline de captación (`GHL_PIPELINE_ID`, etapa
`GHL_PIPELINE_STAGE_ID`) con los campos de `config/ghl.php` (`solicitud_id`, municipio, superficie…) y los de la valoración
(`GHL_FIELD_REFERENCIA_VALORACION`, `_ESTADO_VALORACION`, `_PRECIO_ESTIMADO`, `_INTERVALO`, `_SUFICIENCIA`,
`_VERSION_CALCULO`, `_INFORME_URL`, `_DATOS_APORTADOS`). Un recálculo actualiza la misma oportunidad.
**Ganada** → el flujo existente (`GhlHandoff`: propietario, inmueble en borrador, tarea TARMIO) y además el inmueble en
borrador recibe en sus notas internas la referencia `VAL-…`, el precio, el intervalo y la referencia catastral.

## Panel

* **Propietarios y relaciones → Valoraciones automáticas**: solicitudes con estado, precio, envío (correo y WhatsApp) y
  GHL; detalle con datos aportados, Catastro, errores, envíos, historial de cálculos (cada uno con su PDF); acciones
  «Informe», «Recalcular» (nuevo cálculo, el anterior se conserva), «Reenviar al cliente», «Enviar este cálculo al
  cliente» y «Reintentar GHL».
* **Configuración de la plataforma → Valoraciones automáticas**: formato del informe; modo de cada proveedor, URL y
  credenciales (cifradas con `APP_KEY`, nunca se muestran; un campo vacío conserva lo guardado), estado de la clave de
  la API, de GHL y de los datos.
* Permisos: super administración; administración del equipo; o un rol del equipo con `valoraciones.ver` (ver) /
  `valoraciones.gestionar` (recalcular, envíos, GHL). Otro equipo nunca ve las solicitudes ni sus informes.

## Variables de entorno

| Variable | Uso |
|---|---|
| `VALORACIONES_API_KEY` | clave compartida con el servidor de la web (también en Lovable Secrets) |
| `VALORACIONES_INFORME_DIAS` | días de validez de los enlaces del informe (30) |
| `VALORACIONES_LIMITE_IP`, `VALORACIONES_LIMITE_GLOBAL` | peticiones por minuto (20 / 300) |
| `VALORACIONES_ENVIO_EMAIL`, `VALORACIONES_ENVIO_WHATSAPP` | envío al cliente por cada canal (`true`) |
| `CATASTRO_URL`, `CATASTRO_TIMEOUT`, `CATASTRO_CACHE_HORAS` | Catastro (por defecto OVC, 8 s, 168 h) |
| `VALORACIONES_IDEALISTA_DRIVER` / `_URL` / `_TOKEN_URL` / `_CLIENT_ID` / `_SECRET` | idealista/data (`off`) |
| `VALORACIONES_TINSA_DRIVER` / `_URL` / `_TOKEN_URL` / `_CLIENT_ID` / `_SECRET` | Tinsa AVM (`off`) |
| `VALORACIONES_PROVEEDOR_TIMEOUT` | segundos por proveedor (12) |
| `VALORACIONES_FUENTES_PENDIENTES_LICENCIA` | `false`; `true` solo para pruebas internas |
| `VALORACIONES_RESUMEN_IA` | `true` |
| `GHL_PIPELINE_STAGE_ID` | etapa inicial de la oportunidad (además de `GHL_API_TOKEN`, `GHL_LOCATION_ID`, `GHL_PIPELINE_ID`) |
| `GHL_FIELD_*` | opcionales: los campos se buscan por clave (docs/clarto/captacion-ghl.md) |

El correo sale con la configuración de «Correo saliente» del panel; el WhatsApp, por la línea vinculada en
«WhatsApp». Requiere el worker de colas en marcha (`liberu-worker`).

## Pruebas

`php artisan clarto:valoraciones-prueba [--ejemplos=/tmp/dir]` — 62 comprobaciones en una transacción revertida
(cola síncrona, `Mail::fake`, WAHA/GHL/IA simulados): Valencia, Puerto de Sagunto (dirección con 26 pisos y el ejemplo
de la metodología), Gandía ciudad (superficies discrepantes), Gandía playa (anexos y excepción de zona) y Bellreguard;
sin comparables; fallo de proveedor; teléfono obligatorio; correo con adjunto; WhatsApp con línea sin vincular y
después vinculada; reintentos sin duplicar envíos ni oportunidades; recálculo con histórico y envío bajo demanda;
solicitudes anteriores no enviadas; los 3 formatos (compacta y esencial ≤ 2 páginas, con portada = 3, contadas en los
bytes); enlaces sin firma o de otro equipo. Cada línea indica si usa Catastro real grabado, precios públicos o datos
simulados. Ejemplos: `orquestacion/valoraciones-ejemplos/v2/` (PDF de cada formato y PNG de cada página).
