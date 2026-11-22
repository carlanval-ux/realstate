# Conector de publicación a pisos.com

El equipo gestiona el inmueble una sola vez en el panel y decide en la ficha, pestaña **Portales**, si lo envía a
pisos.com: **Publicar en pisos.com**, **Actualizar**, **Retirar** y **Consultar estado**.

## Estado

**Envíos reales desactivados.** `PISOS_ENABLED=false`, `PISOS_DRIVER=simulado`: las acciones de la ficha funcionan
contra una simulación local con el mismo contrato (nada sale del servidor). Para activar la publicación real hacen
falta los accesos de la última sección.

## Cómo está hecho

Sigue la estructura de los conectores oficiales (Rightmove, Zoopla, OnTheMarket): un *transport* (contrato
`App\Portals\Pisos\PisosTransport`), un cliente HTTP (`PisosApiTransport`, API v5) y un registro de sincronización
por inmueble y portal (`clarto_portal_listings`: id externo, estado, último error, última respuesta, fechas).
`PisosSimulatedTransport` es la simulación. `PisosConnector` aplica las reglas:

| Estado | Significado |
|---|---|
| no enviado | aún no se ha intentado |
| **enviado** | se envió pero pisos.com no confirmó (sin respuesta) |
| **aceptado** | pisos.com validó el alta o el cambio y devolvió su `ID` |
| **publicado** | «Consultar estado» confirma `Estado = 0` (activo) |
| **rechazado** | error de validación (`Respuesta.ErrorCodeId` / `Text`) o `Estado = -4` (pendiente de completar); se guarda el motivo |
| retirado | desactivado en pisos.com (`Estado = -2`); el inmueble sigue igual en el panel |
| error | pisos.com no disponible (5xx) |

* **Sin duplicados.** Si el inmueble ya tiene `ID` de pisos.com, «Publicar» se convierte en actualización (`PUT`).
  Tras un envío sin confirmar, se busca el anuncio por nuestra referencia (`Ref. CL-…` al final de la descripción)
  en `GET usuarios/{userId}/inmuebles` antes de reintentar.
* **Retirar** usa `POST inmueble/deactivate/{id}/{userId}`; no borra (`DELETE`) ni toca el inmueble en el panel.

## Mapeo (API v5, modelo `Inmueble`)

| pisos.com | Panel |
|---|---|
| `Descripcion` | título aprobado + descripción larga aprobada en castellano + `Ref. CL-AAAA-NNNNN` (v5 no tiene campo de título ni de referencia) |
| `CodigoTipoInmueble` | `config/pisos.php` → `tipos` (solo «piso» `F002S021T001` está documentado) |
| `Precios[0]` | `TipoOperacion` (`operaciones`, códigos no documentados), `Valor` = precio, `Oculto` = false |
| `Localizacion` | `CodigoZonaGeo` (`zonas`, por municipio o barrio), `CodigoPostal`, latitud/longitud **redondeadas a 2 decimales** (sin calle ni número) |
| `Caracteristicas` | `NumHabitaciones`, `NumBanos`, `SuperficieConstruida`, `AnoConstruccion`, `energiaconsumocategoria` y extras detectados (`Ascensor`, `Trastero`, `Jardin`, `Piscina`, `Terraza`, `Garaje`, `AireAcondicionado`) — nombres de la v4, **a confirmar** |
| `Usuario` | contacto autorizado de la agencia (`PISOS_CONTACTO_*`) y `PISOS_USER_ID` |
| Fotos | `POST media/saveMultimediaXml`: `Tipo` 1 (foto), `Orden`, `Origen` 4 (pasarelas), URL pública firmada del catálogo (1600 px) |

**Obligatorio antes de enviar** (la ficha lo indica): estado publicable, título y descripción aprobados en castellano,
códigos de tipo, operación y zona, precio, superficie, código postal, al menos una foto y teléfono de contacto.

## Pruebas

`php artisan clarto:pisos-prueba` — respuestas **simuladas** de pisos.com (`Http::fake`) y transacción revertida:
envíos desactivados, datos que faltan, alta, reintento sin duplicado, cambio de precio y fotos, consulta de estado,
rechazo con motivo y reintento, alta sin respuesta y reintento que lo encuentra, retirada sin tocar el inmueble y
portal caído. **Nada se ha probado contra la API real**: no hay entorno de pruebas documentado y no tenemos ApiKey.
Además (selección): registro de portales, ninguna tarea programada que envíe, envío y retirada masivos de los
seleccionados, confirmación con el número, filtros «Publicado / No publicado / Con error en pisos.com», columna
«Portales» y acciones masivas en el listado. 28 comprobaciones.

## Portales: registro y selección de lo que se envía

Requisito del propietario (22/11/2026): «Luego tenemos pisos.com y habrá más webs externas. Debo poder seleccionar los
inmuebles que envío.»

- **Registro** (`config/portales.php` + `App\Portals\Portals`): cada portal es una clase que implementa
  `App\Portals\PortalConnector` (publicar, actualizar, retirar, consultar, qué falta, si los envíos reales están
  permitidos) y una entrada en la configuración, con el módulo CLARTO que lo enciende. Hoy solo pisos.com (en simulación).
- **Nada se envía solo**: no hay ninguna tarea programada. Solo se envía lo que el equipo elige:
  - en la ficha, pestaña «Portales» (una sección por portal);
  - en el **listado de inmuebles**: seleccionar y usar las acciones masivas **«Enviar a pisos.com…»** y **«Retirar de
    pisos.com…»**. La confirmación dice cuántos se enviarán (y cuántos no, por ser de colaboradores sin permiso); al
    terminar, un aviso resume los enviados y los que no, con el motivo.
- **Listado**: columna **Portales** («pisos.com: Publicado», con color por estado) y por cada portal un filtro con
  **Publicado en …** (enviado, aceptado o publicado), **No publicado en …** y **Con error en …** (error, rechazado o
  con mensaje pendiente).
- **Inmuebles de colaboradores**: solo se envían si la ficha del colaborador tiene **«Puede difundirse en portales
  externos»** (desactivado por defecto) **y** la fecha de la autorización por escrito (que debe cubrirlo). Si no, la
  acción de la ficha está desactivada con el motivo, el envío masivo los omite y lo dice, y el propio conector lo
  rechaza aunque se le llame directamente (`Portals::collaboratorRefusal`). **Marca blanca**: lo enviado son los textos
  limpiados, la referencia de CLARTO y fotos por URL firmada del catálogo; nunca datos del colaborador.
- Para añadir un portal: su conector (con su simulación, como `PisosSimulatedTransport`), su `config/<portal>.php` y
  la entrada en `config/portales.php`. La columna, los filtros, las acciones masivas y la pestaña salen solos.
- Archivos de módulo tocados (parche): `PropertyResource` del módulo de inmuebles: columna, filtros y acciones masivas
  (`PropertyPortals::column()/filters()/bulkActions()`). Migración `2026_12_07_000001` (`portals_allowed`).

## Qué hay que pedir a pisos.com

La ayuda de la v5 (https://api.pisos.com/v5/Help) se genera sola y no documenta campos obligatorios, respuestas ni
códigos de error; parece la API de su propia web y de la publicación para particulares. Las agencias suelen publicar
por **pasarela** con contrato.

**Credenciales y accesos**
1. Contrato de publicación para CLARTO (número de anuncios contratados).
2. Código de agencia / código de volcado (AgencyID) de cada oficina.
3. ApiKey de integrador para nuestro software y cómo se envía (parámetro `apiKey` o cabecera); `OrigenPublicacion` que le corresponde.
4. `userId` (o credenciales) de la cuenta profesional de la agencia, para `deactivate/activate`.
5. ApiKey y URL de un entorno de pruebas, si existe; límites de uso.
6. Contacto técnico para validar la integración antes de producción.

**Documentación**
1. Canal correcto para una agencia: API v5 `POST/PUT inmueble` o pasarela (y su especificación: formato, envío push o URL de volcado, frecuencia, completo o incremental).
2. Campos obligatorios por tipo y operación, y lista de `ErrorCodeId` de `Respuesta`.
3. Tablas de códigos: `CodigoTipoInmueble`, `Precio.TipoOperacion`, claves y valores de `Caracteristicas`, certificado energético, antigüedad, planta y estado de conservación.
4. Ubicación: ¿es obligatorio `CodigoZonaGeo` o bastan código postal y coordenadas? ¿cómo se oculta la dirección exacta?
5. Identificadores: ¿podemos enviar nuestra referencia? ¿qué hace un `POST` repetido? ¿el `ID` llega en la respuesta?
6. Ciclo de vida: diferencia entre `delete` y `deactivate`, caducidad y renovación, moderación y cómo se consultan los rechazos (sondeo o aviso).
7. Fotos: subida o URL, formatos, tamaño, número máximo, portada y orden; vídeo y visita virtual.
8. Contacto por anuncio u oficina, o siempre el de la cuenta.
9. Campos legales: índice de referencia del alquiler, renta anterior, licencia turística.

## Activar la publicación real

En el servidor (`.env`), nunca en el repositorio:

```
PISOS_DRIVER=api
PISOS_ENABLED=true
PISOS_API_KEY=…
PISOS_USER_ID=…
PISOS_CONTACTO_TELEFONO=…
PISOS_OPERACION_VENTA=…
PISOS_OPERACION_ALQUILER=…
```

y completar `tipos` y `zonas` en `config/pisos.php` con las tablas de pisos.com. Probar primero con un inmueble ficticio.
