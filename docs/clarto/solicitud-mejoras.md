# Solicitud de mejoras

Apartado interno del panel (menú **Inicio → Solicitud de mejoras**, paneles `admin` y `app`, ruta `…/solicitud-mejoras`)
para que el equipo proponga mejoras con capturas y la administración responda.

## Cómo está hecho

| Pieza | Dónde |
|---|---|
| Tablas `clarto_improvement_requests` (empresa, autor, título, descripción, adjuntos JSON, estado) y `clarto_improvement_request_events` (quién, cuándo, estado de → a, respuesta) | `database/migrations/2026_11_23_000001_*` |
| Reglas: envío, validación de imágenes, respuesta, visibilidad | `App\Models\ImprovementRequest` |
| Permisos: autor ve la suya; admin de la empresa ve y responde todas; nadie borra | `App\Policies\ImprovementRequestPolicy` |
| Pantalla (lista, «Nueva solicitud», Ver con historial, Responder) | `App\Filament\Resources\ImprovementRequestResource` |
| Imágenes privadas | ruta `clarto.mejoras.adjunto` (`routes/clarto-mejoras.php`, `ImprovementAttachmentController`) |
| Registro sin tocar módulos | bloque «Solicitud de mejoras» de `ClartoServiceProvider` (`register()` añade el recurso a todos los paneles; `boot()` política y ruta) |

- **Estados**: `received` Recibida · `in_review` En revisión · `planned` Planificada · `implemented` Implementada ·
  `discarded` Descartada. Cada cambio y cada respuesta es una fila del historial.
- **Administrador de la empresa** = rol `admin` o `super_admin` de Spatie **en ese equipo** (`model_has_roles.team_id`),
  o propietario Jetstream del equipo. El `super_admin` además pasa todas las políticas por el `Gate::before` de Shield.
- **Empresa** = inquilino de Filament en `/admin`, `current_team_id` en `/app`. En `/admin` la lista además se limita al
  inquilino activo.
- **Adjuntos**: `FileUpload` múltiple con vista previa y quitar antes de enviar (patrón de `PropertyPhotos`), disco
  privado `local` (`storage/app/private`). Se suben a `mejoras/subidas/{usuario}` y al enviar se validan **en el servidor**
  (tipo por contenido con finfo —no por extensión— JPG/PNG/WebP, ≤ 10 MB, ≤ 10 archivos, `UploadPolicy` del módulo
  Files) y se mueven a `mejoras/{empresa}/{solicitud}/`. Solo se aceptan rutas de la carpeta de subida del propio
  usuario. Se sirven `inline` con su tipo y `nosniff`, tras `ImprovementRequestPolicy::view`.

## Prueba

```bash
php artisan clarto:mejoras-prueba
```

20 comprobaciones con empresas y usuarios ficticios en una transacción que se deshace y disco privado simulado: envío
con 3 capturas, tipo falso y más de 10 MB rechazados, archivo ajeno rechazado, respuesta y cambios de estado con
historial, quién ve qué, y la ruta del adjunto (autora 200, admin 200, otra empresa 403, sin sesión ≠ 200).

## Pendiente / fuera de alcance

- Sin avisos por correo al responder (el usuario lo ve en el panel).
- Los archivos subidos y no enviados (formulario abandonado) quedan en `mejoras/subidas/`; si crece, limpiar con una
  tarea programada.
