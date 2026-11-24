# Incidencias y mejoras (antes «Solicitud de mejoras»)

Foro interno del equipo (menú **Procesos y conocimiento → Incidencias y mejoras** (primera entrada), paneles `admin` y `app`, ruta `…/solicitud-mejoras`,
que se mantiene para no romper enlaces) para comunicar **incidencias** y pedir **mejoras** o **modificaciones** de la
herramienta, con texto y fotos.

## Cómo está hecho

| Pieza | Dónde |
|---|---|
| Tablas `clarto_improvement_requests` (empresa, autor, **tipo**, título, descripción, adjuntos JSON, estado) y `clarto_improvement_request_events` (quién, cuándo, estado de → a, respuesta, **fotos JSON**) | `database/migrations/2026_11_23_000001_*` y `2026_12_09_000001_*` (aditiva) |
| Tabla `notifications` de Laravel (avisos del panel) | `2026_12_09_000001_*` (solo si no existe) |
| Reglas: alta, respuesta con fotos, cambio de estado, validación de imágenes, visibilidad, avisos | `App\Models\ImprovementRequest` (`submit`, `reply`, `respond`, `scopeVisibleTo`) |
| Permisos: `view`/`reply` todo el equipo de la empresa; `update` (estado) solo administración; nadie borra | `App\Policies\ImprovementRequestPolicy` |
| Lista (tipo, título, autor, estado, nº de respuestas, última actividad; filtros tipo/estado/autor; búsqueda en título y descripción) y «Nuevo tema» | `App\Filament\Resources\ImprovementRequestResource` + `Pages\ManageImprovementRequests` |
| Página del tema (conversación con fotos en miniatura; «Responder» con texto + fotos y, para administración, estado) | `Pages\ViewImprovementRequest` |
| Fotos privadas | ruta `clarto.mejoras.adjunto` (`routes/clarto-mejoras.php`, `ImprovementAttachmentController`); las de una respuesta llevan `?respuesta={evento}` |
| Registro sin tocar módulos | bloque «Solicitud de mejoras» de `ClartoServiceProvider` (`register()` añade el recurso a los paneles `admin` y `app` —nunca al de colaboradores— y activa `databaseNotifications()`; `boot()` política y ruta) |

- **Tipos**: `incidencia` Incidencia · `mejora` Mejora · `modificacion` Modificación. Las solicitudes que ya existían
  quedan como **Mejora** (valor por defecto de la columna).
- **Estados**: `received` Recibida · `in_review` En revisión · `planned` Planificada · `implemented` Implementada ·
  `discarded` Descartada. Cada respuesta y cada cambio de estado es una fila del historial.
- **Quién ve qué**: cualquier miembro de la empresa (equipo Jetstream) ve y responde todos los temas de su empresa; nunca
  los de otra. Excepción: las **dudas de Procesos** («Comunicar una duda o mejora» y «Enviar la duda al responsable»
  del asistente, enlazadas en `clarto_process_feedback`) siguen siendo privadas entre su autor, el responsable del
  proceso y la administración. Las cuentas de colaborador nunca ven el foro.
- **Administrador de la empresa** = rol `admin` o `super_admin` de Spatie **en ese equipo** (`model_has_roles.team_id`),
  o propietario Jetstream del equipo. Solo ellos (y el responsable del proceso en una duda de Procesos) cambian el
  estado. El `super_admin` además pasa todas las políticas por el `Gate::before` de Shield.
- **Empresa** = inquilino de Filament en `/admin`, `current_team_id` en `/app`. En `/admin` la lista además se limita al
  inquilino activo. El filtro de autor solo ofrece autores de temas visibles (no lista personas de otras empresas).
- **Orden**: última actividad primero (`updated_at`, que se actualiza con cada respuesta). **Nº de respuestas** = entradas
  del historial con texto o fotos (no cuentan el alta ni los cambios de estado sin texto).
- **Fotos** (tema y respuestas): `FileUpload` múltiple con vista previa y quitar antes de publicar, disco privado
  `local` (`storage/app/private`). Se suben a `mejoras/subidas/{usuario}` y al publicar se validan **en el servidor**
  (tipo por contenido con finfo —no por extensión— JPG/PNG/WebP, ≤ 10 MB, ≤ 10 archivos, `UploadPolicy` del módulo
  Files) y se mueven a `mejoras/{empresa}/{tema}/` (las de una respuesta, con prefijo `r{evento}-`). Solo se aceptan
  rutas de la carpeta de subida del propio usuario. Se sirven `inline` con su tipo y `nosniff`, tras
  `ImprovementRequestPolicy::view`. Las miniaturas abren la foto a tamaño completo en otra pestaña.
- **Avisos**: notificación de base de datos de Filament (campana del panel) al autor del tema y a quien haya
  participado, menos a quien responde y a quien ya no pueda verlo, en cada respuesta y cambio de estado. Se guardan al
  momento (`notifyNow`, sin cola). El enlace lleva a `/admin/{empresa}/…` para el personal y a `/app/…` para el resto.
  Sin correo ni WhatsApp.

## Prueba

```bash
php artisan clarto:mejoras-prueba
```

33 comprobaciones con empresas y usuarios ficticios en una transacción que se deshace y disco privado simulado: alta
con 3 capturas y tipos, tipo falso y más de 10 MB rechazados, archivo ajeno rechazado, respuesta y cambios de estado
con historial, foro (toda la empresa ve y responde; otra empresa no ve, no lista ni responde; dudas de Procesos
privadas), respuesta de un compañero con 2 fotos (PNG y WebP) y con foto falsa, solo la administración cambia el
estado, orden y nº de respuestas, avisos en el panel, y la ruta de las fotos (tema y respuesta: empresa 200, otra
empresa 403, sin sesión ≠ 200, respuesta pedida por otro tema 404).

## Pendiente / fuera de alcance

- No se puede editar una publicación después de enviarla (se corrige respondiendo). Si hace falta, añadir edición del
  propio texto durante unos minutos.
- Sin avisos por correo ni WhatsApp (decisión: solo campana del panel). Sin aviso al abrir un tema nuevo.
- Los archivos subidos y no publicados (formulario abandonado) quedan en `mejoras/subidas/`; si crece, limpiar con una
  tarea programada.
