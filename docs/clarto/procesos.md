# Procesos y conocimiento (rama `feat/procesos`)

Procedimientos documentados por puesto, con consulta, redacción, revisión y permisos. Diseño y contrato:
`orquestacion/procesos/diseno.md` y `modelo-datos.md` (fuera del repositorio). El asistente «Preguntar a CLARTO» y las
recomendaciones son de la rama `feat/procesos-asistente` (Agente 3) y se enganchan por `ProcessExtensions`.

## Qué hay

| Pantalla (panel `/admin/{empresa}/…`) | Clase | Quién |
|---|---|---|
| Procesos (`procesos`): buscador, «Procesos de mi puesto», favoritos, recientes, explorar por área, puesto y tarea | `App\Filament\Processes\Pages\ProcessHome` | personal de la empresa |
| Procedimiento (`procesos/{id}?v={versión}`): resumen, índice, pasos con lista personal, decisiones, diagrama, excepciones, herramientas, plazos, relacionados, versiones, «Comunicar una duda o mejora», modo paso a paso, acciones de gestión | `ProcessView` | quien puede leerlo (404 si no) |
| Editor del borrador (`procesos/{id}/editar`) | `ProcessEditor` | redactores, responsable, administración |
| Gestión de procesos (`gestion-procesos`) | `ProcessAdmin` | administración, revisores, aprobadores y responsables (lo suyo) |
| Áreas, Puestos, Personas y puestos (`procesos-areas`, `procesos-puestos`, `procesos-asignaciones`) | `App\Filament\Processes\Resources\*` | `procesos.administrar` o administración |

Grupo de menú «Procesos y conocimiento» justo después de «Inicio».

## Permisos

* Servicio único `App\Processes\ProcessAccess` (sin caché): listas, buscador, portada, enlaces directos, versiones y
  adjuntos. Los adjuntos se sirven solo por `GET filament/clarto/procesos/adjunto/{id}` (`clarto.procesos.adjunto`):
  404 sin acceso, `nosniff`, `Cache-Control: private`; disco privado, ruta por huella sha256.
* Permisos Spatie por empresa (migración `2026_11_27_000003`): `procesos.ver` (se da al rol `staff` de cada empresa),
  `procesos.redactar`, `procesos.revisar`, `procesos.aprobar`, `procesos.administrar`. Se asignan en «Roles». Los
  administradores de la empresa los tienen todos.
* Lectura: personal de la empresa (permiso `procesos.*`, puesto activo o administración); `restringido` = lista blanca
  por área, puesto o persona; `deny` por persona gana. Paso de gestión (responsable, administración, aprobar,
  administrar; revisar solo «en revisión»; el autor su borrador) solo en enlaces directos y gestión, nunca en el buscador.
* Doble control: el autor no publica su versión salvo administración («autoaprobado»), o con `procesos.aprobar` si la
  empresa lo desactiva en Ajustes.

## Comandos

* `php artisan clarto:procesos-demo [--team=ID] [--sin-ejemplos]`: mapa de procesos (`database/data/procesos-mapa.json`)
  y los 4 ejemplos «DEMOSTRACIÓN» (`database/data/procesos-demo.json`) con personas ficticias desactivadas
  (`*.demo@example.test`). Como aún no hay estructura real, solo crea las **5 áreas y 6 puestos que usan los ejemplos**,
  con el nombre terminado en «(DEMOSTRACIÓN)» y marcados como propuesta; los procesos quedan **sin responsable ni
  aprobador** y aparecen en «Gestión de procesos → Responsables sin asignar», donde «Asignar responsable y aprobador»
  los completa sin crear versión (también en la ficha, menú «Más»). Con `--sin-ejemplos` solo carga el mapa.
  Idempotente. Si existe `clarto:asistente-reindexar`, lo llama.
* `php artisan clarto:procesos-revisiones`: tarea diaria (07:00); tarea de TARMIO `revision_proceso` 30 días antes de
  la próxima revisión, al responsable.
* `php artisan clarto:procesos-prueba`: prueba completa en una transacción que se deshace (68 comprobaciones).

## Módulo

Manifiesto `app/Modules/manifests/clarto-procesos/module.json` (clave `procesos`, activo por defecto). Con el módulo
desactivado se ocultan crear, enviar, publicar y archivar; lo publicado se sigue leyendo. Ajustes por empresa en
`clarto_process_settings` («Gestión de procesos → Ajustes»).

## Notas técnicas

* Diagramas: subconjunto de Mermaid (`flowchart TD/LR`) dibujado como SVG en el servidor (`App\Processes\MermaidSvg`),
  sin JavaScript ni dependencias; lo que no entiende se muestra como código.
* Sin cambios en módulos (`modules/`): páginas y recursos se añaden al panel con `$app->resolving(PanelRegistry::class)`
  + `Livewire::component()`. Tras el build hay que regenerar la caché de componentes de Filament.
* Archivos compartidos tocados: `ClartoServiceProvider::register()` (bloque «Procesos»), `ImprovementRequestPolicy`
  (`view`/`update` también para el responsable del proceso vinculado), `lang/{es,en,tr}.json` (nombre del módulo) y
  `resources/manual/es.md` (sección nueva).
