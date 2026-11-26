<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

/** Sign-in of /colaboradores: ClartoLogin (password managers) with «tú» in Spanish. */
final class CollaboratorLogin extends ClartoLogin
{
    use SpeaksInformally;

    public function getHeading(): string|Htmlable|null
    {
        return self::informal('login_heading') ?? parent::getHeading();
    }

    protected function getPasswordFormComponent(): Component
    {
        $field = parent::getPasswordFormComponent();
        assert($field instanceof TextInput);
        $forgot = self::informal('forgot');

        return $forgot === null || ! filament()->hasPasswordReset() ? $field : $field->hint(new HtmlString(Blade::render(
            '<x-filament::link :href="filament()->getRequestPasswordResetUrl()" tabindex="-1">{{ $label }}</x-filament::link>', ['label' => $forgot],
        )));
    }
}
