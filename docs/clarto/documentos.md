# Documentos en las fichas

Los archivos se gestionan en la ficha a la que pertenecen. La lista global del módulo («Documentos y fotos») sale del
menú: solo los superadministradores la abren, como **Operación y diagnóstico → Archivo general** (auditoría).

## Dónde está

* **Inmueble → Fotos y vídeos** (`App\Filament\PropertyPhotos`): fotos, planos, vídeos subidos (MP4/WebM/MOV, 60 MB) y
  enlaces de YouTube/Vimeo, todo `MediaDocument` del módulo en disco público, ordenado; la primera foto es la portada.
  Un enlace es una fila `kind = video` con `path` = URL. La API del catálogo sigue leyendo solo `photo|floorplan|siteplan`.
* **Inmueble → Documentos** (`App\Filament\Documents\DocumentsUi::propertyTab`): documentos del inmueble por categoría
  y, debajo, los `MediaDocument` antiguos que no son galería ni vídeo («Documentos anteriores», solo lectura).
* **Cliente** (pestaña **Documentos** de la ficha 360; sección **Documentos** en la edición de cualquier contacto):
  documentos propios, «Generados en otros módulos» (`App\Documents\ClientFiles`: firmados y auditoría, informes de
  valoración, albaranes, documentos de alquiler, sobres del módulo) e «Inmuebles vinculados».

## Datos

Tabla host `clarto_documents` (migración `2026_11_26_000001`): `team_id`, `party_id` **o** `property_id`, `category`,
`title`, `disk` (`local`, privado), `path`, `original_name`, `mime`, `size`, `sha256`, `created_by`. Se eligió una
tabla propia en vez de un `party_id` en `real_estate_media_documents` porque esas filas son medios públicos que sirven
la API REST del módulo, su lista Livewire y la lista global; estos son privados con permisos propios. No se mueve
ningún dato existente.

## Reglas

* Tipo comprobado en el servidor sobre los bytes guardados (PDF, imágenes, Word/Excel/OpenDocument), máximo 20 MB.
* Descarga solo por `GET filament/clarto/documentos/{archivo|firma|firma-enviado|albaran}/{id}` (sesión, miembro del
  equipo del archivo y el permiso de su origen; si no, 404). Informes de valoración: su ruta `clarto.valoraciones.informe`.
* Categorías restringidas: DNI/NIE (`alquileres.identidad`), solvencia y certificado bancario (`alquileres.solvencia`);
  además las ven superadministradores y administradores (regla de Propietarios `OwnerProfile::canSeeSensitive`).
* Archivar documentos nuevos depende del módulo CLARTO «Documentos y formularios» (los existentes siguen visibles).
* Livewire admite subidas temporales de hasta 60 MB (PHP del contenedor: 64 MB).

## Prueba

`php artisan clarto:documentos-prueba` (transacción revertida, disco privado simulado).
