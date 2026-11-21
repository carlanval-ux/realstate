# Captación de propietarios con GoHighLevel (Liberu ↔ GHL)

Estrategia y textos: `orquestacion/ghl-estrategia.md` y anexos A–E. Activos creados en GHL (nombres e ids):
`orquestacion/ghl-activos.md`. Aquí, solo lo que hace el panel.

## Quién hace qué

| Paso | Liberu | GHL |
|---|---|---|
| Solicitud web → cálculo + PDF | `ValoracionesController`, `ProcessValuation` | — |
| Contacto (uno por persona) | upsert sin etiquetas; etiquetas por `POST /contacts/{id}/tags`; ficha del lead, consentimientos (reglas §7.3), copia `val_*` | asignación, estado del lead (WF-01…) |
| Oportunidad (una por **inmueble**) | `property_key` = `rc:<ref. catastral>` o `dir:<sha1>`; otra solicitud del mismo inmueble actualiza la oportunidad abierta (`solicitudes_ids` + nota); ganada o perdida → nueva; 400 `OPPORTUNITY_NO_DUPLICATE` → `meta.existingId` | pipeline «CLARTO · Captación de propietarios» |
| Correo 1 + WhatsApp 1 (entrega) | `ValuationDelivery` (B.1/B.1-R, B.2 según plazo, L-16) | — |
| Estado de la entrega | `reportDelivery()`: `entrega_*_estado`, etapa «Valoración entregada», nota; `entrega-error` si un canal sigue fallando a las 2 h | WF-02, WF-10 |
| WhatsApp de cita (A3–A5) | `POST /api/v1/ghl/whatsapp` (`GhlWhatsApp::order`) | acción «Webhook» |
| WhatsApp de nutrición (B.3 v2) | `NurturePlan`: planifica y envía por WAHA, comprueba todo antes de cada envío | reloj común: `POST /api/v1/ghl/nutricion` |
| Respuestas por WhatsApp | nota + `respondio-whatsapp` (agrupadas 10 min); «BAJA/STOP/no me escriban» → DND + `baja-whatsapp` + Revocado + bloqueo local | WF-04, WF-06 |
| Ganada | `POST /api/v1/ghl/oportunidad-ganada` → propietario (con consentimientos, L-10) + inmueble en borrador + encargo + tarea TARMIO; diario `clarto:ghl-ganadas` recoge las que no llegaron (L-8) | WF-08 / WF-08b |

Campos personalizados: Liberu los busca **por clave** (`opportunity.<clave>` / `contact.<clave>` del Anexo A) con
`GET /locations/{id}/customFields` (caché `GHL_FIELDS_CACHE_HORAS`, 6 h). No hace falta un `GHL_FIELD_*` por campo:
solo si GHL generó otra clave, `GHL_FIELD_<CLAVE>` / `GHL_CONTACT_FIELD_<CLAVE>` fuerzan el id (`config/ghl.php`).
Tras crear o actualizar la oportunidad se relee `inmueble_clave` (L-0): si GHL no la guardó, la sincronización queda
en «error» con el motivo.

## Webhooks de GHL

Secreto `GHL_WEBHOOK_SECRET` (solo en el `.env` del servidor y en la acción de GHL): cabecera
`X-Clarto-Webhook-Secret` o, con la acción «Webhook» estándar, dato personalizado `clarto_secreto`.

`POST /api/v1/ghl/whatsapp` — datos personalizados `tipo` (`w2|a3|a4|a5`), `texto` (≤ 1.000, variables ya resueltas),
`clave` (idempotencia, p. ej. `{{contact.id}}:a3:{{appointment.id}}`), `caduca` (ISO, opcional; 24 h por defecto);
`contact_id` del cuerpo estándar. Respuesta `{estado: enviado|en_cola|rechazado|error, motivo}`; 401 secreto; 422
datos o contacto de otra subcuenta; 403 módulo WhatsApp desactivado; 503 GHL no responde. Rechaza: DND WhatsApp,
`baja-whatsapp`, baja local, W2 sin `consentimiento_comercial_whatsapp = Sí`. En cola mientras la línea no esté
vinculada; al caducar, `whatsapp-error` + nota.

## Nutrición por WhatsApp (L-18…L-21, anexo B.3 v2 §5)

Decisión del propietario: los WhatsApp de nutrición los envía Liberu por WAHA, fuera de GHL. GHL lleva los correos y el
reloj: `POST /api/v1/ghl/nutricion` (mismo secreto) con `evento` = `inicio` (`rama` activa|templada|fria;
`asesor_nombre` ya no se usa) | `correo` (`codigo` c2…c5, `estado` enviado|sin_correo) | `parada` (WF-13). Cada correo programa su
WhatsApp 2 días laborables después (WA-T1: 3), L–V 17:30–18:30 Europe/Madrid con minuto aleatorio
(`NurturePlan::SLOTS`); festivos en `NUTRICION_WA_FESTIVOS` (lista a revisar con BOE/DOGV y los tres ayuntamientos;
faltan los locales). `clarto:nutricion-whatsapp` (cada minuto en la ventana) envía como mucho uno por ejecución y 30 por
hora y línea; una ventana perdida se omite, nunca se envía tarde. Antes de CADA envío: módulo y línea, día laborable,
idempotencia (`nutricion:{contacto}:{plan}:{código}`), ningún otro mensaje a ese número hoy ni correo de nutrición hoy,
consentimiento WhatsApp de la solicitud y sin BAJA, ningún WhatsApp recibido desde el inicio, no es cliente de CLARTO
(Party con ese teléfono, correo o contacto GHL, o Ganada procesada) y, en GHL, `nutricion-activa` sin etiquetas de
parada, sin DND y con consentimiento «Sí» (si GHL no responde, espera). Paradas inmediatas: WhatsApp recibido o BAJA
(L-20), Party guardada con ese teléfono/correo/contacto o Ganada → `cliente-clarto` en GHL; «más adelante» →
`retomar-mas-adelante` (L-21). Todo queda en la solicitud (sección GoHighLevel → Nutrición por WhatsApp) y en
Comunicaciones (`source = nutricion`). Además: como mucho tres mensajes automáticos por semana sumando canales (entrega,
correos de nutrición, WhatsApp). Textos: `NurturePlan::TEXTS` (B.3 v2 §4 aprobado; automáticos: «Le escribimos desde CLARTO», plural, sin nombre del asesor; WA3 sin duración, D7,
y «visita gratuita», D3). WA2 y WA-T1 (contenido fiscal): revisión legal aprobada (21/11/2026); la retención sigue disponible con
`NUTRICION_WA_REVISION_LEGAL` (vacía por defecto). El W2 por webhook queda retirado.

## Variables de entorno (sin secretos aquí)

| Variable | Uso |
|---|---|
| `GHL_API_TOKEN`, `GHL_LOCATION_ID`, `GHL_WEBHOOK_SECRET` | `scripts/set-ghl.sh` (bundle de despliegue) |
| `GHL_PIPELINE_ID` | pipeline «CLARTO · Captación de propietarios» |
| `GHL_PIPELINE_STAGE_ID` | etapa «Solicitud recibida» |
| `GHL_STAGE_ENTREGADA` | etapa «Valoración entregada» (L-3) |
| `GHL_ALTA_DESDE` | `AAAA-MM-DD`: solicitudes anteriores = `web_valoracion_previa` (L-7) |
| `GHL_ASESORES` | ids de usuario de GHL de los asesores de captación, separados por comas: la oportunidad nace asignada a uno; cada persona nueva va al siguiente asesor (alternancia) y un mismo propietario conserva el suyo en sus otros inmuebles (WF-01 no puede asignar oportunidades). Hoy `iDQeh2pQX6EhtYpxkW8d` (Carlos). **Para añadir a Jorge** cuando tenga usuario en GHL: añadir su id a la lista (`iDQeh2pQX6EhtYpxkW8d,<id de Jorge>`) y reiniciar el panel; nada más en Liberu. En GHL, añadirlo también al «Assign to User» de WF-01 (contacto) |
| `VALORACIONES_CITA_VALENCIA` / `_SAGUNTO` / `_BELLREGUARD` | enlaces de reserva de «Visita de valoración»; mejor en el panel (Valoraciones automáticas → Visita de valoración), que manda |
| `GHL_FIELD_*`, `GHL_CONTACT_FIELD_*` | opcionales, solo para forzar un id |

Permisos del token (§14 de la estrategia): `contacts.readonly`, `contacts.write`, `opportunities.readonly`,
`opportunities.write`, `locations.readonly`, `locations/customFields.readonly`.

## Oficina y enlaces

`Office::forPostalCode()` (L-11): `465xx` y `12590/12592/12593/12600` → Puerto de Sagunto; `467xx`, `037xx` →
Bellreguard; resto → Valencia centro (fuera de la provincia: alerta `fuera-de-zona`). El mismo enlace de cita va en el
correo 1 (`utm_content=correo1`), el WhatsApp 1 (`whatsapp1`), el PDF (`pdf`, L-17) y la respuesta de la API para la
pantalla final (`enlace_cita` + `oficina_nombre`, `gracias`, L-13). Aperturas del enlace firmado del informe →
`informe_aperturas`; la segunda sube un nivel la temperatura (L-15; los escáneres de enlaces del correo pueden sumar).

## Pruebas

* `php artisan clarto:ghl-prueba` — 56 comprobaciones (incluye las pruebas 17–25 de B.3 v2 y el modo de prueba) con GHL y WAHA simulados (transacción revertida): handoff de
  Ganada y el circuito de captación (validaciones 1–7: contacto + oportunidad + entrega, reenvío sin duplicados, dos
  inmuebles y el mismo inmueble dos veces, respuestas por WhatsApp, BAJA, webhook de WhatsApp, fallo y recuperación de
  la línea, Ganada con consentimientos y conciliación diaria, L-7).
* `php artisan clarto:valoraciones-prueba` — incluye correo B.1, `enlace_cita`, enlaces del PDF, WhatsApp según plazo.

## Decisiones del propietario (21/11/2026) que afectan a Liberu

* **Sin calendarios por ahora**: la visita se concierta por WhatsApp o teléfono. Sin enlace de calendario, el botón
  «Solicitar valoración personalizada» del correo 1, el PDF y `val_enlace_cita` en GHL llevan a
  `wa.me/34623229779` con la referencia (`ValuationRequest::visitUrl()`); el WhatsApp 1 y el WA3 invitan a responder;
  la API devuelve `enlace_cita = null`. Si se crean calendarios, basta con poner sus enlaces en el panel.
* **Consentimiento comercial: 12 meses**: un WhatsApp de nutrición no sale si el consentimiento tiene más de 12 meses.
* **Conservación de los datos de valoración: 24 meses** (política de privacidad). Las búsquedas de Liberu por teléfono
  o correo ya se limitan a 24 meses. **No existe aún** un borrado o anonimización automática de solicitudes antiguas:
  es una tarea aparte (decidir qué se borra y qué se anonimiza, y qué pasa con los PDF).
* **Modo de prueba de la nutrición** (B.3 v2 §8): `NUTRICION_WA_PRUEBA_TELEFONOS` (E.164 separados por comas, solo
  móviles del equipo) y `NUTRICION_WA_PRUEBA_MINUTOS` (2): para esos números un «día laborable» son N minutos, sin
  ventana ni límites de ritmo; todas las paradas siguen activas. Vacío en producción. `clarto:nutricion-whatsapp` corre
  cada minuto (cada mensaje lleva su propia ventana).
