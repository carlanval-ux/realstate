<?php

declare(strict_types=1);

namespace App\Filament;

use App\Collaborators\CollaboratorAccess;
use Filament\Facades\Filament;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\Resources\Resource;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Throwable;

/**
 * «En tus datos» of «Preguntar a CLARTO»: the panel's own records that match a question, found with the SAME query,
 * tenant scope and policies as each screen and the panel's global search — never a query of its own on the tables.
 *
 * Only the resources listed in SOURCES (the day-to-day business screens, never users, roles or teams), only those
 * registered in the current panel, and only when the request is in order: the panel the chat was rendered on, the
 * tenant of the URL when the panel has one (without a tenant Filament would not scope at all), and an external
 * collaborator only in /colaboradores. Anything else → no data (fails closed).
 */
final class PanelDataSearch
{
    public const PER_SOURCE = 3;

    public const MAX_RESULTS = 8;

    private const MAX_TERMS = 6;

    /**
     * Resource => attributes the chat searches. null = the resource's own global search (its attributes, query
     * changes and result titles); a list = resources without global search (the inventory module's property screen),
     * searched on getGlobalSearchEloquentQuery()
     * (= the screen's query) with those attributes only (never notes, bodies or internal fields). The first attribute
     * is the result's title, the second its detail line.
     *
     * @var array<class-string<Resource>, list<string>|null>
     */
    private const SOURCES = [
        \Liberu\RealEstate\PropertiesFilament\Resources\PropertyResource::class => ['title', 'address', 'postal_code'],
        \App\Filament\Owners\OwnerResource::class => null,
        // Not «Documentación de la empresa»: by design only its own module may touch it (gestor-documental battery).
        \App\Filament\Resources\SearchRequestResource::class => null,
        \App\Filament\Resources\ValuationRequestResource::class => null,
        \App\Filament\Resources\AlbaranResource::class => null,
        \App\Filament\Resources\CollaboratorResource::class => null,
        \App\Filament\Resources\ImprovementRequestResource::class => null,
        // /colaboradores: their resources already go through CollaboratorAccess (assigned properties, requests sent).
        \App\Filament\Colaboradores\Resources\MyPropertyResource::class => null,
        \App\Filament\Colaboradores\Resources\SearchRequestResource::class => null,
    ];

    /** Resources an external collaborator may ever be searched in, whatever the panel says. */
    private const COLLABORATOR_SOURCES = [
        \App\Filament\Colaboradores\Resources\MyPropertyResource::class,
        \App\Filament\Colaboradores\Resources\SearchRequestResource::class,
    ];

    /** Words that say what is asked, not which record: dropped before searching. */
    private const STOPWORDS = [
        'a', 'al', 'algo', 'alguna', 'alguno', 'ante', 'aqui', 'aquí', 'busca', 'buscar', 'busco', 'como', 'cómo', 'con', 'cual', 'cuál', 'cuales', 'cuáles',
        'cuando', 'cuándo', 'de', 'del', 'donde', 'dónde', 'dame', 'el', 'ella', 'en', 'encontrar', 'encuentra', 'encuentro', 'es', 'esta', 'está', 'este',
        'esto', 'estan', 'están', 'hay', 'la', 'las', 'le', 'lo', 'los', 'me', 'mi', 'mis', 'muestra', 'muéstrame', 'mostrar', 'para', 'pero', 'por', 'que',
        'qué', 'quien', 'quién', 'se', 'ser', 'si', 'sí', 'sin', 'sobre', 'su', 'sus', 'tengo', 'tiene', 'tienen', 'todo', 'todos', 'tu', 'tus', 'un',
        'una', 'unos', 'unas', 'ver', 'veo', 'y', 'o', 'u', 'ya', 'datos', 'dato', 'ficha', 'fichas', 'panel', 'clarto', 'quiero', 'necesito', 'puedo',
        'inmueble', 'inmuebles', 'propiedad', 'propiedades', 'propietario', 'propietarios', 'propietaria', 'solicitud', 'solicitudes', 'valoracion',
        'valoración', 'valoraciones', 'expediente', 'expedientes', 'documento', 'documentos', 'albaran', 'albarán', 'albaranes', 'colaborador',
        'colaboradores', 'colaboradora', 'mejora', 'mejoras', 'referencia',
        'the', 'of', 'in', 'on', 'for', 'to', 'and', 'or', 'is', 'are', 'where', 'what', 'which', 'who', 'how', 'show', 'find', 'my', 'property',
        'properties', 'owner', 'owners', 'request', 'requests', 'valuation', 'valuations', 'document', 'documents', 'file',
    ];

    /**
     * @return list<array{title: string, category: string, details: string, url: string}>
     */
    public static function search(string $question, string $panelId): array
    {
        if (! self::requestInOrder($panelId)) {
            return [];
        }

        $terms = self::terms($question);
        if ($terms === []) {
            return [];
        }

        // All the words together first (like the global search); nothing → each meaningful word on its own.
        $results = self::searchTerms(implode(' ', $terms));
        if ($results === [] && count($terms) > 1) {
            foreach ($terms as $term) {
                if (mb_strlen($term) >= 4 || preg_match('/\d/', $term) === 1) {
                    $results = [...$results, ...self::searchTerms($term)];
                }
            }
        }

        return array_slice(array_values(collect($results)->unique('url')->all()), 0, self::MAX_RESULTS);
    }

    /** The current panel is the chat's, its tenant (if any) is set and the user's, and a collaborator is in their panel. */
    public static function requestInOrder(string $panelId): bool
    {
        $user = auth()->user();
        $panel = Filament::getCurrentPanel();
        if ($user === null || $panel === null || $panel->getId() !== $panelId || ! in_array($panelId, ManualChat::PANELS, true)) {
            return false;
        }
        if (! $user->canAccessPanel($panel)) {
            return false;
        }
        if (CollaboratorAccess::isCollaborator($user) && $panelId !== CollaboratorAccess::PANEL) {
            return false;
        }
        if ($panel->hasTenancy()) {
            $tenant = Filament::getTenant();

            return $tenant !== null && method_exists($user, 'canAccessTenant') && $user->canAccessTenant($tenant);
        }

        return true;
    }

    /** @return list<string> lower-case words worth searching (letters, digits, «-», «@», «.»), at most MAX_TERMS */
    public static function terms(string $question): array
    {
        $words = preg_split('/[^\p{L}\p{N}@.\-]+/u', mb_strtolower($question), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_map(fn (string $w): string => trim($w, '.-'), $words);
        $words = array_filter($words, fn (string $w): bool => $w !== '' && ! in_array($w, self::STOPWORDS, true)
            && (mb_strlen($w) >= 3 || preg_match('/\d/', $w) === 1));

        return array_slice(array_values(array_unique($words)), 0, self::MAX_TERMS);
    }

    /** @return list<array{title: string, category: string, details: string, url: string}> */
    private static function searchTerms(string $search): array
    {
        $user = auth()->user();
        $registered = Filament::getCurrentPanel()?->getResources() ?? [];
        $collaborator = CollaboratorAccess::isCollaborator($user);
        $results = [];

        foreach (self::SOURCES as $resource => $attributes) {
            if (! in_array($resource, $registered, true) || ($collaborator && ! in_array($resource, self::COLLABORATOR_SOURCES, true))) {
                continue;
            }
            try {
                $found = $attributes === null ? self::viaGlobalSearch($resource, $search) : self::viaScreenQuery($resource, $attributes, $search);
            } catch (Throwable $e) {
                report($e);   // one broken screen must not break the answer (nor reveal anything)

                continue;
            }
            foreach ($found->take(self::PER_SOURCE) as $result) {
                $results[] = [
                    'title' => self::text($result->title),
                    'category' => (string) $resource::getPluralModelLabel(),
                    'details' => implode(' · ', array_map(fn (mixed $v): string => self::text($v), array_values(array_filter((array) $result->details, 'filled')))),
                    'url' => (string) $result->url,
                ];
            }
        }

        return $results;
    }

    /**
     * The resource's own global search, exactly as the «Buscar» box runs it.
     *
     * @param  class-string<Resource>  $resource
     * @return Collection<int, GlobalSearchResult>
     */
    private static function viaGlobalSearch(string $resource, string $search): Collection
    {
        return $resource::canGloballySearch() ? $resource::getGlobalSearchResults($search)->values() : collect();
    }

    /**
     * A resource without global search: its screen query (getGlobalSearchEloquentQuery = getEloquentQuery: tenant
     * scope and the resource's own restrictions), its access check, and per record its own view/edit policy for the link.
     *
     * @param  class-string<Resource>  $resource
     * @param  list<string>  $attributes
     * @return Collection<int, GlobalSearchResult>
     */
    private static function viaScreenQuery(string $resource, array $attributes, string $search): Collection
    {
        if (! $resource::canAccess()) {
            return collect();
        }

        $query = $resource::getGlobalSearchEloquentQuery();
        foreach (preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $reference = self::referenceId($word);
            $query->where(function (Builder $q) use ($attributes, $word, $reference): void {
                foreach ($attributes as $attribute) {
                    $q->orWhere($q->qualifyColumn($attribute), 'like', '%'.$word.'%');
                }
                if ($reference !== null) {
                    $q->orWhere($q->getModel()->getQualifiedKeyName(), $reference);
                }
            });
        }

        return $query->limit(20)->get()->map(function (Model $record) use ($resource, $attributes): ?GlobalSearchResult {
            $url = $resource::getGlobalSearchResultUrl($record);
            if (blank($url)) {
                return null;
            }

            $name = (string) ($record->getAttribute($attributes[0]) ?? '');
            $title = method_exists($record, 'reference') ? trim($record->reference().' · '.$name, ' ·') : $name;
            $details = array_values(array_filter(array_map(fn (string $a): string => (string) ($record->getAttribute($a) ?? ''), array_slice($attributes, 1, 1))));

            return new GlobalSearchResult(title: $title !== '' ? $title : (string) $resource::getRecordTitle($record), url: $url, details: $details);
        })->filter()->values();
    }

    /** «RE-2026-00042» (Property::reference()) → 42. */
    private static function referenceId(string $word): ?int
    {
        return preg_match('/^[a-z]+-\d{4}-0*(\d+)$/i', $word, $m) === 1 ? (int) $m[1] : null;
    }

    private static function text(mixed $value): string
    {
        $value = $value instanceof Htmlable ? $value->toHtml() : (string) $value;

        return trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5));
    }
}
