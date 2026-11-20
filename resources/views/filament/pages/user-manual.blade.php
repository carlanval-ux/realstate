@php($manual = $this->manual())
<x-filament-panels::page>
    <style>
        .cl-manual { display: grid; gap: 2rem; grid-template-columns: minmax(0, 1fr); }
        @media (min-width: 1024px) { .cl-manual { grid-template-columns: 15rem minmax(0, 1fr); } }
        .cl-manual__toc { position: sticky; top: 5rem; align-self: start; display: flex; flex-direction: column; gap: .35rem; font-size: .875rem; }
        .cl-manual__toc a { color: var(--gray-500); text-decoration: none; }
        .cl-manual__toc a:hover { color: var(--primary-600); }
        .cl-manual__body { max-width: 52rem; line-height: 1.65; font-size: .95rem; }
        .cl-manual__body h2 { font-size: 1.25rem; font-weight: 600; margin: 2.25rem 0 .75rem; scroll-margin-top: 5rem; }
        .cl-manual__body h3 { font-size: 1.05rem; font-weight: 600; margin: 1.5rem 0 .5rem; }
        .cl-manual__body p, .cl-manual__body ul, .cl-manual__body ol, .cl-manual__body table { margin: .6rem 0; }
        .cl-manual__body ul { list-style: disc; padding-left: 1.4rem; }
        .cl-manual__body ol { list-style: decimal; padding-left: 1.4rem; }
        .cl-manual__body li { margin: .25rem 0; }
        .cl-manual__body table { width: 100%; border-collapse: collapse; font-size: .9rem; }
        .cl-manual__body th, .cl-manual__body td { border: 1px solid var(--gray-200); padding: .45rem .6rem; text-align: left; }
        .dark .cl-manual__body th, .dark .cl-manual__body td { border-color: var(--gray-700); }
        .cl-manual__body code { font-size: .85em; }
        .cl-manual__body a { color: var(--primary-600); text-decoration: underline; }
    </style>

    <div class="cl-manual">
        <nav class="cl-manual__toc" aria-label="{{ __('User manual') }}">
            @foreach ($manual['toc'] as $id => $title)
                <a href="#{{ $id }}">{{ $title }}</a>
            @endforeach
        </nav>
        <article class="cl-manual__body">{!! $manual['html'] !!}</article>
    </div>
</x-filament-panels::page>
