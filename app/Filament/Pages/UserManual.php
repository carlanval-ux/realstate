<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Collaborators\CollaboratorAccess;
use App\Modules\ClartoModules;
use App\Processes\Assistant\Text;
use Filament\Pages\Page;
use Illuminate\Support\Str;

/**
 * "User manual": resources/manual/{locale}.md rendered in the panel, in the user's language (es as fallback). A subclass
 * with its own MANUAL reads resources/manual/{MANUAL}.{locale}.md instead (CollaboratorManual: «colaboradores»).
 * The staff manual (MANUAL '') is empty for an external collaborator, whatever page, chat or search asks for it.
 *
 * A heading followed by a line `<!-- módulo: key[, key…] -->` (CLARTO module keys) is shown, with everything up to the
 * next heading of the same or higher level, only while one of those modules is switched on. The same visible text
 * feeds the page's own search box and the panel's global search (ManualGlobalSearchProvider).
 */
class UserManual extends Page
{
    /** '' = the staff manual; otherwise the file prefix of another audience's manual. */
    public const MANUAL = '';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $slug = 'manual';

    protected static ?int $navigationSort = -100;

    /** Own static (not Page's shared one): see App\Filament\ClartoNavigation. */
    protected static string|\UnitEnum|null $navigationGroup = null;

    protected string $view = 'filament.pages.user-manual';

    public static function getNavigationLabel(): string
    {
        return __('User manual');
    }

    public function getTitle(): string
    {
        return __('User manual');
    }

    /** @return list<array{id: string, title: string, html: string}> the introduction (title '') and one entry per «##» section */
    public function manual(): array
    {
        return array_map(fn (array $section): array => [
            'id' => $section['id'],
            'title' => $section['title'],
            'html' => (string) preg_replace_callback('/<h([23])>(.*?)<\/h\1>/', fn (array $m): string => '<h'.$m[1].' id="'.self::anchor($m[2]).'">'.$m[2].'</h'.$m[1].'>', self::html($section['markdown'])),
        ], static::sections());
    }

    /**
     * Manual entries («##» and «###») containing at least $minCoverage of the query's significant terms (1 = every
     * word, as the global search; less for questions written as sentences, as the chat), best first.
     *
     * @return list<array{title: string, section: string, anchor: string, excerpt: string}>
     */
    public static function search(string $query, int $limit = 5, float $minCoverage = 1.0): array
    {
        $terms = Text::terms($query);
        if ($terms === []) {
            return [];
        }
        $found = [];
        foreach (static::sections() as $section) {
            foreach (preg_split('/^(?=### )/m', $section['markdown']) ?: [] as $part) {
                $title = preg_match('/^#{2,3} (.+)$/m', $part, $m) === 1 ? trim($m[1]) : '';
                // One line per paragraph, list item or heading (the markdown's own line wraps are not breaks).
                $blocks = collect(explode("\x1E", html_entity_decode(strip_tags((string) preg_replace('#</(p|li|h[1-6]|td|th)>#', "\x1E", self::html($part))))))
                    ->map(fn (string $b): string => trim((string) preg_replace('/\s+/u', ' ', $b)))->filter()->values();
                $text = $blocks->implode("\n");
                $coverage = Text::coverage($terms, $text);
                if ($title === '' || $coverage < $minCoverage) {
                    continue;
                }
                // The sentence with most of the words the title does not already show.
                $rest = array_values(array_filter($terms, fn (string $t): bool => Text::coverage([$t], $title) === 0.0)) ?: $terms;
                $sentence = $blocks->flatMap(fn (string $b): array => preg_split('/(?<=[.;!?])\s+/u', $b) ?: [])
                    ->reject(fn (string $s): bool => $s === $title)
                    ->sortByDesc(fn (string $s): float => Text::coverage($rest, $s) + Text::coverage($terms, $s) / 10)->first() ?? '';
                $folded = Text::fold($text);
                $mentions = array_sum(array_map(fn (string $t): int => substr_count($folded, $t), $terms));
                $found[] = [
                    'title' => $title,
                    'section' => $section['title'],
                    'anchor' => self::anchor($title),
                    'excerpt' => Str::limit($sentence, 140),
                    'score' => 3 * Text::coverage($terms, $title) + 2 * $coverage + min($mentions, 10) / 10,
                ];
            }
        }
        usort($found, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_map(fn (array $f): array => array_diff_key($f, ['score' => 0]), array_slice($found, 0, $limit));
    }

    /** @return list<array{id: string, title: string, markdown: string}> visible sections: intro (title '') first */
    protected static function sections(): array
    {
        if (static::MANUAL === '' && CollaboratorAccess::isCollaborator(auth()->user())) {
            return []; // internal screens and procedures: never for external collaborators
        }
        $prefix = static::MANUAL === '' ? '' : static::MANUAL.'.';
        $file = resource_path('manual/'.$prefix.app()->getLocale().'.md');
        $markdown = (string) file_get_contents(is_file($file) ? $file : resource_path('manual/'.$prefix.'es.md'));
        $markdown = (string) preg_replace('/^# .*\n/', '', str_replace("\r\n", "\n", $markdown), 1); // the page already shows the title

        $sections = [];
        foreach (preg_split('/^(?=## )/m', self::withoutDisabledModules($markdown)) ?: [] as $chunk) {
            if (trim($chunk) === '') {
                continue;
            }
            $title = preg_match('/^## (.+)$/m', $chunk, $m) === 1 && str_starts_with($chunk, '## ') ? trim($m[1]) : '';
            $sections[] = ['id' => $title === '' ? 'inicio' : self::anchor($title), 'title' => $title, 'markdown' => $chunk];
        }

        return $sections;
    }

    private static function withoutDisabledModules(string $markdown): string
    {
        $lines = explode("\n", $markdown);
        $kept = [];
        $skipping = null; // level of the heading whose block is being left out
        foreach ($lines as $i => $line) {
            if (preg_match('/^(#{2,3}) /', $line, $heading) === 1) {
                $level = strlen($heading[1]);
                if ($skipping !== null && $level <= $skipping) {
                    $skipping = null;
                }
                if ($skipping === null && preg_match('/^<!--\s*m[oó]dulos?:\s*(.+?)\s*-->\s*$/u', $lines[$i + 1] ?? '', $marker) === 1
                    && ! collect(explode(',', $marker[1]))->contains(fn (string $key): bool => self::moduleOn(trim($key)))) {
                    $skipping = $level;
                }
            }
            if ($skipping === null && preg_match('/^<!--\s*m[oó]dulos?:/u', $line) !== 1) {
                $kept[] = $line;
            }
        }

        return implode("\n", $kept);
    }

    private static function moduleOn(string $key): bool
    {
        return in_array($key, ClartoModules::keys(), true) && ClartoModules::switchedOn($key);
    }

    private static function html(string $markdown): string
    {
        return Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }

    private static function anchor(string $title): string
    {
        return Str::slug(html_entity_decode(strip_tags(Str::inlineMarkdown($title))));
    }
}
