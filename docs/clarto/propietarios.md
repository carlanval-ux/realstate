# Propietarios (ficha 360 del propietario)

Panel → **Contactos → Propietarios** y **Contactos → Pendientes de vinculación**. Solo personal
(roles `super_admin`, `admin`, `staff`). El documento de identidad (y el del representante) y el IBAN se
ven completos solo con `super_admin` o `admin`; el resto los ve enmascarados (`•••••678Z`,
`ES•• •••• … 1332`), y en el formulario puede escribir un valor nuevo, pero nunca recibe el actual
(tampoco en el estado de Livewire).

La ficha sigue el modelo de la **ficha de cliente CRM001 de DEALER** (referencia del orquestador:
`orquestacion/ficha-360-y-facturador.md`), adaptada a propietarios: sin facturación de telecomunicaciones.

## Qué es un propietario

Un `Party` de tipo `vendor`, o cualquier `Party` con ficha (`clarto_owner_profiles`, 1:1 por
`party_id`). Nombre, correo y teléfono siguen en el `Party`; el resto, en la ficha. Código del propietario:
`PR-000001` (id de la ficha, como el `CL-000001` de CRM001). Los inmuebles de un propietario son los de
sus encargos (`Instruction.party_id`), valoraciones (`Valuation.party_id`) y captaciones de GHL
(`clarto_ghl_handoffs.party_id`): `Property` no tiene columna de propietario.

## Contraste con la ficha 360 (CRM001)

### Adoptado

| CRM001 | Propietarios | Regla |
|---|---|---|
| `party.kind` individual / organization | `person_type` fisica / juridica | no se mezclan: campos distintos por tipo |
| `given_name`, `family_name` (obligatorios, ≤200), `additional_family_name` | `first_name`, `last_name` (obligatorios en el panel), `second_last_name` | ≤200 |
| `birth_date` 1900–hoy, `nationality` ISO-2 | `birth_date`, `nationality` | |
| `legal_name` (obligatorio), `trading_name` | `legal_name` (obligatorio en el panel), `trading_name` | ≤200 |
| Identificadores NIF / NIE / CIF / PASSPORT / OTHER, normalizados | `tax_id_type` + `tax_id` | NIF y NIE mod 23; pasaporte 5–20; otro 2–40; sin espacios `. - /`, en mayúsculas. Persona física: NIF, NIE, pasaporte u otro; jurídica: CIF u otro |
| Email en minúsculas `^[^@\s]{1,64}@[^@\s]{1,190}\.[a-z]{2,}$`; teléfono 6–15 cifras (+) | `Party.email`, `Party.phone`, `phone_secondary` | misma regla |
| Preferencia de comunicación con franja horaria | `preferred_channel`, `preferred_language`, `contact_window` (≤100) | |
| CP español de 5 cifras coherente con la provincia | `fiscal_*` y `postal_*` | el CP rellena la provincia y debe coincidir con ella |
| Consentimiento de marketing con canal, versión del texto y evidencia; servicio/facturación/soporte siempre permitidos | `marketing_consent_at`, `_version`, `_channel`; `consent_source`, `consent_evidence` (≤2000) | versión y canal obligatorios si hay consentimiento |
| IBAN mod 97 (ES = 24), BIC `^[A-Z0-9]{8}([A-Z0-9]{3})?$`, titular, método `direct_debit` / `bank_transfer` | `iban`, `bic`, `iban_holder`, `payment_method` | IBAN nunca visible para quien no es administrador (`ES•• … 1234`) |
| Etiquetas | `tags` | |
| Duplicados por identificador, email, últimos 9 dígitos del teléfono o razón social; guardar exige confirmar | alta en el panel: casilla «Confirmo que es un propietario distinto»; captación GHL: cola de vinculación | `OwnerProfile::duplicates()` |
| Código de cliente `CL-000001` | `PR-000001` | calculado |
| Pestañas Resumen · Datos y contactos · Direcciones · Facturación · … · Documentos · Actividad | Resumen · Datos y contactos · Direcciones · Facturación · Inmuebles · Encargos · Valoraciones · Documentos · Historial | ver abajo |
| Auditoría de cada cambio | Historial (activity_log `propietarios`): campos cambiados, nunca sus valores | |

### Adaptado

- **CIF**: CRM001 solo comprueba el patrón; aquí también el **dígito de control** (más estricto).
- **Documento repetido**: CRM001 pide confirmación; aquí el documento principal es **único por equipo**
  (índice en BD) porque cada propietario tiene una sola ficha. Los demás duplicados (correo, teléfono,
  razón social) sí se resuelven con confirmación.
- **Contactos múltiples** (≤20, con finalidad y verificado): correo y teléfono principales en el `Party`
  y un teléfono secundario en la ficha.
- **Direcciones**: CRM001 usa dirección estructurada (tipo de vía, número, portal, escalera, piso, puerta)
  con códigos INE de municipio y localidad. Aquí: dirección en una línea + CP + municipio + provincia +
  país, fiscal y postal (la postal puede ser la fiscal). La provincia se valida contra el CP.
- **Consentimientos**: CRM001 guarda un registro append-only por finalidad/canal/estado. Aquí: privacidad
  (fecha + versión) y marketing (fecha + versión + canal) en la ficha; el historial guarda cada cambio de
  esos campos con fecha y autor. Retirar el consentimiento = vaciar la fecha (queda en el historial).
- **Personas autorizadas con rol**: solo el **representante legal** de la persona jurídica (nombre + NIF).
- **IBAN**: CRM001 lo guarda en Vault y conserva los 4 últimos. Aquí se guarda **cifrado** con `APP_KEY`
  (no hay Vault) y solo los administradores lo ven completo.
- **Notas**: CRM001 no tiene notas libres; aquí se mantienen `notes` internas porque el equipo las pidió.
- **Permisos**: `customers.read/create/update` → personal (`super_admin`, `admin`, `staff`);
  `billing.read/manage` y documento completo → `super_admin`/`admin` (`OwnerProfile::canSeeSensitive()`).
  CRM001 muestra el NIF completo en la cabecera 360 (hueco conocido); aquí está enmascarado en todas partes.
- **Contrataciones / Servicios** → **Encargos** e **Inmuebles**; se añade **Valoraciones**.

### Fuera (no aplica a propietarios)

Cuenta de facturación (código, moneda, ciclo 1–28, postpago/prepago, pagador distinto), dirección de
instalación y envío, verificación de contactos y de cuenta bancaria, consentimientos de perfilado y cesión
a terceros, clasificación/segmento/estado de la cuenta, riesgo y lista negra, campos personalizados,
códigos INE, revisiones completas y concurrencia optimista, documentos de identidad con finalidad declarada
para descargar. Cotitulares de un inmueble: un encargo tiene hoy un único propietario (`Instruction.party_id`).

## Captación ganada en GHL → propietario

`App\Acquisition\GhlHandoff::process()` (webhook `POST /api/v1/ghl/oportunidad-ganada`):

1. Ya vinculado a este contacto de GHL (`metadata.ghl_contact_id` o `metadata.ghl_contact_ids`) → ese propietario.
2. Si no, candidatos por correo (sin distinguir mayúsculas), teléfono (últimas 9 cifras) o NIF (si GHL lo envía):
   - ninguno → propietario nuevo con ficha prefijada;
   - exactamente uno, sin contradicciones (otro NIF, otro nombre, otro contacto de GHL ya vinculado; otro
     correo u otro teléfono, salvo que coincida el NIF) → ese propietario;
   - cualquier otro caso → **pendiente de vinculación**: se crean el inmueble y el encargo en borrador **sin
     propietario**, y el registro queda en `status = pendiente` con `candidate_party_ids`, `reason` y la copia del contacto (`contact`).
3. En *Pendientes de vinculación* una persona elige el propietario (candidatos o búsqueda por nombre, correo,
   teléfono o NIF) o confirma que es nuevo → `GhlHandoff::link()` asigna el encargo, guarda el contacto de
   GHL en el propietario, completa su ficha y deja constancia en el historial (`linked_by`, `linked_at`).

Idempotencia: una fila por oportunidad; un webhook repetido (procesado o pendiente) responde `duplicado`
y no crea nada. Un mismo contacto que aporta otro inmueble se vincula solo a la misma ficha.

## Mapa de campos GHL ↔ ficha

| Ficha (`clarto_owner_profiles` / `Party`) | GHL (contacto, API v2 `GET /contacts/{id}`) | Notas |
|---|---|---|
| `Party.name` | `name` o `firstName` + `lastName` | |
| `Party.email` | `email` | en minúsculas; solo si el propietario no tiene |
| `Party.phone` | `phone` | solo si el propietario no tiene |
| `Party.metadata.ghl_contact_id` / `ghl_contact_ids` | `id` | segundo contacto de la misma persona → `ghl_contact_ids` |
| `first_name`, `last_name` | `firstName`, `lastName` | `lastName` entero en `last_name`; el segundo apellido se separa en el panel |
| `legal_name` | `companyName` | |
| `person_type` | — | `juridica` si el NIF es un CIF, o si hay `companyName` sin `firstName`; si no, `fisica` |
| `tax_id_type`, `tax_id` | campo personalizado `GHL_CONTACT_FIELD_NIF` | solo NIF/NIE/CIF válidos; si no, se anota en `notes` |
| `fiscal_address`, `fiscal_postal_code`, `fiscal_city`, `fiscal_country` | `address1`, `postalCode`, `city`, `country` | |
| `fiscal_province` | — (España) / `state` (extranjero) | en España se deduce del CP |
| `preferred_language` | campo personalizado `GHL_CONTACT_FIELD_IDIOMA` | `es`, `ca`, `en`, `tr`, `fr`, `de` |
| `preferred_channel` | campo personalizado `GHL_CONTACT_FIELD_CANAL` | `email`, `phone`, `whatsapp`, `sms`, `post` |
| `tags` | `tags` | se suman a las existentes |
| `source` | — | `ghl` o `panel` |
| resto (`second_last_name`, `birth_date`, `nationality`, `trading_name`, representante, `phone_secondary`, `contact_window`, dirección postal, consentimientos, datos bancarios, `notes`) | — | se completan en el panel |

Regla: GHL solo rellena huecos; nunca sobrescribe lo que el equipo ha escrito.

Variables (`.env`; ids de campos personalizados del **contacto** en la subcuenta):
`GHL_CONTACT_FIELD_NIF`, `GHL_CONTACT_FIELD_IDIOMA`, `GHL_CONTACT_FIELD_CANAL`. Vacías = no se leen.

«Ficha incompleta» (lista y Resumen): falta documento, domicilio fiscal, correo, teléfono, canal preferido,
consentimiento de privacidad o IBAN; nombre y primer apellido (física) o razón social y representante
(jurídica); y el titular si la forma de pago es domiciliación.

## Pestañas de la ficha 360 y punto de extensión

Resumen 10 · Datos y contactos 20 · Direcciones 30 · Facturación 40 (forma de pago, titular, IBAN
enmascarado, BIC, domicilio fiscal) · Inmuebles 50 · Encargos 55 · Valoraciones 58 · Documentos 60
(`MediaDocument` de sus inmuebles, sin galería, y `DocumentEnvelope` de sus inmuebles o con el propietario
como participante) · Historial 90 (alta, captaciones de GHL y reenvíos, encargos, valoraciones, cambios de
ficha y vinculaciones).

Otras funciones añaden pestañas sin tocar estos archivos, desde el `boot()` de un proveedor; se colocan
por su `sort` entre las propias (`OwnerTabs::SORTS`):

```php
use App\Owners\OwnerProfileSections;
use Filament\Schemas\Components\Tabs\Tab;

OwnerProfileSections::register('albaranes', fn (): Tab => Tab::make(__('…'))
    ->icon('heroicon-o-receipt-percent')
    ->schema([/* componentes; los closures reciben `$record` (Party) */]), sort: 70);
```

Ojo: en los closures de Filament los argumentos se inyectan **por nombre** (`$record`, `$state`, `$query`, `$get`).

## Prueba

```
php artisan migrate --force          # 2026_11_21_000007_create_clarto_owner_profiles_table
php artisan clarto:propietarios-prueba
php artisan clarto:ghl-prueba        # la de captación sigue pasando
```

GHL simulado con `Http::fake`, por la ruta real del webhook, en una transacción que se deshace.
