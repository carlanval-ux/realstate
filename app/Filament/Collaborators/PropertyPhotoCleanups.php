<?php

declare(strict_types=1);

namespace App\Filament\Collaborators;

use App\Collaborators\Import\PhotoCleanup;
use App\Collaborators\Import\WatermarkRemoval;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Liberu\RealEstate\Properties\Models\Property;
use Livewire\Component;
use RuntimeException;

/**
 * Photo review of a collaborator property (tab «Colaboradores»): each photo whose watermark was removed, original vs
 * cleaned side by side, «Aprobar» / «Descartar» and «Aprobar todas». Nothing becomes public without a staff approval.
 */
final class PropertyPhotoCleanups extends Component
{
    public int $propertyId;

    public function request(): void
    {
        $this->attempt(fn () => __('importacion.cleanup.queued', ['n' => WatermarkRemoval::request($this->property())]));
    }

    public function approve(int $id): void
    {
        $this->attempt(function () use ($id): string {
            WatermarkRemoval::approve($this->cleanup($id));

            return __('importacion.cleanup.approved', ['n' => 1]);
        });
    }

    public function approveAll(): void
    {
        $this->attempt(function (): string {
            $ready = $this->cleanups()->where('status', PhotoCleanup::READY);
            $ready->each(fn (PhotoCleanup $c) => WatermarkRemoval::approve($c));

            return __('importacion.cleanup.approved', ['n' => $ready->count()]);
        });
    }

    public function discard(int $id): void
    {
        $this->attempt(function () use ($id): string {
            WatermarkRemoval::discard($this->cleanup($id));

            return __('importacion.cleanup.discarded');
        });
    }

    public function render(): string
    {
        return <<<'BLADE'
            <div class="space-y-4 text-sm">
                @php($items = $this->cleanups())
                @php($refusal = \App\Collaborators\Import\WatermarkRemoval::refusal($this->property()))
                <p class="text-gray-600 dark:text-gray-300">{{ __('importacion.cleanup.help') }}</p>
                @if ($refusal)
                    <p class="font-medium text-warning-600">{{ $refusal }}</p>
                @elseif ($items->whereIn('status', ['pendiente', 'procesando', 'listo'])->isEmpty())
                    <x-filament::button size="sm" color="gray" icon="heroicon-o-sparkles" wire:click="request" wire:confirm="{{ __('importacion.cleanup.request_confirm') }}">{{ __('importacion.cleanup.request') }}</x-filament::button>
                @endif
                @if ($items->where('status', 'listo')->count() > 1)
                    <x-filament::button size="sm" icon="heroicon-o-check-badge" wire:click="approveAll" wire:confirm="{{ __('importacion.cleanup.approve_all_confirm', ['n' => $items->where('status', 'listo')->count()]) }}">{{ __('importacion.cleanup.approve_all', ['n' => $items->where('status', 'listo')->count()]) }}</x-filament::button>
                @endif
                @foreach ($items as $c)
                    <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                        <div class="mb-2 font-medium">{{ __('importacion.cleanup.photo', ['n' => $loop->iteration]) }} · {{ __('importacion.cleanup.statuses.'.$c->status) }}@if ($c->error) — <span class="text-danger-600">{{ $c->error }}</span>@endif</div>
                        @if ($c->original_path)
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <figure><img loading="lazy" class="w-full rounded" src="{{ route('colaboradores.marca-agua', [$c->id, 'original']) }}" alt="{{ __('importacion.cleanup.original') }}"><figcaption class="mt-1 text-xs text-gray-500">{{ __('importacion.cleanup.original') }}</figcaption></figure>
                                @if ($c->cleaned_path)
                                    <figure><img loading="lazy" class="w-full rounded" src="{{ route('colaboradores.marca-agua', [$c->id, 'limpia']) }}" alt="{{ __('importacion.cleanup.cleaned') }}"><figcaption class="mt-1 text-xs text-gray-500">{{ __('importacion.cleanup.cleaned') }}</figcaption></figure>
                                @endif
                            </div>
                        @endif
                        @if ($c->status === 'listo')
                            <div class="mt-2 flex gap-2">
                                <x-filament::button size="sm" icon="heroicon-o-check" wire:click="approve({{ $c->id }})">{{ __('importacion.cleanup.approve') }}</x-filament::button>
                                <x-filament::button size="sm" color="gray" icon="heroicon-o-x-mark" wire:click="discard({{ $c->id }})">{{ __('importacion.cleanup.discard') }}</x-filament::button>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
            BLADE;
    }

    /** @return Collection<int, PhotoCleanup> latest attempt per photo */
    public function cleanups(): Collection
    {
        return PhotoCleanup::query()->where('property_id', $this->propertyId)->where('team_id', CollaboratorUi::teamId())
            ->orderByDesc('id')->get()->unique('media_document_id')->sortBy('id')->values();
    }

    public function property(): Property
    {
        $property = Property::query()->whereKey($this->propertyId)->where('team_id', CollaboratorUi::teamId())->first();
        abort_unless($property !== null && CollaboratorUi::staff(), 404);

        return $property;
    }

    private function cleanup(int $id): PhotoCleanup
    {
        $cleanup = PhotoCleanup::query()->whereKey($id)->where('property_id', $this->property()->getKey())->first();
        abort_if($cleanup === null, 404);

        return $cleanup;
    }

    /** @param callable(): string $work */
    private function attempt(callable $work): void
    {
        try {
            Notification::make()->success()->title($work())->send();
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title($e->getMessage())->persistent()->send();
        }
    }
}
