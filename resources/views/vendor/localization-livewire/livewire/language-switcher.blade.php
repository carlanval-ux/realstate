{{-- CLARTO: the package view used utility classes the Filament theme does not compile (white box in dark mode). --}}
<div>
    <x-filament::dropdown placement="bottom-end" width="xs">
        <x-slot name="trigger">
            <x-filament::button color="gray" size="sm" outlined icon="heroicon-m-language" aria-label="{{ __('Language') }}">
                {{ strtoupper($currentLocale) }}
            </x-filament::button>
        </x-slot>

        <x-filament::dropdown.list>
            @foreach ($availableLocales as $locale => $name)
                <x-filament::dropdown.list.item
                    wire:click="switchLanguage('{{ $locale }}')"
                    :icon="$locale === $currentLocale ? 'heroicon-m-check' : null"
                    :color="$locale === $currentLocale ? 'primary' : 'gray'"
                    lang="{{ $locale }}"
                >
                    {{ $name }}
                </x-filament::dropdown.list.item>
            @endforeach
        </x-filament::dropdown.list>
    </x-filament::dropdown>
</div>
