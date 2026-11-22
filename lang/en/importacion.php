<?php

return [
    'section' => 'Property import',

    'fields' => [
        'source' => 'Where the properties come from',
        'feed_url' => 'Feed URL',
        'feed_format' => 'Feed format',
        'mapping' => 'Field mapping',
        'mapping_help' => 'Dot path inside each listing of the feed (e.g. «price» or «data.area»). «Listings» is where the listings are, from the XML root element (e.g. «listings.listing»).',
        'web_url' => 'Collaborator website: listing page or sitemap',
        'web_url_help' => 'A sitemap (…/sitemap.xml) is faster and needs fewer requests.',
        'web_pattern' => 'Listing page pattern (optional)',
        'web_pattern_help' => 'Use * as wildcard, e.g. /property/* or https://site.com/sale/*. Without a pattern every link of the same site is checked (up to the page cap).',
        'schedule' => 'Schedule',
        'authorized_at' => 'Date of the written authorization',
        'authorized_at_help' => 'Date on which the collaborator authorized CLARTO in writing to copy their listings and photos.',
        'authorization_doc' => 'Authorization document (optional)',
        'authorization_doc_help' => 'PDF or image. Stored privately; only CLARTO staff can download it.',
        'portals_allowed' => 'Can be published on external portals',
        'portals_allowed_help' => 'Lets staff send their properties to pisos.com and other portals (always as CLARTO properties, without their data) when selected. Off by default; the written authorization date is also required and must cover it.',
    ],

    'sources' => ['ninguno' => 'None', 'feed' => 'Feed', 'web' => 'Their website'],
    'statuses' => ['activo' => 'active at the source', 'retirado' => 'withdrawn from the source'],
    'formats' => ['kyero' => 'Kyero XML', 'xml' => 'Generic XML (mapped)', 'json' => 'Generic JSON (mapped)'],
    'schedules' => ['diario' => 'Daily (05:30)', 'manual' => 'Manual only'],

    'mapping' => [
        'elementos' => 'Listings', 'referencia' => 'Reference', 'precio' => 'Price', 'operacion' => 'Operation (sale/rent)',
        'tipo' => 'Type', 'm2' => 'Area (m²)', 'habitaciones' => 'Bedrooms', 'banos' => 'Bathrooms', 'municipio' => 'Town',
        'zona' => 'Area / zone', 'titulo' => 'Title', 'descripcion' => 'Description (text, or {"es": …, "en": …})', 'fotos' => 'Photos (list or comma-separated URLs)',
        'caracteristicas' => 'Features (list)', 'planos' => 'Floor plans', 'videos' => 'Videos',
    ],

    'extra' => [
        'title' => 'Data imported from the collaborator',
        'help' => 'What the original listing shows and has no field of its own on the record. Updated on every import.',
        'source' => 'Source',
        'last_seen' => 'Last seen',
        'reference' => 'Collaborator reference', 'url' => 'Original listing', 'usable' => 'Usable area',
        'plot' => 'Plot area', 'floor' => 'Floor', 'orientation' => 'Orientation', 'condition' => 'Condition',
        'community' => 'Community fees', 'ibi' => 'Property tax (IBI)', 'zone' => 'Zone', 'town' => 'Town', 'province' => 'Province',
        'descriptions' => 'Descriptions in other languages', 'videos' => 'Other videos', 'tours' => 'Other virtual tours',
        'original_title' => 'Collaborator\'s original title', 'original_description' => 'Collaborator\'s original description',
        'tour' => 'Collaborator\'s virtual tour (not published)',
    ],

    'origin' => [
        'heading' => 'Collaborator property: :name',
        'source' => 'source: :source',
        'reference' => 'collaborator ref.: :ref',
        'synced' => 'last sync: :date',
        'open' => 'Open the collaborator\'s record',
        'white_label' => 'On clarto.es it is published as a CLARTO property (white label): no collaborator name, reference or links.',
        'origins' => ['feed' => 'feed', 'web' => 'website', 'manual' => 'manual'],
        'review_texts' => 'Review texts: collaborator traces were removed (:items). The original is under «Data imported from the collaborator».',
        'review_tour' => 'Review the virtual tour: it is the collaborator\'s and is not published until staff put it on the record.',
        'review_photos' => 'Review photos (possible collaborator watermark)',
        'reviewed' => 'Mark as reviewed',
        'reviewed_help' => 'Confirm you reviewed texts, virtual tour and photos. The notices come back if an import brings something new.',
        'reviewed_done' => 'Review notices cleared.',
        'column' => 'Collaborator',
        'filter_owner' => 'Owner',
        'own' => 'CLARTO\'s own',
        'collaborator' => 'Collaborator\'s',
        'filter_collaborator' => 'Collaborator',
        'filter_source' => 'Import source',
    ],

    'review' => [
        'photos_metadata' => 'the image data mentions «:brand»',
        'photos_repeated' => 'the same mark appears in the same place on several photos',
    ],

    'gate' => [
        'title' => 'The collaborator\'s written authorization is required',
        'help' => 'Copying another agency\'s catalogue and photos needs their permission. Until the date of their written authorization is recorded here, no import runs, scheduled or manual.',
        'none_signed' => 'No agreement has been signed with any collaborator yet, so the import is ready but does not run.',
        'ok' => 'Authorization recorded. Imported properties come in as drafts and are not published on clarto.es until staff decide so on each record.',
        'refused' => 'Nothing is imported without the collaborator\'s written authorization. Record its date on the collaborator\'s record.',
    ],

    'actions' => [
        'now' => 'Import now',
        'now_help' => 'Queued; it starts within a minute. A website can take several minutes (one request every few seconds). Properties come in as drafts.',
        'requested' => 'Import requested: it starts within a minute.',
    ],

    'status' => [
        'never' => 'No import has run yet.',
        'requested' => '(Import requested, starts within a minute.)',
        'last' => 'Last import: :date — :status',
        'en_curso' => 'Running',
        'ok' => 'Successful',
        'parcial' => 'Partial',
        'error' => 'Error',
        'sin_autorizacion' => 'Not run: written authorization missing',
        'no_retire' => 'Incomplete read: no property was withdrawn in this run.',
    ],

    'counts' => [
        'leidos' => 'Read', 'nuevos' => 'New', 'actualizados' => 'Updated', 'sin_cambios' => 'Unchanged', 'retirados' => 'Withdrawn',
        'fotos' => 'New photos', 'fotos_quitadas' => 'Photos removed', 'errores' => 'Errors', 'paginas' => 'Requests', 'ia' => 'Read with AI',
    ],

    'errors' => [
        'unsafe_url' => 'Address not allowed: :url',
        'no_items' => 'The listings were not found in the feed (check «Listings»).',
        'bad_feed' => 'The feed is not valid XML.',
        'robots' => 'The site\'s robots.txt does not allow reading :url.',
        'page_cap' => 'Reached the cap of :n requests per run.',
        'ai_quota' => 'Free AI limit reached: remaining pages are read from their structured data only.',
        'too_big' => 'Page too large: :url',
        'inactive' => 'The collaborator is deactivated.',
        'not_configured' => 'The import is not configured (source and URL).',
    ],

    'note' => 'Imported from :name (:source) on :date. Collaborator reference: :ref. Original listing: :url. Review before publishing.',
    'title' => ':type in :town',
    'no_location' => 'Location pending (imported)',
    'types' => [
        'apartment' => 'Apartment', 'villa' => 'Villa', 'townhouse' => 'Townhouse', 'house' => 'House', 'commercial' => 'Commercial premises',
        'land' => 'Land', 'hmo' => 'Building', 'residential' => 'Property',
    ],
];
