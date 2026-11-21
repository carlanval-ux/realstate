# Propietarios (ficha 360 del propietario)

Panel → **Contactos → Propietarios** y **Contactos → Pendientes de vinculación**. Solo personal
(roles `super_admin`, `admin`, `staff`). NIF/NIE/CIF e IBAN se ven completos solo con `super_admin` o
`admin`; el resto los ve enmascarados (`•••••678Z`, `ES•• •••• … 1332`), y en el formulario puede
escribir un valor nuevo, pero nunca recibe el actual (tampoco en el estado de Livewire).

## Qué es un propietario

Un `Party` de tipo `vendor`, o cualquier `Party` con ficha (`clarto_owner_profiles`, 1:1 por
`party_id`). Nombre, correo y teléfono siguen en el `Party`; el resto, en la ficha. Los inmuebles de un
propietario son los de sus encargos (`Instruction.party_id`), valoraciones (`Valuation.party_id`) y
captaciones de GHL (`clarto_ghl_handoffs.party_id`): `Property` no tiene columna de propietario.

## Captación ganada en GHL → propietario

`App\Acquisition\GhlHandoff::process()` (webhook `POST /api/v1/ghl/oportunidad-ganada`):

1. Ya vinculado a este contacto de GHL (`metadata.ghl_contact_id` o `metadata.ghl_contact_ids`) → ese propietario.
2. Si no, candidatos por correo (sin distinguir mayúsculas) o teléfono (últimas 9 cifras):
   - ninguno → propietario nuevo con ficha prefijada;
   - exactamente uno, sin contradicciones (otro correo, otro teléfono, otro nombre, u otro contacto de GHL ya vinculado) → ese propietario;
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
| `first_name`, `last_name` | `firstName`, `lastName` | |
| `legal_name` | `companyName` | |
| `person_type` | — | `juridica` si el NIF es un CIF, o si hay `companyName` sin `firstName`; si no, `fisica` |
| `tax_id` | campo personalizado `GHL_CONTACT_FIELD_NIF` | solo si pasa la validación; si no, se anota en `notes` |
| `fiscal_address`, `fiscal_postal_code`, `fiscal_city`, `fiscal_province`, `fiscal_country` | `address1`, `postalCode`, `city`, `state`, `country` | |
| `preferred_language` | campo personalizado `GHL_CONTACT_FIELD_IDIOMA` | `es`, `ca`, `en`, `tr`, `fr`, `de` |
| `preferred_channel` | campo personalizado `GHL_CONTACT_FIELD_CANAL` | `email`, `phone`, `whatsapp`, `sms`, `post` |
| `tags` | `tags` | se suman a las existentes |
| `source` | — | `ghl` o `panel` |
| `phone_secondary`, `postal_*`, `representative_*`, `privacy_consent_*`, `marketing_consent_*`, `consent_source`, `iban`, `iban_holder`, `notes` | — | se completan en el panel |

Regla: GHL solo rellena huecos; nunca sobrescribe lo que el equipo ha escrito.

Variables (`.env`; ids de campos personalizados del **contacto** en la subcuenta):
`GHL_CONTACT_FIELD_NIF`, `GHL_CONTACT_FIELD_IDIOMA`, `GHL_CONTACT_FIELD_CANAL`. Vacías = no se leen.

## Validaciones

- NIF (8 cifras + letra, y K/L/M), NIE (X/Y/Z) y CIF con dígito de control (`App\Owners\SpanishIds`).
  Persona física → NIF/NIE; jurídica → CIF (y NIF del representante). Un NIF no se repite dentro del equipo (índice único).
- IBAN: formato y control mod 97 (ES = 24 caracteres). Se guarda **cifrado** (`APP_KEY`).
- Código postal español: 01000–52999 cuando el país es ES.
- Consentimientos RGPD: fecha + versión del texto (versión vigente `OwnerProfile::CONSENT_VERSION`) + origen.

«Ficha incompleta» (lista y pestaña Datos): falta NIF, domicilio fiscal, correo, teléfono, canal preferido,
consentimiento de privacidad o IBAN, y nombre y apellidos (física) o razón social y representante (jurídica).

## Pendiente de contrastar con la ficha 360 de facturación

La estructura de la ficha 360 de las herramientas de facturación (campos, validaciones, permisos) aún no
ha llegado. Hasta entonces, estos campos son una propuesta y están listados en
`OwnerProfile::PENDING_BILLING_CONTRAST`:

- `person_type`, `tax_id`, `representative_name`, `representative_tax_id` (¿varios representantes? ¿cotitulares?)
- domicilio fiscal y postal (¿estructura de vía/número/piso? ¿código INE de municipio?)
- `preferred_channel`, `preferred_language` (valores admitidos)
- `privacy_consent_version`, `marketing_consent_version` (versionado de textos legales)
- `iban`, `iban_holder` (¿varias cuentas? ¿mandato SEPA?)
- `tags` (¿catálogo cerrado?)
- Permisos: hoy «datos sensibles» = `super_admin`/`admin`. Si facturación define otro rol o permiso, cambiar `OwnerProfile::canSeeSensitive()`.
- Cotitularidad: hoy un encargo tiene un único propietario (`Instruction.party_id`).

## Pestañas de la ficha 360 y punto de extensión

Datos · Inmuebles · Encargos · Valoraciones · Documentos (`MediaDocument` de sus inmuebles, sin galería, y
`DocumentEnvelope` de sus inmuebles o con el propietario como participante) · Historial (alta, captaciones
de GHL y reenvíos, encargos, valoraciones, cambios de ficha —solo nombres de campos— y vinculaciones).

Otras funciones añaden pestañas sin tocar estos archivos, desde el `boot()` de un proveedor:

```php
use App\Owners\OwnerProfileSections;
use Filament\Schemas\Components\Tabs\Tab;

OwnerProfileSections::register('albaranes', fn (): Tab => Tab::make(__('…'))
    ->icon('heroicon-o-receipt-percent')
    ->schema([/* componentes; los closures reciben `Party $record` */]), sort: 70);
```

Orden de las pestañas propias: Datos 10, Inmuebles 20, Encargos 30, Valoraciones 40, Documentos 50, Historial 60.
Ojo: en los closures de Filament los argumentos se inyectan **por nombre** (`$record`, `$state`, `$query`, `$get`).

## Prueba

```
php artisan migrate --force          # 2026_11_21_000007_create_clarto_owner_profiles_table
php artisan clarto:propietarios-prueba
php artisan clarto:ghl-prueba        # la de captación sigue pasando
```

GHL simulado con `Http::fake`, por la ruta real del webhook, en una transacción que se deshace.
