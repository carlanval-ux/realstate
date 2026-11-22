# Conservación de las solicitudes de valoración

La política de privacidad publicada en clarto.es promete:

- los datos de la valoración se conservan **24 meses desde el último contacto**;
- el consentimiento comercial dura **12 meses**.

Liberu lo cumple con `App\Valuations\Retention`, que ejecuta el comando `clarto:valoraciones-conservacion` **cada día a
las 03:40** (programado desde `ClartoValuationsServiceProvider`, un solo servidor, sin solapes). Migración
`2026_12_01_000001` (columna `anonymized_at`). Solo afecta a las solicitudes `VAL-` de las valoraciones automáticas.

```bash
php artisan clarto:valoraciones-conservacion --dry-run   # solo cuenta lo que haría; no cambia ni borra nada
php artisan clarto:valoraciones-conservacion             # lo que hace el programador cada noche
php artisan clarto:conservacion-prueba                   # prueba ejecutable con datos ficticios (BD revertida)
```

## Decisiones

### Qué es «último contacto»

El último gesto **de la persona**, no de CLARTO. Se toma la fecha más reciente de:

1. cualquiera de **sus solicitudes** de valoración (la misma persona = mismo móvil en E.164 **o** mismo email, sin
   distinguir mayúsculas): pedir otra valoración renueva todas;
2. la última **apertura de un enlace del informe** (`/r/{codigo}`; solo personas: las vistas previas de WhatsApp y los
   escáneres de correo no cuentan, `ValoracionesController::isPreview`);
3. el último **mensaje recibido** desde su móvil o su email en el registro de comunicaciones (WhatsApp, correo).

Lo que envía CLARTO (correos y WhatsApp de nutrición, recordatorios) **no** alarga el plazo: si contara, la propia
nutrición mantendría los datos para siempre.

### Anonimizar, no borrar

Pasados 24 meses la solicitud se **anonimiza**: la fila se queda para la estadística (cuántas valoraciones, dónde, de
qué tipo, con qué resultado) y desaparece todo lo que identifica a la persona o a la vivienda.

| Se conserva | Se elimina |
|---|---|
| Referencia `VAL-`, fechas (alta, cálculos), estado, operación | Nombre, email, teléfono, canal, franja, plazo, relación, comentario (texto libre) |
| Municipio, provincia, tipo, superficie construida/útil | Dirección, referencia catastral, planta, puerta, coordenadas y el resto del inmueble; respuesta de Catastro; clave de inmueble |
| Del cálculo: estado, código de motivo, precio central, intervalo, €/m², tipo de precio, moneda, suficiencia, fecha, versión de cálculo y de datos, superficie usada | Resumen (IA o plantilla), mensaje, sujeto, ajustes, errores de proveedores |
| `report_opens` (número de aperturas) | **PDF** de todos los cálculos (y su carpeta) |
| | **Enlaces cortos** `/r/{codigo}` (filas borradas → el enlace deja de existir) y los firmados antiguos (revocados) |
| | **Planes de nutrición WhatsApp** y sus mensajes programados (por solicitud, móvil o contacto GHL) |
| | Consentimiento, atribución (UTM, gclid…), IP, ids de contacto y oportunidad de GHL, estado de la entrega, errores |
| | En el **registro de comunicaciones**: teléfono, email, asunto y texto de los mensajes de la valoración y de cualquier mensaje con ese móvil/email que no sea de un contacto de Liberu (queda la fila con «[Eliminado: plazo de conservación cumplido]») |
| | **Órdenes de WhatsApp** que pidió GHL para ese contacto/móvil |

Por qué anonimizar: el propietario quiere estadísticas de captación por zona y tipo, y una fila sin datos personales
no está sujeta al plazo. Por qué no se conserva el código postal ni la planta: junto con tipo y superficie permitirían
identificar la vivienda.

En el panel, una solicitud anonimizada se ve con «Datos personales eliminados el dd/mm/aaaa (plazo de conservación)» y
queda **en solo lectura**: sin recalcular, reenviar, enlaces ni GHL (`ValuationRequestResource::canManage`; no basta el
gate porque Shield deja pasar al superadministrador por todos).

### Consentimiento comercial (12 meses sin renovar)

Cuenta desde el **consentimiento comercial más reciente de la persona** (`consentimiento.comercial.fecha`, o la fecha
del consentimiento, o el alta). Una solicitud nueva con consentimiento comercial lo **renueva**. Pasados 12 meses sin
renovar, en cada solicitud con consentimiento activo: email y WhatsApp pasan a `false`, se añade
`comercial.caducado` (fecha) y se conservan versión, textos y fecha originales como prueba; sus planes de nutrición
activos se cancelan («Consentimiento comercial caducado (12 meses)») y sus WhatsApp programados no saldrán. La
nutrición ya lo comprobaba antes de cada envío (`NurturePlan::attempt`); ahora además queda registrado.

### Excepciones (solo para la anonimización)

- **Cliente de CLARTO**: la misma regla que detiene la nutrición (L-20, `NurturePlan::isClient`): su móvil, email o
  contacto GHL es de un contacto de Liberu (propietario, cliente…), hay una oportunidad ganada traspasada
  (`clarto_ghl_handoffs` procesada o pendiente) o la solicitud ya tiene inmueble (`property_id`). Sus datos pasan a la
  relación de cliente y se rigen por su propia base legal. Su consentimiento comercial sí caduca.
- **Bloqueo legal**: no existía ningún indicador. Se añade el mínimo: `VALORACIONES_CONSERVACION_BLOQUEO` en el `.env`,
  lista de referencias separadas por comas (`VAL-000123,VAL-000456`) para una reclamación, una inspección o una orden
  judicial. Mientras estén ahí, no se anonimizan. Si se usa a menudo, merecerá un campo en el panel.

### Qué no toca

- La **lista de bajas de WhatsApp** (`clarto_whatsapp_optouts`, solo el móvil): hay que conservarla para seguir
  respetando el «BAJA».
- Los mensajes de un **contacto de Liberu** (con `party_id`): son de la relación con el cliente.
- **GoHighLevel**: el comando no llama a GHL (ver abajo).
- Copias de seguridad y registros del servidor: caducan con su propia rotación.

### Registro

Solo recuentos, nunca nombres, emails, teléfonos ni referencias. Línea en el log de Laravel:
`Valoraciones: conservación de datos {"revisadas":…,"anonimizadas":…,"excluidas_cliente":…,"excluidas_bloqueo_legal":…,
"pdf_borrados":…,"enlaces_borrados":…,"planes_nutricion_borrados":…,"comunicaciones_anonimizadas":…,
"ordenes_whatsapp_borradas":…,"consentimientos_caducados":…,"planes_nutricion_cancelados":…}` (con «(simulacro)» en
`--dry-run`). Es idempotente: una solicitud anonimizada no se vuelve a tratar.

## GoHighLevel (lo hace GHL, no Liberu)

GHL guarda su propia copia del contacto (nombre, email, teléfono, campos de la valoración, oportunidad, conversaciones).
Liberu **no borra ni modifica nada en GHL**. Recomendación para la subcuenta CLARTO, con el mismo criterio:

1. **Workflow «Conservación 24 meses»** (diario o mensual): contactos con la etiqueta `valoracion-gratuita` cuya última
   actividad de la persona (último mensaje entrante; última valoración = `consentimiento_tratamiento_fecha`, que Liberu
   reescribe con cada solicitud nueva; última apertura del informe) tenga más de 24 meses y **sin** `cliente-clarto` / `propietario-cliente` ni oportunidad ganada → **eliminar
   el contacto** (borra también sus oportunidades y conversaciones). Mientras no exista, una lista inteligente con ese
   filtro y borrado manual mensual.
2. **Workflow «Consentimiento 12 meses»**: 12 meses después de `consentimiento_comercial_email_fecha` /
   `consentimiento_comercial_whatsapp_fecha` sin renovar → esos campos a «Caducado», quitar `nutricion-activa`, añadir
   `consentimiento-caducado` (la parada WF-13 corta correos y WhatsApp). Una valoración nueva vuelve a escribir la fecha
   (`GhlValuationSync`) y renueva.
3. Las referencias `VAL-` anonimizadas en Liberu siguen en la oportunidad de GHL hasta que GHL la borre: el paso 1 es
   el que completa la anonimización.
