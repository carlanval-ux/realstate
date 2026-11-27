<?php

declare(strict_types=1);

namespace App\Signing;

/**
 * Read-only check of the DocuSeal setup on the server (pwb scripts/set-docuseal.sh, clarto:firma-comprobar): the key
 * opens the account of the configured environment, the webhook secret is there, and every active «Formulario»
 * that uses a DocuSeal template points to an existing, unarchived template with the same roles and fields.
 * Only GETs; never prints the key or the secret. Lines for the owner, in Spanish.
 */
final class DocusealCheck
{
    /** @var list<array{0: string, 1: string}> */
    private array $lines = [];

    /** @return list<array{0: string, 1: string}> [level OK|AVISO|ERROR, text] */
    public function run(): array
    {
        $this->lines = [];
        $provider = new DocusealProvider;
        $env = $provider->environment();
        $label = $env === 'production' ? 'producción (nube UE)' : 'pruebas (nube global, solo datos ficticios)';

        config('firma.driver') === 'docuseal'
            ? $this->say('OK', 'envíos reales ACTIVADOS (FIRMA_DRIVER=docuseal)')
            : $this->say('AVISO', 'envíos reales desactivados: el panel simula la firma (se activan con --activar)');
        $this->say('OK', "entorno de DocuSeal: {$label}, {$provider->apiUrl()}");

        if (blank(config('firma.docuseal.api_key'))) {
            $this->say('ERROR', 'falta la clave de la API de DocuSeal de este entorno ('.($env === 'production' ? 'DOCUSEAL_API_KEY_PROD' : 'DOCUSEAL_API_KEY_SANDBOX').')');
        } else {
            [$code] = $provider->probe('/templates', ['limit' => 1]);
            match (true) {
                $code === 200 => $this->say('OK', 'DocuSeal acepta la clave'),
                in_array($code, [401, 403], true) => $this->say('ERROR', "DocuSeal no acepta la clave ({$code}): ¿es de la cuenta de {$label}?"),
                $code === 0 => $this->say('ERROR', 'DocuSeal no contesta desde el servidor'),
                default => $this->say('ERROR', "DocuSeal contestó {$code}"),
            };
        }

        $secret = (string) config('firma.docuseal.webhook_secret');
        if ($secret === '') {
            $this->say('ERROR', 'falta el secreto HMAC del webhook ('.($env === 'production' ? 'DOCUSEAL_WEBHOOK_SECRET_PROD' : 'DOCUSEAL_WEBHOOK_SECRET_SANDBOX').')');
        } else {
            str_starts_with($secret, 'whsec_')
                ? $this->say('OK', 'secreto del webhook guardado')
                : $this->say('AVISO', 'el secreto del webhook no empieza por «whsec_»: cópialo de la pestaña HMAC del webhook');
        }
        $this->say('OK', 'URL del webhook en DocuSeal: '.rtrim((string) config('app.url'), '/').'/api/v1/firma/docuseal');

        if (! config('firma.docuseal.send_email') && config('firma.sms.driver') !== 'redtelecom') {
            $this->say('AVISO', 'DocuSeal no envía el correo con el enlace (DOCUSEAL_SEND_EMAIL=false) y los SMS reales no están activos: el firmante no recibiría nada');
        }

        if (filled(config('firma.docuseal.api_key'))) {
            $this->templates($provider, $env);
        }

        return $this->lines;
    }

    public function failed(): bool
    {
        return collect($this->lines)->contains(fn (array $l): bool => $l[0] === 'ERROR');
    }

    private function templates(DocusealProvider $provider, string $env): void
    {
        $forms = FormTemplate::query()->where('status', 'activo')->where('source', 'docuseal')->orderBy('key')->orderBy('version')->get();
        if ($forms->isEmpty()) {
            $this->say('AVISO', 'no hay formularios activos con plantilla de DocuSeal (los de PDF subido no la necesitan)');
        }
        foreach ($forms as $form) {
            $name = "«{$form->name}» v{$form->version}";
            $id = $form->docusealTemplateId($env);
            if ($id === null) {
                $this->say($form->fictitious ? 'AVISO' : 'ERROR', "{$name}: sin id de plantilla de DocuSeal para este entorno".($form->fictitious ? ' (formulario ficticio de prueba)' : ''));

                continue;
            }
            [$code, $t] = $provider->probe('/templates/'.$id);
            if ($code !== 200) {
                $this->say('ERROR', "{$name}: la plantilla {$id} no existe en esta cuenta de DocuSeal ({$code})");

                continue;
            }
            if (filled($t['archived_at'] ?? null)) {
                $this->say('ERROR', "{$name}: la plantilla {$id} está archivada en DocuSeal");

                continue;
            }
            $roles = array_map(fn ($s) => (string) ($s['name'] ?? ''), (array) ($t['submitters'] ?? []));
            $fields = array_map(fn ($f) => (string) ($f['name'] ?? ''), (array) ($t['fields'] ?? []));
            $wantRoles = collect((array) $form->roles)->map(fn ($r) => $r['docuseal_role'] ?? null)->filter()->values()->all();
            $wantFields = collect((array) $form->fields)->map(fn ($f) => $f['docuseal_field'] ?? null)->filter()->values()->all();
            $missingRoles = array_values(array_diff($wantRoles, $roles));
            $missingFields = array_values(array_diff($wantFields, $fields));
            if ($missingRoles === [] && $missingFields === []) {
                $this->say('OK', "{$name}: plantilla {$id} con sus roles y campos");
            } else {
                $this->say('ERROR', "{$name}: a la plantilla {$id} le faltan".($missingRoles ? ' roles '.implode(', ', $missingRoles) : '').($missingFields ? ' campos '.implode(', ', $missingFields) : ''));
            }
        }
    }

    private function say(string $level, string $text): void
    {
        $this->lines[] = [$level, $text];
    }
}
