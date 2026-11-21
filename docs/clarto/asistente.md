# Preguntar a CLARTO y recomendaciones de documentación

«Preguntar a CLARTO» responde preguntas del equipo **solo con los procedimientos publicados que esa persona puede
leer**, con los pasos relevantes y enlaces al procedimiento y su versión. Las recomendaciones de documentación comparan
el mapa de procesos con lo registrado y con las preguntas y dudas del equipo.

Módulo «Preguntar a CLARTO» (`clarto-procesos-asistente`, clave `procesos_asistente`, **apagado por defecto**) en
Configuración de la plataforma → Módulos, más el interruptor por empresa en sus ajustes. Código en
`app/Processes/Assistant/`, `app/Processes/Recommendations/` y `app/Filament/Processes/Assistant/`; proveedor
`AssistantServiceProvider`, que registra `ClartoProcessesServiceProvider` (Agente 2) si existe. Migraciones
`2026_11_28_000001` y `000002`. Contrato: `orquestacion/procesos/modelo-datos.md` §5–§8. No toca `modules/` ni
archivos compartidos.

## Cómo responde

1. **Enmascara la pregunta**: DNI, NIE, CIF, IBAN, correos, teléfonos y nombres de las personas de la empresa pasan a
   `[DNI]`, `[IBAN]`, `[persona]`… Es lo único que se guarda en el registro.
2. **Recupera con el permiso ya aplicado.** La búsqueda (SQLite FTS5 sobre `clarto_process_chunks`) lleva en la misma
   consulta `process_version_id IN ProcessAccess::visibleQuery($persona)`: versiones vigentes, publicadas y que esa
   persona puede leer en ese momento (puesto, área, excepciones `allow`/`deny`, bajas). Nada más se lee ni se puntúa.
3. **Decide sin IA** cuándo no hay documentación (ningún procedimiento contiene al menos un tercio de los términos de
   la pregunta) y cuándo es **ambigua** (el segundo procedimiento puntúa ≥ 75 % del primero): en ese caso pregunta
   «¿A cuál se refiere?» con los procedimientos como botones. En los dos casos no se envía nada al proveedor.
4. **Envía al proveedor** (el configurado en «Inteligencia artificial», mediante `App\Ai\AiClient`, sin modificarlo)
   solo la pregunta enmascarada y los fragmentos del procedimiento elegido, con el límite de fragmentos y caracteres de
   la empresa. Nunca adjuntos, URL de descarga, nombres de usuario ni datos de clientes. Un proceso con
   `ai_excluded` no se envía: se cita solo su título.
5. **Cita desde el servidor**: las marcas `[F1]` del modelo se convierten en enlaces construidos aquí
   (`ProcessLinks::version($v, '#paso-…')`); las URL que escriba el modelo se eliminan. Cada vez que se muestra una
   respuesta (también en el historial) se vuelve a comprobar `ProcessAccess::canView`: si la persona ya no puede leer
   alguna versión citada, no ve ni la respuesta ni el enlace.
6. **Prácticas nuevas**: si el modelo propone algo que no está en los fragmentos (línea `PROPUESTA:`), no se presenta
   como norma: se crea una recomendación `practica_sugerida`, pendiente y marcada «Propuesta generada por IA».

«Enviar la duda al responsable» usa `ProcessFeedback::submit(..., source: 'asistente')`: crea la solicitud de mejora
(título `[Procesos] …`), la vincula al procedimiento citado si la persona aún puede leerlo, y no se duplica.

Valoración útil / no útil con comentario (enmascarado). «Consultas sin respuesta útil» = `sin_documentacion` o
valoración negativa; se ven, **sin el nombre de quien preguntó**, en Gestión de procesos → «Consultas sin respuesta
útil». El registro se borra a los 180 días (configurable por empresa).

## Índice

`clarto_process_chunks` guarda el texto plano de `ProcessVersion::sections()` (mismos anclajes que la página),
enmascarado y troceado en fragmentos de hasta 1 200 caracteres. La tabla virtual FTS5
`clarto_process_chunks_fts` (`unicode61 remove_diacritics 2`) se mantiene con disparadores. Sin FTS5 (u otro driver)
se busca con `LIKE` por términos. Los términos se reducen a prefijos sin acentos («comunicar» → `comunic*`).

- `ProcessVersionPublished` → borra los fragmentos del proceso e indexa la versión nueva.
- `ProcessArchived` → borra los fragmentos del proceso.
- `php artisan clarto:asistente-reindexar [--team=]`: reconstrucción idempotente desde los procesos publicados; se
  ejecuta cada día a las 03:10. Aunque un reindexado falle, la recuperación filtra por `visibleQuery`, así que una
  versión sustituida, archivada o restringida no se usa nunca.

Decisión: el reindexado completo lee `clarto_processes` publicados directamente y no el `visibleQuery` de un
administrador, porque `visibleQuery` no tiene paso de gestión y dejaría fuera los procesos restringidos.

## Recomendaciones de documentación

Gestión de procesos → «Recomendaciones» (solo `procesos.administrar` o administración de la empresa). Se recalculan
cada día a las 03:30 y con el botón «Actualizar recomendaciones».

| Tipo | Cuándo | Responsable sugerido |
|---|---|---|
| `mapa` | Entrada del mapa sin proceso registrado (ni por `map_entry_id`, ni por código). Prioridad de la entrada; «a validar» baja a 3. | Puesto responsable de la entrada y su titular principal |
| `area`, `sector` | Menos de la mitad de las entradas del mapa (≥ 3) publicadas en el área o la línea de negocio | Responsable del área |
| `puesto` | Puesto con personas y ningún procedimiento publicado que ejecutar | Responsable del área del puesto |
| `preguntas` | ≥ 2 consultas sin respuesta útil en 90 días sobre el mismo tema | El de la entrada del mapa que coincide, si hay |
| `dudas` | ≥ 2 dudas sobre un mismo proceso, o ≥ 2 dudas sin proceso sobre el mismo tema | Responsable del proceso |
| `practica_sugerida` | La crea el asistente | Responsable del proceso |

Acciones: **Aceptar**; **Descartar** con motivo obligatorio (no vuelve a aparecer: misma huella); **Proponer contenido
con IA** (solo envía título, área, disparador y pantallas de la entrada; queda «Propuesta generada por IA»);
**Abrir borrador** con `Processes::createDraft` y la plantilla de la entrada (o `startRevision` si es sobre un proceso
existente). Antes de abrirlo se buscan **procesos parecidos** por título; si los hay, hay que confirmar que se han
revisado. El borrador lleva `ai_assisted` cuando el contenido es de IA y la propuesta va en un aviso marcado.

## Ajustes por empresa

Gestión de procesos → Consultas → «Ajustes de Preguntar a CLARTO» (`/admin/{empresa}/preguntar-a-clarto/ajustes`):
activado, modelo (en blanco = modelo de redacción del panel), fragmentos (6) y caracteres (8 000) por consulta, días
de registro (180).

## Pruebas

`php artisan clarto:procesos-asistente-prueba` (alias `clarto:asistente-prueba`): empresa, personas y los cuatro
procedimientos de demostración (SIS-91, ALQ-91, ADF-91 restringido con la marca ZAFIRO-7, ALQ-92 v1/v2) dentro de una
transacción que se deshace, con el proveedor de IA simulado (`Http::fake`, peticiones reales prohibidas) y cada
petición inspeccionada. 52 comprobaciones, verificadas el 2026-11-21 en el contenedor de staging sobre una copia de la
base de datos en `/tmp`.

## Manual · «### Preguntar a CLARTO» (cuerpo de la subsección)

> En **Procesos y conocimiento → Preguntar a CLARTO** escriba su duda como se la haría a un compañero («¿en cuánto
> tiempo hay que comunicar los viajeros?»). La respuesta es breve, con los pasos que importan y el enlace al
> procedimiento y a su versión: ábralo para ver el detalle. Solo usa los procedimientos publicados que usted puede
> consultar.
>
> - Si su pregunta encaja con varios procedimientos, le preguntará a cuál se refiere.
> - Si no hay documentación, se lo dirá: pulse «Enviar la duda al responsable» y llegará como solicitud de mejora.
> - Valore cada respuesta (útil / no útil). Las que no sirven ayudan a decidir qué documentar.
> - No escriba datos de clientes: los DNI, IBAN, correos, teléfonos y nombres se ocultan antes de enviar nada al
>   proveedor de IA, pero es mejor no incluirlos.
> - Si el asistente sugiere una forma nueva de hacer algo, es una propuesta pendiente de aprobación, no una norma.
>
> Desde un procedimiento, el botón «Preguntar a CLARTO» pregunta dentro de ese procedimiento.
