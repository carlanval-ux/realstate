# Módulos de CLARTO en «Módulos»

Rama `feat/modulos-clarto` (sobre `feat/captacion-ghl`). Ajustes de la plataforma → **Módulos** (`/admin/{equipo}/modulos`).

## Qué aparece y en qué estado

Estado comprobado el 2026-11-21 con la base de datos del panel (copia), sin desplegar:

| Módulo | Nombre en el panel | Versión | Estado hoy | ¿Funciona de punta a punta? |
|---|---|---|---|---|
| `clarto-idiomas` | Idiomas y traducción | 1.0.0 | Activado | **Sí.** Textos públicos es/en/tr, redacción y traducción con IA, revisión. Sin clave de IA pasa a «Pendiente de configurar» con enlace a Inteligencia artificial. |
| `clarto-documentos` | Documentos y formularios | 1.0.0 | Activado | **Parcial.** Los documentos del paquete oficial `real-estate-media-and-documents` (certificados, folletos, plantillas y versiones) funcionan y quedan bajo el interruptor. **Los formularios están pendientes** de la rama `feat/formularios-firma`. |
| `clarto-firma` | Firma electrónica | 0.1.0 | Desactivado; al activarlo, **pendiente de configurar** hasta que el servidor tenga DocuSeal | **Sí, en simulación.** Envío a firma, SMS y sobres del paquete oficial funcionan con proveedores simulados; las firmas reales necesitan DocuSeal (`FIRMA_DRIVER=docuseal`, clave y secreto del webhook) y los SMS, Red Telecom (`formularios-firma.md`). |
| `clarto-whatsapp` | WhatsApp | 0.1.0 | Activado con la línea vinculada; si no, **pendiente de configurar** (falta WAHA o escanear el QR) | **Sí.** Línea WAHA por equipo, envíos desde fichas y expedientes, avisos a colaboradores y nutrición (`whatsapp.md`). |
| `clarto-pisos` | Portal pisos.com | 1.0.0 | Pendiente de configurar | **Sí, en simulación.** Los envíos reales esperan la pasarela y las credenciales de pisos.com (`docs/clarto/pisos-com.md`). |
| `clarto-captacion` | Captación GoHighLevel | 1.0.0 | Pendiente de configurar | **Sí, con GHL simulado** (`clarto:ghl-prueba`). En el servidor faltan `GHL_API_TOKEN`, `GHL_LOCATION_ID`, `GHL_PIPELINE_ID` y `GHL_WEBHOOK_SECRET`. |

Estar en la lista no significa estar implementado: un módulo sin implementación en la versión desplegada se muestra como **«Pendiente de implementar»**, aunque esté activado.

Valores por defecto (los del manifiesto, como en Liberu): idiomas, documentos, pisos.com y captación vienen **activados** (es lo que ya funcionaba, así que nada cambia al desplegar); firma y WhatsApp vienen **desactivados**, porque necesitan cuentas externas, igual que los adaptadores oficiales de Google Analytics, Meta y MyMemory.

## Por qué «Módulos» estaba vacío

`Liberu\Foundation\ModuleManager\ModuleManagement::modules()` solo lista manifiestos que cumplen dos condiciones:

1. declaran `"module_type": "feature"` (sin esa clave, un módulo es «infraestructura»), y
2. pertenecen a un paquete Composer instalado (`InstalledVersions::isInstalled`).

Ninguno de los 196 manifiestos oficiales declara `feature`: todos son infraestructura y se cargan por configuración (`default_enabled` del manifiesto, más `MODULES_ENABLED` / `MODULES_DISABLED`). Las decisiones del administrador se guardan en la tabla `module_activations` (una fila por módulo) y se aplican en el arranque con `ModuleActivations::apply()`. Quién gestiona módulos lo decide la habilidad `manageModules`, definida en `AdminPanelProvider` (administradores).

## Cómo se registran (y por qué así)

Se descartaron dos opciones:

* **Ampliar la página del paquete.** `ModuleManagement` (servicio y página) son clases `final` y exigen un paquete Composer instalado. `module-manager` y `module-manager-filament` vienen de Packagist y no están en el repositorio (`.gitignore`), así que no pueden recibir un parche mínimo de forma fiable.
* **Paquetes Composer locales (`path`).** Habría que cambiar `composer.json` y `composer.lock`, cambiar la construcción de la imagen (que hace `composer install` desde el lock) y crear paquetes vacíos cuyo código seguiría en `app/`. Serían paquetes falsos.

**Elegido:** CLARTO aporta su propia fuente de manifiestos y una página «Módulos» del host que reutiliza lo que ofrece el gestor:

* **Manifiestos Liberu de verdad**: `app/Modules/manifests/clarto-*/module.json`, leídos y validados con la propia clase `Liberu\Foundation\ModuleManager\Manifest` (`module_type: feature`, `requires.packages` con los paquetes oficiales en los que se apoyan, `capabilities`, `features`, `default_enabled`). La versión que se muestra es la del manifiesto.
* **Misma tabla de activaciones**: `module_activations`, con el nombre del manifiesto (`clarto-whatsapp`…). `ModuleRegistry::resolve()` ignora los nombres que no conoce, así que estas filas no afectan al arranque de los módulos oficiales. `MODULES_ENABLED` y `MODULES_DISABLED` funcionan igual que con los módulos oficiales (`MODULES_DISABLED=clarto-whatsapp` lo apaga y bloquea el interruptor). **No hay migraciones nuevas.**
* **Dependencias reales**: si un paquete oficial requerido falta, no cumple la versión o no está cargado (`ModuleInventory`), el módulo aparece como «Con errores» y no se puede activar.
* **Página del host** `App\Filament\Pages\Modules`: lista los módulos *feature* oficiales con el mismo servicio `ModuleManagement` (hoy no hay ninguno) y los de CLARTO, con nombre, versión, estado, nota y los botones **Activar**, **Desactivar** y **Configurar** (este último solo en «Pendiente de configurar» y si existe una página de ajustes). Sustituye a la página del paquete: se deniega `manageModules` (los administradores no la abren) y su entrada de menú se oculta para todos, porque Shield deja pasar a los superadministradores por cualquier habilidad. La página del paquete sigue accesible por URL para un superadministrador; no se pierde nada, porque lista los mismos módulos oficiales.
* **Permisos**: solo administradores (`manageClartoModules`, la misma regla que `manageModules`). La entrada de menú, como antes «Modules», solo se muestra a superadministradores (`ClartoNavigation::TECHNICAL_PATHS`).

La configuración operativa (claves, buzón, sesiones) sigue en las páginas de ajustes de cada función. «Módulos» solo activa, desactiva e indica qué falta.

## API para las demás ramas

```php
use App\Modules\ClartoModules;

ClartoModules::enabled('whatsapp');        // bool: ¿puede empezar una operación nueva?
ClartoModules::status('whatsapp');         // App\Modules\ModuleStatus (Activado, Pendiente de configurar, …)
ClartoModules::ensureEnabled('whatsapp');  // lanza App\Modules\ModuleDisabled (HTTP 403 con mensaje claro)
ClartoModules::refusal('whatsapp');        // ?string: el mismo mensaje, para una notificación o una respuesta JSON
```

Claves: `firma`, `documentos`, `whatsapp`, `idiomas`, `pisos`, `captacion` (constantes `ClartoModules::FIRMA`…).

Cada implementación se anuncia una vez, desde un service provider; mientras no lo haga, el módulo aparece como «Pendiente de implementar»:

```php
ClartoModules::implement(ClartoModules::WHATSAPP,
    missingConfiguration: fn (): ?string => $sesionWahaConectada ? null : __('…qué falta…'),
    settingsUrl: fn (): ?string => \App\Filament\Pages\WhatsAppSettings::getUrl(),
);
```

Pauta para que desactivar no borre nada: comprobar `enabled()` o `ensureEnabled()` **al iniciar una operación nueva** (enviar, crear, firmar, publicar o aceptar un webhook) y ocultar esas acciones en la interfaz. Lo existente (historial, mensajes, documentos, sobres firmados) se sigue mostrando. Un webhook de un módulo desactivado responde 4xx con el mensaje, no 5xx, para que el emisor no reintente sin fin.

## Qué bloquea cada módulo al desactivarlo (y qué conserva)

| Módulo | Operaciones nuevas que se rechazan | Lo que se conserva y sigue visible |
|---|---|---|
| Idiomas y traducción | Botones de IA ocultos y `PropertyTextWriter` sin llamar a la IA; no se crean ni modifican traducciones en/tr ni borradores (guarda a nivel de modelo, solo si hay cambios); las pestañas en/tr pasan a solo lectura. | Traducciones aprobadas y borradores (se muestran y la web los sigue sirviendo); descartar borradores; el texto en castellano del inmueble. |
| Documentos y formularios | Crear documentos que no son galería (cualquier `kind` salvo foto, plano, plano de situación o vídeo), plantillas y versiones del paquete oficial: panel y API. | Todos los documentos existentes; **fotos y planos siguen funcionando** (no son documentos). |
| Firma electrónica | Crear sobres, firmas de sobre y firmas de documento del paquete oficial (panel y API `…/envelopes`, `…/sign`). | Sobres y firmas existentes. |
| WhatsApp | Lo que la rama de WAHA proteja con `ensureEnabled()`. | Su historial. |
| Portal pisos.com | Publicar y Actualizar (botones ocultos y rechazo en el conector con el motivo). | Anuncios y estado; **Retirar y Consultar estado siguen disponibles**, para poder quitar un anuncio ya publicado. |
| Captación GoHighLevel | El webhook `POST /api/v1/ghl/oportunidad-ganada` responde **403** `{"estado":"desactivado","message":…}` después de comprobar el secreto. | Captaciones registradas y expedientes. |

Al reactivar, todo vuelve a estar disponible desde la siguiente petición. Las acciones que se hayan rechazado no se repiten solas.

Límites conocidos:

* En la pantalla oficial de documentos (`MediaDocumentResource`, paquete de Packagist), el botón «Crear» sigue visible con Documentos desactivado. Al guardar un documento que no es de galería sale un error 403 con el mensaje, porque ese recurso gestiona también las fotos y no se puede ocultar solo para documentos sin modificar el paquete.
* El idioma de la interfaz del panel (`APP_LOCALES`, selector de idioma) no depende del módulo, a propósito: apagarlo no debe dejar el panel sin idioma.

## Prueba

```bash
php artisan clarto:modulos-prueba
```

Se ejecuta dentro de una transacción que se deshace al terminar (también las decisiones de «Módulos») y no llama a ningún servicio externo (`Http::preventStrayRequests`). Comprueba lo siguiente:

1. Los cuatro módulos pedidos aparecen con nombre, versión y estado; firma y WhatsApp, sin implementación, salen como «Pendiente de implementar» aunque se activen.
2. Pendiente de configurar: captación sin token de GHL (nombra las variables, sin valores), pisos.com en simulación, idiomas con o sin clave de IA; `MODULES_DISABLED` bloquea el interruptor.
3. Para cada módulo, al desactivarlo se rechaza la operación nueva (webhook 403, IA sin llamar, borrador o traducción, publicación en pisos.com, documento, sobre, `ensureEnabled`) y se conserva lo que había; al reactivarlo, la operación vuelve a funcionar.
4. Permisos: solo los administradores gestionan módulos, y la entrada de la página del paquete queda oculta.

Resultado en el contenedor del panel con una **copia** de su base de datos (`VACUUM INTO` en `/tmp`, caché en memoria, sin escribir en `/var/www/html`): **38 correctas, 0 fallos**, en español y en turco.

## Para desplegar (orquestador)

* Archivo de módulo modificado (va en el parche): `modules/real-estate-application/src/Providers/Filament/AdminPanelProvider.php`, una línea que registra `App\Filament\Pages\Modules`.
* Host: `bootstrap/providers.php` añade `App\Providers\ClartoModulesServiceProvider`. No hay migraciones (la tabla `module_activations` ya existe), ni variables de entorno nuevas, ni cambios en Composer.
* Después de desplegar: `php artisan clarto:modulos-prueba`.
