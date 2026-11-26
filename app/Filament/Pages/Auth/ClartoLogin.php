<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\Login;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;

/**
 * Filament's login, recognisable by password managers: the inputs carry `name` and the e-mail `autocomplete="username"`
 * (Filament renders them without a name and with autocomplete="on", so Chrome, 1Password, Bitwarden… did not offer
 * to fill or save). Used by /admin (ClartoServiceProvider) and /colaboradores (CollaboratorPanelProvider).
 */
class ClartoLogin extends Login
{
    protected function getEmailFormComponent(): Component
    {
        $field = parent::getEmailFormComponent();
        assert($field instanceof TextInput);

        return $field->autocomplete('username')->extraInputAttributes(['name' => 'email']);
    }

    protected function getPasswordFormComponent(): Component
    {
        $field = parent::getPasswordFormComponent();
        assert($field instanceof TextInput);

        return $field->extraInputAttributes(['name' => 'password']);
    }
}
