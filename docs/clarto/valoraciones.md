# Valoraciones automáticas

La web (clarto.es/inmuebles/valoraciones) pide a Liberu una **estimación automática orientativa** del precio de venta
de una vivienda. Liberu localiza el inmueble en Catastro, calcula precio central, intervalo y suficiencia de los datos,
genera un **informe PDF de marca CLARTO (máximo dos páginas)**, registra contacto y **una oportunidad por solicitud**
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
| Precios públicos (`resources/valoraciones/precios.json`) | **Reales y fechados.** En el cálculo solo entran las fuentes de reutilización autorizada (MIVAU, Registradores Open Data). |
| Estimador CLARTO 1.0.0 | **Real**, determinista, documentado abajo. |
| idealista/data, Tinsa AVM | **Adaptadores preparados** (`ExternalAvm`): modo `off` (por defecto), `simulado` o `http`. Sin contrato: el modo `http` no se ha probado contra el proveedor. |
| Resumen con IA | Real (`App\Ai\AiClient`, OpenRouter del panel) cuando hay clave y responde; si no, plantilla determinista. |
| GoHighLevel | Real cuando están las variables `GHL_*`; en las pruebas, simulado. |

`datos_simulados: true` en la respuesta y un aviso en el PDF cuando ha respondido un proveedor en modo simulado.

## Metodología (Estimador CLARTO `1.0.0`)

1. **Inmueble.** Con referencia catastral confirmada por el usuario se consulta `Consulta_DNPRC`, que separa
   vivienda, elementos comunes y anexos (garaje, almacén/trastero…).
2. **Superficie de cálculo** = construida **con** la parte de elementos comunes y **sin** anexos (pisos). Los anexos
   no se suman: se valoran como ajuste. En una **casa unifamiliar** se usa toda la construida de Catastro (garaje y
   almacenes forman parte de la casa) y no hay ajuste aparte.
   * Útil → construida con comunes: × 1,25 (≈ 15 % muros y tabiques + ≈ 10 % comunes).
   * Si lo declarado difiere ≤ 10 % de Catastro, se usa Catastro.
   * Si coincide (± 8 %) con el **total** de Catastro con anexos, se explica y se usa la vivienda + comunes.
   * Diferencia del 10–35 %: se usa Catastro, baja la suficiencia y el intervalo se amplía 4 puntos.
   * Más del 35 %: **revisión** del equipo.
3. **Precio base €/m² de cierre** del código postal (si hay serie) o del municipio:
   compraventas > tasaciones > ofertas. Tasación → cierre con el factor **0,903** = compraventas registradas de
   vivienda en la provincia (Registradores Open Data, 1.788 €/m², 12 meses a 2026T2) / valor tasado provincial
   (MIVAU, 1.980,1 €/m², 2026T2). Oferta → cierre: −10 %.
4. **Ajustes** (sobre el €/m², tope −30 % / +25 %): conservación (a reformar −12 %, reformado +6 %, obra nueva +12 %);
   planta baja −6 %; sin ascensor planta 1/2/≥3: −2/−5/−10 %; planta ≥ 4 con ascensor +2 %; ático +5 %; interior −5 %;
   terraza ≥ 4 m² +2 %, ≥ 10 m² +4 %; antigüedad ≥ 40 años −3 %, ≥ 60 −5 % (si no está reformada), ≤ 10 años +3 %;
   garaje incluido +5 %; trastero +1,5 %. Constantes en `InHouseEstimator::ADJUSTMENTS`.
5. **Suficiencia** (empieza en alta y baja un nivel por cada uno): sin Catastro; discrepancia de superficie;
   serie con < 100 operaciones; datos de más de 12 meses; solo ofertas; fuentes que difieren > 25 %; el municipio
   tiene precios por código postal pero no utilizables (mezcla zonas, p. ej. Gandia ciudad/playa).
   Por debajo de «baja» → **revisión**.
6. **Intervalo**: ±8 % (alta), ±12 % (media), ±18 % (baja); al menos la mitad de la diferencia entre fuentes;
   +4 puntos por discrepancia de superficie; +1 punto por cada dato clave sin indicar (estado, planta). Redondeo a 1.000 €.
7. **Revisión** (sin precio) cuando: no es vivienda (o Catastro no la da como residencial), falta o es atípica la
   superficie (< 20 o > 1.000 m²), discrepancia excesiva, municipio sin datos, o solo datos pendientes de licencia.
8. **Precio de oferta y de cierre**: el resultado es siempre **precio de cierre** (`tipo_precio: "cierre"`); las
   ofertas solo entran convertidas.

`version_calculo` se guarda en cada cálculo; `version_datos` es la versión de `precios.json`.

## Fuentes (consultadas el 2026-11-21)

| Fuente | Tipo | Ámbito y fecha | Licencia | En el cálculo |
|---|---|---|---|---|
| Sede Electrónica del Catastro (OVC) | catastral | inmueble, en el momento | información no protegida, reutilización libre citando la fuente | sí |
| MIVAU, valor tasado de vivienda libre (35103500 / 35101000) | tasación | municipios > 25.000 hab. y provincia, 2026T2 | reutilización autorizada (Ley 37/2007), citar MIVAU | sí |
| Registradores Open Data, compraventas residencial por provincia | compraventa | provincia, 12 meses a 2026T2 | ODbL 1.0 | sí (factor tasación → cierre) |
| Consejo General del Notariado, Portal Estadístico | compraventa | municipio y CP, últimos 12 meses | uso personal; uso comercial y bases de datos **requieren autorización del CGN** | **no** (solo `VALORACIONES_FUENTES_PENDIENTES_LICENCIA=true`, pruebas internas) |
| Fotocasa, índice de precios | oferta | municipio, 2026-10 | términos no verificados | **no** (ídem) |

Investigación completa: `orquestacion/valoraciones-investigacion.md` y `orquestacion/valoraciones-datos/`.
Consecuencias: Bellreguard (menos de 25.000 habitantes) **no tiene serie autorizada** y va a revisión; Gandia y
Puerto de Sagunto usan la media municipal aunque existan datos por código postal (Notariado) más precisos.

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
* `POST /solicitudes` (idempotente por `request_id`; 201 nueva, 200 existente; 422 datos no válidos; 403 módulo desactivado).
* `GET /solicitudes/{request_id}`.
* `GET /informes/{request_id}/{n}?expires&signature` — PDF con enlace firmado (30 días), sin sesión.
* Panel: `/filament/clarto/valoraciones/{id}/informe/{n?}` para el personal autorizado.

GHL y el resumen con IA se hacen **después** de responder; un fallo de GHL se reintenta en cada consulta de la
solicitud o con «Reintentar GHL» en el panel, sin duplicar la oportunidad (id guardado al crearla y búsqueda por la
etiqueta `#xxxxxxxxxx` o el campo `solicitud_id`, como hacía `src/lib/ghl.ts` de la web).

## GoHighLevel

Contacto (upsert) + oportunidad en el pipeline de captación (`GHL_PIPELINE_ID`, etapa `GHL_PIPELINE_STAGE_ID`) con
los campos de `config/ghl.php` (`solicitud_id`, municipio, superficie…) y los de la valoración
(`GHL_FIELD_REFERENCIA_VALORACION`, `_ESTADO_VALORACION`, `_PRECIO_ESTIMADO`, `_INTERVALO`, `_SUFICIENCIA`,
`_VERSION_CALCULO`, `_INFORME_URL`, `_DATOS_APORTADOS`). Un recálculo actualiza la misma oportunidad.
**Ganada** → el flujo existente (`GhlHandoff`: propietario, inmueble en borrador, tarea TARMIO) y además el inmueble en
borrador recibe en sus notas internas la referencia `VAL-…`, el precio, el intervalo y la referencia catastral.

## Panel

* **Propietarios y relaciones → Valoraciones automáticas**: solicitudes con estado, precio, GHL; detalle con datos
  aportados, Catastro, errores, historial de cálculos (cada uno con su PDF); acciones «Informe», «Recalcular» (nuevo
  cálculo, el anterior se conserva) y «Reintentar GHL».
* **Configuración de la plataforma → Valoraciones automáticas**: modo de cada proveedor, URL y credenciales (cifradas
  con `APP_KEY`, nunca se muestran; un campo vacío conserva lo guardado), estado de la clave de la API, de GHL y de los datos.
* Permisos: super administración; administración del equipo; o un rol del equipo con `valoraciones.ver` (ver) /
  `valoraciones.gestionar` (recalcular, GHL). Otro equipo nunca ve las solicitudes ni sus informes.

## Variables de entorno

| Variable | Uso |
|---|---|
| `VALORACIONES_API_KEY` | clave compartida con el servidor de la web (también en Lovable Secrets) |
| `VALORACIONES_INFORME_DIAS` | caducidad del enlace firmado (30) |
| `VALORACIONES_LIMITE_IP`, `VALORACIONES_LIMITE_GLOBAL` | peticiones por minuto (20 / 300) |
| `CATASTRO_URL`, `CATASTRO_TIMEOUT`, `CATASTRO_CACHE_HORAS` | Catastro (por defecto OVC, 8 s, 168 h) |
| `VALORACIONES_IDEALISTA_DRIVER` / `_URL` / `_TOKEN_URL` / `_CLIENT_ID` / `_SECRET` | idealista/data (`off`) |
| `VALORACIONES_TINSA_DRIVER` / `_URL` / `_TOKEN_URL` / `_CLIENT_ID` / `_SECRET` | Tinsa AVM (`off`) |
| `VALORACIONES_PROVEEDOR_TIMEOUT` | segundos por proveedor (12) |
| `VALORACIONES_FUENTES_PENDIENTES_LICENCIA` | `false`; `true` solo para pruebas internas |
| `VALORACIONES_RESUMEN_IA` | `true` |
| `GHL_PIPELINE_STAGE_ID` | etapa inicial de la oportunidad (además de `GHL_API_TOKEN`, `GHL_LOCATION_ID`, `GHL_PIPELINE_ID`) |
| `GHL_FIELD_*` de la tabla anterior | ids de los campos personalizados de la oportunidad |

## Pruebas

`php artisan clarto:valoraciones-prueba [--ejemplos=/tmp/dir]` — 43 comprobaciones en una transacción revertida:
Valencia, Puerto de Sagunto (dirección con 26 pisos), Gandía (superficies discrepantes) y Bellreguard; sin
comparables; fallo de proveedor; reintentos sin duplicar en GHL; recálculo con histórico; PDF ≤ 2 páginas (contadas
en los bytes); enlaces sin firma o de otro equipo. Cada línea indica si usa Catastro real grabado, precios públicos o
datos simulados. Ejemplos de informe: `orquestacion/valoraciones-ejemplos/`.
