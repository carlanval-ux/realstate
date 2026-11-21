<?php

return [
    'menu' => 'Correo entrante',
    'vacio' => 'No hay correos en esta bandeja',
    'sin_asunto' => '(sin asunto)',
    'sugerido' => 'Sugerido: :c',
    'confirmar_texto' => 'Se enviará como «:c».',
    'ver_html' => 'Ver la versión con formato',
    'adjunto_omitido' => 'no guardado (más de 20 MB)',
    'bandeja' => ['revisar' => 'Pendiente de revisar', 'entrada' => 'Entrada', 'enrutado' => 'Enrutados', 'archivo' => 'Archivo'],
    'categoria' => [
        'solicitud' => 'Respuesta a solicitud de inmueble', 'propietario' => 'Propietario / valoración', 'colaborador' => 'Colaborador',
        'cliente' => 'Cliente con expediente', 'consulta' => 'Consulta nueva', 'proveedor' => 'Proveedor / factura',
        'administracion' => 'Administración / notificación oficial', 'publicidad' => 'Publicidad / spam', 'otros' => 'Otros',
        'automatico' => 'Automático (acuse, rebote, ausencia)',
    ],
    'area' => [
        'inmobiliaria' => 'Inmobiliaria', 'alquiler' => 'Alquiler', 'gestoria' => 'Gestoría', 'mercantil' => 'Mercantil',
        'extranjeria' => 'Extranjería', 'fiscal' => 'Fiscal', 'otros' => 'Otros', 'proveedor' => 'Proveedores / facturas', 'administracion' => 'Notificaciones oficiales',
    ],
    'metodo' => ['hilo' => 'Respuesta a un envío nuestro', 'referencia' => 'Referencia', 'regla' => 'Regla', 'remitente' => 'Remitente conocido', 'cabeceras' => 'Cabeceras', 'ia' => 'IA', 'manual' => 'Equipo'],
    'motivo' => [
        'conflicto' => 'Señales contradictorias', 'sin_ia' => 'Sin regla y con la IA desactivada', 'ia_no_disponible' => 'La IA no respondió',
        'ia_sin_respuesta' => 'La IA no dio una categoría válida', 'sin_expediente' => 'No se encontró el expediente o el contacto',
        'baja_confianza' => 'Confianza baja', 'solicitud_sin_vincular' => 'No se pudo vincular a una solicitud',
        'solicitudes_desactivado' => 'El módulo Solicitudes está desactivado', 'error' => 'Error al procesarlo',
    ],
    'ruta' => [
        'solicitud_vinculada' => 'Vinculada a la solicitud', 'solicitud_pendiente' => 'En «Respuestas por vincular»', 'solicitud_acuse' => 'Acuse en la solicitud',
        'solicitud_fuera_plazo' => 'Solicitud cerrada (en el historial)', 'solicitud_duplicada' => 'Ya recibida', 'cronologia' => 'En la cronología del contacto',
        'entrada' => 'Entrada', 'archivo' => 'Archivado',
    ],
    'campo' => [
        'recibido' => 'Recibido', 'de' => 'De', 'para' => 'Para', 'fecha' => 'Fecha', 'asunto' => 'Asunto', 'categoria' => 'Categoría', 'area' => 'Área',
        'como' => 'Clasificado por', 'contacto' => 'Contacto', 'regla' => 'Recordar para próximos correos', 'adjuntos' => 'Adjuntos',
    ],
    'regla' => ['ninguna' => 'No recordar', 'remitente' => 'Este remitente', 'dominio' => 'Todo su dominio'],
    'ayuda' => [
        'contacto' => 'Vacío: el contacto con esta dirección de correo.',
        'regla' => 'Los próximos correos de este remitente (o dominio) se clasificarán así sin preguntar.',
    ],
    'accion' => ['ver' => 'Ver', 'confirmar' => 'Confirmar', 'clasificar' => 'Clasificar', 'reclasificar' => 'Reclasificar', 'atendido' => 'Atendido'],
    'aviso' => ['hecho' => 'Hecho', 'guardado' => 'Ajustes guardados'],
    'error' => [
        'sin_sugerencia' => 'No hay sugerencia que confirmar.', 'categoria' => 'Categoría o área no válida.', 'contacto' => 'Ese contacto no es de esta empresa.',
        'ya_enrutado' => 'Ya está en una solicitud o en la cronología de un contacto.', 'dominio_publico' => 'Es un dominio de correo público: la regla solo puede ser para este remitente.',
        'otra_empresa' => 'Otra empresa ya lee este buzón: solo una puede tener el sondeo activo.',
    ],
    'modulo' => ['falta_buzon' => 'Falta el buzón: el administrador del servidor debe ejecutar scripts/set-imap.sh con la contraseña de info@.'],
    'sondeo' => [
        'ok' => 'Leído: :n correos nuevos', 'error' => 'Error al leer el buzón: :e', 'esperando' => 'Aún no toca (intervalo)', 'en_curso' => 'Ya hay una lectura en curso',
        'sin_buzon' => 'Falta configurar el buzón', 'sin_sondeo' => 'El sondeo está desactivado', 'varias_empresas' => 'Varias empresas con el sondeo activo',
        'modulo_desactivado' => 'El módulo Correo entrante está desactivado',
    ],
    'ajustes' => [
        'titulo' => 'Ajustes del correo entrante', 'buzon' => 'Buzón', 'funcionamiento' => 'Funcionamiento',
        'buzon_ok' => 'Buzón :buzon en :host (contraseña guardada solo en el servidor).',
        'ultimo' => 'Última lectura: :fecha · :n nuevos', 'nunca' => 'Aún no se ha leído el buzón.', 'error' => 'último error: :e',
        'solo_lectura' => 'Solo lectura: el panel nunca marca como leído, mueve, borra ni responde correos del buzón. La primera lectura empieza por los correos que lleguen desde ese momento.',
        'polling' => 'Leer el buzón', 'polling_ayuda' => 'Solo una empresa puede tenerlo activo.', 'interval' => 'Cada (minutos)',
        'ai' => 'Clasificar con IA lo que no resuelvan las reglas', 'ai_ayuda' => 'A la IA solo se envían el asunto y un extracto breve sin datos personales; nunca adjuntos.',
        'retention' => 'Conservar copias (días)', 'retention_ayuda' => 'Por defecto 24 meses, como la política de privacidad. Publicidad y automáticos: 30 días.',
        'responsables' => 'Responsable de cada área', 'responsables_ayuda' => 'Correo del usuario de TARMIO que recibe la tarea de cada entrada. Vacío: el de «Otros» o el propietario de la empresa.',
        'probar' => 'Probar conexión', 'probar_ok' => 'Conexión correcta: :n correos en la bandeja (no se ha modificado nada).', 'probar_error' => 'No se pudo conectar: :e',
        'sondear' => 'Leer ahora',
    ],
];
