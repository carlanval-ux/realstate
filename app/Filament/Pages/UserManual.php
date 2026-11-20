<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Str;

/** "User manual": resources/manual/{locale}.md rendered in the panel, in the user's language (es as fallback). */
final class UserManual extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $slug = 'manual';

    protected static ?int $navigationSort = -100;

    protected string $view = 'filament.pages.user-manual';

    public static function getNavigationLabel(): string
    {
        return __('User manual');
    }

    public function getTitle(): string
    {
        return __('User manual');
    }

    /** @return array{html: string, toc: array<string, string>} */
    public function manual(): array
    {
        $file = resource_path('manual/'.app()->getLocale().'.md');
        $markdown = (string) file_get_contents(is_file($file) ? $file : resource_path('manual/es.md'));
        $markdown = (string) preg_replace('/^# .*\n/', '', $markdown, 1); // the page already shows the title

        $toc = [];
        $html = (string) preg_replace_callback('/<h2>(.*?)<\/h2>/', function (array $m) use (&$toc): string {
            $id = Str::slug(strip_tags($m[1]));
            $toc[$id] = strip_tags($m[1]);

            return '<h2 id="'.$id.'">'.$m[1].'</h2>';
        }, Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]));

        return ['html' => $html, 'toc' => $toc];
    }
}
