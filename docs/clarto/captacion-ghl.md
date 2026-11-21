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
| WhatsApp que decide GHL (W2, A3–A5) | `POST /api/v1/ghl/whatsapp` (`GhlWhatsApp::order`) | acción «Webhook» |
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

## Variables de entorno (sin secretos aquí)

| Variable | Uso |
|---|---|
| `GHL_API_TOKEN`, `GHL_LOCATION_ID`, `GHL_WEBHOOK_SECRET` | `scripts/set-ghl.sh` (bundle de despliegue) |
| `GHL_PIPELINE_ID` | pipeline «CLARTO · Captación de propietarios» |
| `GHL_PIPELINE_STAGE_ID` | etapa «Solicitud recibida» |
| `GHL_STAGE_ENTREGADA` | etapa «Valoración entregada» (L-3) |
| `GHL_ALTA_DESDE` | `AAAA-MM-DD`: solicitudes anteriores = `web_valoracion_previa` (L-7) |
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

* `php artisan clarto:ghl-prueba` — 36 comprobaciones con GHL y WAHA simulados (transacción revertida): handoff de
  Ganada y el circuito de captación (validaciones 1–7: contacto + oportunidad + entrega, reenvío sin duplicados, dos
  inmuebles y el mismo inmueble dos veces, respuestas por WhatsApp, BAJA, webhook de WhatsApp, fallo y recuperación de
  la línea, Ganada con consentimientos y conciliación diaria, L-7).
* `php artisan clarto:valoraciones-prueba` — incluye correo B.1, `enlace_cita`, enlaces del PDF, WhatsApp según plazo.
