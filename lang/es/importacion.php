<?php

return [
    'section' => 'Importación de inmuebles',

    'fields' => [
        'source' => 'Origen de los inmuebles',
        'feed_url' => 'URL del feed',
        'feed_format' => 'Formato del feed',
        'mapping' => 'Correspondencia de campos',
        'mapping_help' => 'Ruta con puntos dentro de cada inmueble del feed (p. ej. «precio» o «datos.superficie»). «Lista de inmuebles» es dónde están los inmuebles, contando desde el elemento raíz del XML (p. ej. «inmuebles.inmueble»).',
        'web_url' => 'Web del colaborador: página de listado o sitemap',
        'web_url_help' => 'Mejor el sitemap (…/sitemap.xml): se recorre más rápido y con menos peticiones.',
        'web_pattern' => 'Patrón de las fichas de inmueble (opcional)',
        'web_pattern_help' => 'Usa * como comodín, p. ej. /inmueble/* o https://web.com/venta/*. Sin patrón se revisan todos los enlaces de la misma web (hasta el límite de páginas).',
        'schedule' => 'Programación',
        'authorized_at' => 'Fecha de la autorización por escrito',
        'authorized_at_help' => 'Fecha en que el colaborador autorizó por escrito a CLARTO a copiar sus inmuebles y fotos.',
        'authorization_doc' => 'Documento de la autorización (opcional)',
        'authorization_doc_help' => 'PDF o imagen. Se guarda en el almacenamiento privado; solo el personal de CLARTO puede descargarlo.',
    ],

    'sources' => ['ninguno' => 'Ninguno', 'feed' => 'Feed', 'web' => 'Su página web'],
    'statuses' => ['activo' => 'activo en el origen', 'retirado' => 'retirado del origen'],
    'formats' => ['kyero' => 'Kyero XML', 'xml' => 'XML genérico (con correspondencia)', 'json' => 'JSON genérico (con correspondencia)'],
    'schedules' => ['diario' => 'Diaria (05:30)', 'manual' => 'Solo manual'],

    'mapping' => [
        'elementos' => 'Lista de inmuebles', 'referencia' => 'Referencia', 'precio' => 'Precio', 'operacion' => 'Operación (venta/alquiler)',
        'tipo' => 'Tipo', 'm2' => 'Superficie (m²)', 'habitaciones' => 'Habitaciones', 'banos' => 'Baños', 'municipio' => 'Municipio',
        'zona' => 'Zona', 'titulo' => 'Título', 'descripcion' => 'Descripción (texto, o {"es": …, "en": …})', 'fotos' => 'Fotos (lista o URLs separadas por comas)',
        'caracteristicas' => 'Características (lista)', 'planos' => 'Planos', 'videos' => 'Vídeos',
    ],

    'extra' => [
        'title' => 'Datos importados del colaborador',
        'help' => 'Lo que muestra el anuncio original y no tiene campo propio en la ficha. Se actualiza en cada importación.',
        'source' => 'Origen',
        'last_seen' => 'Visto por última vez',
        'reference' => 'Referencia del colaborador', 'url' => 'Anuncio original', 'usable' => 'Superficie útil',
        'plot' => 'Superficie de parcela', 'floor' => 'Planta', 'orientation' => 'Orientación', 'condition' => 'Estado de conservación',
        'community' => 'Gastos de comunidad', 'ibi' => 'IBI', 'zone' => 'Zona', 'town' => 'Municipio', 'province' => 'Provincia',
        'descriptions' => 'Descripciones en otros idiomas', 'videos' => 'Otros vídeos', 'tours' => 'Otras visitas virtuales',
        'original_title' => 'Título original del colaborador', 'original_description' => 'Descripción original del colaborador',
        'tour' => 'Visita virtual del colaborador (no publicada)',
    ],

    'origin' => [
        'heading' => 'Inmueble de colaborador: :name',
        'source' => 'origen: :source',
        'reference' => 'ref. del colaborador: :ref',
        'synced' => 'última sincronización: :date',
        'open' => 'Abrir la ficha del colaborador',
        'white_label' => 'En clarto.es se publica como inmueble de CLARTO (marca blanca): sin nombre, referencia ni enlaces del colaborador.',
        'origins' => ['feed' => 'feed', 'web' => 'web', 'manual' => 'manual'],
        'review_texts' => 'Revisar textos: se quitaron rastros del colaborador (:items). El original está en «Datos importados del colaborador».',
        'review_tour' => 'Revisar la visita virtual: es del colaborador y no se publica hasta que el equipo la ponga en la ficha.',
        'review_photos' => 'Revisar fotos (posible marca de agua del colaborador)',
        'reviewed' => 'Marcar como revisado',
        'reviewed_help' => 'Confirma que has revisado textos, visita virtual y fotos. Los avisos vuelven si una importación trae algo nuevo.',
        'reviewed_done' => 'Avisos de revisión quitados.',
        'column' => 'Colaborador',
        'filter_owner' => 'Origen',
        'own' => 'Propio de CLARTO',
        'collaborator' => 'De colaborador',
        'filter_collaborator' => 'Colaborador',
        'filter_source' => 'Origen de importación',
    ],

    'review' => [
        'photos_metadata' => 'los datos de la imagen mencionan «:brand»',
        'photos_repeated' => 'la misma marca aparece en el mismo sitio de varias fotos',
    ],

    'gate' => [
        'title' => 'Hace falta la autorización por escrito del colaborador',
        'help' => 'Copiar el catálogo y las fotos de otra agencia requiere su permiso. Mientras no se registre aquí la fecha de su autorización por escrito, no se ejecuta ninguna importación, ni programada ni manual.',
        'none_signed' => 'Por ahora no hay ningún acuerdo firmado con colaboradores, así que la importación está preparada pero no se ejecuta.',
        'ok' => 'Autorización registrada. Los inmuebles importados entran como borrador y no se publican en clarto.es hasta que el equipo lo decida en cada ficha.',
        'refused' => 'Sin autorización por escrito del colaborador no se importa nada. Registra la fecha de la autorización en la ficha.',
    ],

    'actions' => [
        'now' => 'Importar ahora',
        'now_help' => 'Se pone en cola y empieza en menos de un minuto. Una web puede tardar varios minutos (una petición cada pocos segundos). Los inmuebles entran como borrador.',
        'requested' => 'Importación solicitada: empezará en menos de un minuto.',
    ],

    'status' => [
        'never' => 'Aún no se ha ejecutado ninguna importación.',
        'requested' => '(Importación solicitada, empieza en menos de un minuto.)',
        'last' => 'Última importación: :date — :status',
        'en_curso' => 'En curso',
        'ok' => 'Correcta',
        'parcial' => 'Parcial',
        'error' => 'Error',
        'sin_autorizacion' => 'No ejecutada: falta la autorización por escrito',
        'no_retire' => 'Lectura incompleta: no se ha retirado ningún inmueble en esta ejecución.',
    ],

    'counts' => [
        'leidos' => 'Leídos', 'nuevos' => 'Nuevos', 'actualizados' => 'Actualizados', 'sin_cambios' => 'Sin cambios', 'retirados' => 'Retirados',
        'fotos' => 'Fotos nuevas', 'fotos_quitadas' => 'Fotos quitadas', 'errores' => 'Errores', 'paginas' => 'Peticiones', 'ia' => 'Leídos con IA',
    ],

    'errors' => [
        'unsafe_url' => 'Dirección no permitida: :url',
        'no_items' => 'No se encuentra la lista de inmuebles en el feed (revisa «Lista de inmuebles»).',
        'bad_feed' => 'El feed no es un XML válido.',
        'robots' => 'El robots.txt de la web no permite leer :url.',
        'page_cap' => 'Se ha alcanzado el límite de :n peticiones por ejecución.',
        'ai_quota' => 'Límite de la IA gratuita alcanzado: el resto de páginas se leen solo con sus datos estructurados.',
        'too_big' => 'Página demasiado grande: :url',
        'inactive' => 'El colaborador está dado de baja.',
        'not_configured' => 'La importación no está configurada (origen y URL).',
    ],

    'note' => 'Importado de :name (:source) el :date. Referencia del colaborador: :ref. Anuncio original: :url. Revisar antes de publicar.',
    'title' => ':type en :town',
    'no_location' => 'Ubicación pendiente (importado)',
    'types' => [
        'apartment' => 'Piso', 'villa' => 'Chalet', 'townhouse' => 'Adosado', 'house' => 'Casa', 'commercial' => 'Local',
        'land' => 'Terreno', 'hmo' => 'Edificio', 'residential' => 'Inmueble',
    ],
];
