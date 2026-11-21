# Alquileres: tradicional, por habitaciones y vacacional

Rama `feat/alquileres` (base `feat/captacion-ghl`). Encargo literal: `orquestacion/encargo-alquileres.md`.
Los tres módulos comparten una única **base de ocupación**. Todo el código vive en el host (`app/Rentals`,
`app/Filament/Alquileres`) y se registra desde `ClartoRentalsServiceProvider`, al que llama `ClartoServiceProvider`
en un bloque delimitado. Todos los datos de prueba son ficticios.

## 1. Auditoría de lo existente (21/11/2026)

| Pieza | Qué ofrece | Uso en alquileres |
|---|---|---|
| **Lettings** (`real_estate_lease_agreements`, `rental_charges`, `rental_applications`) | Contratos y cargos pensados para el Reino Unido (right to rent, *deposit scheme*, campos *blockchain*). No tiene unidades, habitaciones, solapes ni vacacional. | No se usa. Hueco: no hay calendario ni control de solapes. |
| **Property Management** (`maintenance_requests`, `vendor_quotes`, `work_orders`) | Avería y presupuesto por inmueble. | No se usa: falta la unidad u ocupación, el acceso autorizado, quién paga y el cierre verificado. Se cubre con `clarto_rent_incidents`. |
| **Properties** (`real_estate_properties`, `real_estate_property_units`) | Inmueble y unidades de edificio. | Se reutiliza el **inmueble**. La unidad alquilable es propia y puede enlazarse con `property_unit_id`. |
| **Parties** (`real_estate_parties`) | Personas y empresas. | Se reutiliza: `clarto_rent_people.party_id`. Rol, identidad y solvencia van por ocupación. |
| **Comunicaciones** (`real_estate_communications`) | Registro de comunicaciones. | Se reutiliza como implementación local de «registrar comunicación». |
| **Media and Documents** (plantillas, sobres de firma) | Lo amplía la rama `feat/formularios-firma`. | Se usa a través del punto de integración «enviar a Formularios». |
| **Ficha 360** (`feat/propietarios-360`) | `App\Owners\OwnerProfileSections::register()` para añadir pestañas. | Pendiente de enlazar (§6). |
| **Albaranes** (`feat/albaranes-facturacion`) | `App\Albaranes\Albaran` + líneas, con el facturador simulado. | Se usa a través del punto de integración «crear albarán» (§6). |
| **WhatsApp/WAHA** (`feat/whatsapp-waha`) | `App\WhatsApp\WhatsApp::send(Party, phone, body, idempotencyKey, …)`. | Se usa a través de «registrar comunicación» (§6). |
| **Registro de módulos** (`feat/modulos-clarto`) | `App\Modules\ClartoModules` lee los manifiestos de `app/Modules/manifests/*`. | Esta rama añade tres manifiestos (§7). |

## 2. Base de ocupación

```
Inmueble ─┬─ Unidad «vivienda» (parent_id = null) ─── habitaciones (parent_id = vivienda)
          └─ Ocupación: unidad · módulo · tipo (contrato | reserva | bloqueo) · finalidad (habitual | temporal | turística)
                ├─ personas con rol: titular · firmante · arrendatario · ocupante · avalista · propietario · viajero
                ├─ historial (append-only) · cobros · garantías · incidencias · documentos
                └─ estados: borrador → confirmada → en_curso → finalizada | cancelada
```

* Las fechas son `[entrada, salida)`: el día de salida queda libre para la siguiente entrada. La ocupación usa las
  fechas efectivas (`actual_*`) cuando existen y, si no, las previstas.
* **Sin solapes confirmados, también con concurrencia.** Cada operación que ocupa fechas (`book`, `confirm`,
  `extend`, `start`, `finish`, `changeRoom`) va en una transacción cuya **primera sentencia escribe**
  (`lock_version + 1` de las unidades del inmueble).
  * En SQLite esa escritura toma el cerrojo de escritura antes de leer. Las peticiones simultáneas se ponen en cola
    (`busy_timeout`) y luego ven la primera; si se agota la espera, se lanza `OccupancyConflict` («otra solicitud está
    reservando…»).
  * En MySQL y PostgreSQL, ese mismo `UPDATE` bloquea las filas hasta el *commit*.
  * Después se comprueba el solape con la propia unidad, con sus habitaciones (si es la vivienda) o con su vivienda
    (si es una habitación). Dos habitaciones distintas nunca chocan.
  * `ponytail:` el cerrojo es por inmueble (en SQLite, de toda la base). Se puede afinar a filas si algún día hay mucho
    volumen.
* Desactivar un módulo bloquea las **operaciones nuevas** (`RentalModules::ensureEnabled`), pero las filas no se
  borran y el historial se sigue viendo. Las unidades no se eliminan: se desactivan.

Tablas (`2026_11_22_000001`): `clarto_rent_units`, `_occupancies`, `_people`, `_events`, `_charges`, `_guarantees`,
`_incidents`, `_documents`, `_seasons`, `_licences` y `_settings`. Permisos (`2026_11_22_000002`): filas Spatie.

**Fianza, garantía adicional, aval y seguro son conceptos distintos** (`clarto_rent_guarantees`) y nunca se cuentan
como ingresos. Renta, suministros, estancia, limpieza, servicios de CLARTO y liquidaciones van en `clarto_rent_charges`.

## 3. Flujos

* **Tradicional**
  * Alta con roles separados y contrato o anexos en Formularios, con datos precargados.
  * Fianza legal: se recibe, se deposita en la GVA con su resguardo y se liquida.
  * Garantía adicional y aval.
  * Entrega de llaves e inventario de entrada.
  * Rentas mensuales con vencimiento y reparto de suministros según el contrato.
  * Impago: cargo marcado, entrada en el historial y requerimiento registrado.
  * Avería: fotos, prioridad, acceso autorizado, proveedor, presupuesto, quién paga, responsable y cierre verificado.
  * Preaviso, prórroga, salida con acta y devolución total o parcial de la fianza con deducciones justificadas.
* **Habitaciones**
  * Habitación concreta con plazas; zonas comunes en la vivienda.
  * Contratos y cobros independientes, y suministros repartidos por porcentaje, importe fijo o «incluido».
  * Ocupaciones simultáneas.
  * Cambio de habitación: cierra la ocupación con el motivo `cambio_habitacion`, abre otra enlazada con
    `previous_occupancy_id`, copia las personas y mueve las garantías.
  * Régimen habitual o temporal, con la causa documentada.
* **Vacacional**
  * Calendario por noches, tarifas por temporada con mínimo de noches, bloqueos del propietario o de mantenimiento.
  * Reserva por canal, con titular y viajeros, cobros de estancia y limpieza.
  * Cancelación con devolución en porcentaje.
  * *Check-in* con parte a SES.HOSPEDAJES (simulado); *check-out* con orden de limpieza que se verifica.
  * Liquidación al propietario: ingresos − devoluciones − comisión − gastos a su cargo. La comisión pasa a albarán.
  * iCal: exportación del calendario e importación de bloqueos de un canal.

Pantallas (grupo «Ventas y alquileres»):
* **Contratos y reservas**: una pestaña por módulo; alta; ficha con acciones y con las pestañas Personas, Cobros,
  Fianza y garantías, Incidencias y limpieza, Documentos e Historial.
* **Unidades alquilables**, con temporadas.
* **Habilitaciones y registros**.
* **Ajustes de alquileres**, por empresa: canales, plantillas, comisión, limpieza y comunidad autónoma.

## 4. Normativa convertida en validaciones (comprobada el 21/11/2026)

El informe completo, con la confianza de cada dato, está en la carpeta de notas de la sesión
(`alquileres-normativa.md`). **Atención: la LAU cambió el 08/10/2026 con el RDL 29/2026, convalidado el 12/11/2026.**

| Regla | Validación | Fuente (consultada el 21/11/2026) |
|---|---|---|
| Vivienda habitual: prórroga obligatoria hasta 5 años (7 si el arrendador es persona jurídica). | Aviso en el historial si el contrato es más corto. | LAU art. 9.1 — [BOE consolidado a 13/11/2026](https://www.boe.es/buscar/act.php?id=BOE-A-1994-26003) |
| Vivienda temporal (incluye habitaciones): la causa debe constar en el contrato; más de 31 días y, como regla general, hasta 12 meses. | Error si falta la causa o si la duración se sale de ese rango. | LAU arts. 2.3, 7.2 y 9 bis (RDL 29/2026, [BOE-A-2026-20823](https://www.boe.es/buscar/doc.php?id=BOE-A-2026-20823)) |
| Fianza: 1 mensualidad en vivienda (también en vivienda temporal) y 2 en uso distinto (temporada anterior al 08/10/2026, DT 8.ª). | La fianza debe ser exactamente la legal. | LAU art. 36.1 y DT 8.ª |
| Garantía adicional: máximo 2 mensualidades en habitual y 1 en temporal. | Error si se supera. | LAU art. 36.5 |
| No se puede exigir al inquilino un seguro de impago. | Error con el tipo `seguro_impago` en vivienda. | LAU art. 36.5 (RDL 29/2026) |
| La fianza se devuelve en 1 mes desde la entrega de llaves; después devenga interés legal. | Marca «fuera de plazo» en la liquidación. | LAU art. 36.4 |
| Sin documento de finalización, se presume que la vivienda se entregó en buen estado. | Para descontar daños hace falta el acta de salida. | LAU art. 36.7 |
| Gestión, formalización, preparación, novación o renovación: nunca a cargo del inquilino. Un servicio opcional solo si lo pide por escrito. | Error en el albarán con pagador «inquilino». | LAU art. 20.2 |
| Habitaciones: la suma de sus rentas no puede superar la renta de la vivienda completa. | Error con la renta de referencia de la vivienda; aviso si no está informada. | LAU art. 17.9 |
| Comunitat Valenciana: el arrendador deposita la fianza legal completa en 1 mes. Modelo 816 (telemático) u 806 (en banco), que sirve de resguardo. Recargo del 5, 10, 15 o 20 %. | Resguardo obligatorio para pasar a «depositada»; aviso de recargo si se deposita tarde. | Ley 8/2004 arts. 19-23; Decreto 46/2022 art. 3 — [GVA, procedimiento 3023](https://www.gva.es/es/inicio/procedimientos?id_proc=3023) |
| VUT en la Comunitat Valenciana: inmueble completo, 10 días o menos seguidos al mismo cliente, inscripción en el Registro de Turismo vigente (5 años) e informe de compatibilidad urbanística. Prohibido por habitaciones. | La unidad debe ser una vivienda; máximo 10 noches; licencia `vut_cv` vigente para toda la estancia. | Ley 15/2018 art. 65 (DL 9/2024) — [BOE](https://www.boe.es/buscar/act.php?id=BOE-A-2018-8950), [GVA VUT](https://cindi.gva.es/es/web/turisme/habitatges-dus-turistic) |
| **Registro Único de Arrendamientos: ANULADO.** | No se exige (el tipo se conserva solo como histórico). | STS 620/2026 de 19/05/2026 — [BOE-A-2026-12300](https://www.boe.es/boe/dias/2026/06/08/pdfs/BOE-A-2026-12300.pdf) |
| SES.HOSPEDAJES: reservas, anulaciones y entradas en 24 h. Datos: nombre, apellidos (2.º si DNI), sexo, documento y soporte (a partir de 14 años), nacionalidad, nacimiento, dirección, contacto y parentesco si viajan menores. | Comunica la reserva y la anulación al registrarlas. En el *check-in* se rechaza la entrada si falta algún dato. | RD 933/2021 art. 6 y anexo I — [BOE](https://www.boe.es/buscar/act.php?id=BOE-A-2021-17461), [Sede de Interior](https://sede.interior.gob.es/portal/sede/tramites?idAgrupacion=18) |

**No convertido en validación (documentado):**
* Actualización de renta: IRAV, y tope extraordinario del 2 % hasta el 31/12/2027 (DF 6.ª del RDL 29/2026). No hay
  flujo de actualización de renta.
* Prórrogas extraordinarias: art. 10.2 y 10.3, y DF 5.ª del RDL 29/2026.
* Zona tensionada y gran tenedor.
* Desistimiento en vivienda temporal (1 mes con 10 días de aviso).
* Alquileres de **11 a 31 días** en la Comunitat Valenciana: no son VUT ni vivienda temporal. Zona gris pendiente de
  revisión jurídica; hoy el sistema los rechaza en los tres módulos.

## 5. Implementado frente a verificado con un servicio externo

| Pieza | Estado |
|---|---|
| Base de ocupación, reglas, flujos, permisos, pantallas | **Implementado y probado** (`clarto:alquileres-prueba` 51/51, más carrera real y renderizado en el contenedor de staging; ver §8) |
| Crear albarán | Implementación **local**: solicitud en el historial con número `ALB-ALQ-…`. Hay que enlazarla con `feat/albaranes-facturacion`. |
| Enviar a Formularios y firma | Implementación **local**: documento preparado con referencia `FORM-LOCAL-…`. Hay que enlazarla con `feat/formularios-firma`. |
| Registrar comunicación | **Local**: fila `recorded` en `real_estate_communications`; **no se envía nada**. Hay que enlazarla con WAHA, SMS y correo. |
| SES.HOSPEDAJES | **Simulado** (`SimulatedSesHospedajes`): valida los datos y devuelve `SES-SIM-…`. **No verificado** con Interior. |
| Calendario de canales | **Preparado** (`IcalCalendar`): la exportación e importación iCal son reales y están probadas con un fichero ficticio. **No se descarga ninguna URL de canal** (aún no hay URLs). |
| Depósito de fianza en la GVA | Se registra el resguardo (816/806) a mano; **sin conexión** con la ATV. |

## 6. Puntos de integración que hay que enlazar después de fusionar

Están enlazados con `bindIf` en `ClartoRentalsServiceProvider::register()`. Para sustituirlos, hay que añadir un
`bind()` del adaptador real en el mismo proveedor.

| Interfaz | Implementación local | Adaptador que hay que escribir |
|---|---|---|
| `App\Rentals\Integration\AlbaranGateway::request(Occupancy, ?partyId, lines, reference): string` | `LocalAlbaranGateway` | Crear `App\Albaranes\Albaran` (borrador) con las líneas (`description`, `quantity`, `unit_price`), el cliente de `Albaran::clientDataFor($party)` y el `property_id`. Devolver su número. |
| `FormsGateway::prepare(RentalDocument, signers, sendToSignature): string` | `LocalFormsGateway` | Usar `App\Signing\Signing::prefill()`, y `->send()` si hay que firmar, con la plantilla de `RentalSettings::forTeam()['plantillas'][kind]`. Devolver el id de la solicitud. |
| `CommunicationLog::log(Occupancy, channel, purpose, to, subject, body)` | `TableCommunicationLog` | Para `whatsapp`: `App\WhatsApp\WhatsApp::send($party, $to, $body, "alq-{$id}-…")`. Para SMS y correo: el canal de la rama de formularios o el *mailer*. Conservar la fila de registro. |
| `TravellerRegistry::submit()/notify()` | `SimulatedSesHospedajes` | Cliente del servicio web de SES.HOSPEDAJES (XML) cuando estén las credenciales (§9). |
| Ficha 360 | — | En `ClartoRentalsServiceProvider::boot()`: `OwnerProfileSections::register('alquileres', fn () => …, sort: 60)`. Debe listar `Property::rentalOccupancies` (relación ya declarada) del propietario. |
| Registro de módulos | `RentalModules` lee `module_activations` | Con `ClartoModules` presente se usa solo; el proveedor ya llama a `ClartoModules::implement()` para las tres claves. |

## 7. Registro de módulos (para el orquestador)

| Manifiesto | Nombre visible | Clave | Versión | Estado |
|---|---|---|---|---|
| `app/Modules/manifests/clarto-alquiler-tradicional` | Alquiler tradicional | `alquiler_tradicional` | 1.0.0 | Implementado; integraciones locales; `default_enabled: true` |
| `app/Modules/manifests/clarto-alquiler-habitaciones` | Alquiler por habitaciones | `alquiler_habitaciones` | 1.0.0 | Implementado; integraciones locales; `default_enabled: true` |
| `app/Modules/manifests/clarto-alquiler-vacacional` | Alquiler vacacional | `alquiler_vacacional` | 1.0.0 | Implementado; SES e iCal de canales preparados (simulados); `default_enabled: true` |

## 8. Permisos

Permisos Spatie (guard `web`), que se asignan desde «Roles». Los administradores y superadministradores los tienen
todos.

* `alquileres.ver`: ver.
* `alquileres.gestionar`: altas y acciones.
* `alquileres.identidad`: documento, nacimiento, nacionalidad y dirección de inquilinos, avalistas, etc.
* `alquileres.solvencia`: ingresos y nóminas.
* `alquileres.contratos`: documentos y la pestaña Documentos.
* `alquileres.viajeros`: identidad de los viajeros.

La identidad, la solvencia y los valores de los documentos se guardan **cifrados** (`encrypted:array`).

## 9. Pruebas

* `php artisan clarto:alquileres-prueba`: transacción que se deshace al final; da PASS o FAIL por cada comprobación.
* Verificado en el contenedor `liberu-app` de staging, sobre una **copia** de la BD en `/tmp`:
  * `php -l` y carga de las 44 clases.
  * Migraciones y comando: 51/51.
  * Carrera real con 6 procesos simultáneos sobre la misma vivienda y su habitación: 1 confirmada y 5 rechazadas.
  * Renderizado HTTP 200 de las 6 pantallas y de las 6 pestañas con datos.
  * La BD viva no se ha tocado.

## 10. Pendiente: accesos, plantillas y verificaciones externas

**Accesos**
1. SES.HOSPEDAJES: alta del arrendador (certificado o Cl@ve), código de arrendador, código de establecimiento por
   vivienda, y usuario (NIF + «WS») y contraseña del servicio web (marcar «envío por servicio web» en el alta).
   Especificación WSDL/XSD: pedirla a ses.hospedajes@interior.es.
2. Canales: URL iCal de exportación de cada anuncio (Airbnb, Booking, Vrbo) y decidir la URL secreta pública del
   calendario de CLARTO (hoy el panel solo admite personal).
3. GVA fianzas: acceso de la empresa a la ATV (modelo 816) o banco colaborador (806). Hay que decidir si CLARTO
   deposita en nombre del arrendador.
4. WAHA, SMS y correo: los de sus ramas (para el adaptador de comunicaciones).

**Plantillas** (hoy son ficticias, `ficticio/*` en Ajustes de alquileres)
* Contrato de vivienda habitual, contrato de vivienda temporal (con cláusula de causa) y contrato de habitación.
* Anexo, inventario de entrada, acta de entrega de llaves, preaviso y acta de salida o documento de finalización
  (art. 36.7).
* Contrato de cesión VUT con referencia catastral.
* Mensajes de requerimiento de pago e instrucciones de *check-in*.

**Verificaciones externas**
1. Si SES.HOSPEDAJES se aplica a la vivienda temporal y a las habitaciones después del RDL 29/2026: consultar a
   Interior.
2. Alquileres de 11 a 31 días en la Comunitat Valenciana: revisión jurídica.
3. Seguimiento del RDL 29/2026, por si se tramita como proyecto de ley.
4. Mayoría de 3/5 de la comunidad de propietarios para VUT (LPH art. 17.12): leer el texto en el BOE.
5. Formato exacto del número VUT (`VT-000000-X`).
