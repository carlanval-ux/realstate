@php($sections = $this->manual())
<x-filament-panels::page>
    <style>
        .cl-manual { display: grid; gap: 1.5rem 2rem; grid-template-columns: minmax(0, 1fr); }
        .cl-manual__side { display: flex; flex-direction: column; gap: 1rem; }
        @media (min-width: 1024px) {
            .cl-manual { grid-template-columns: 15rem minmax(0, 1fr); }
            /* mobile: normal flow, else it sits over the text */
            .cl-manual__side { position: sticky; top: 5rem; align-self: start; max-height: calc(100vh - 6rem); overflow-y: auto; }
            .cl-manual__toc > summary { display: none; }
        }
        .cl-manual__toc > summary { cursor: pointer; font-weight: 600; font-size: .9rem; }
        .cl-manual__toc nav { display: flex; flex-direction: column; gap: .35rem; font-size: .875rem; margin-top: .5rem; }
        .cl-manual__toc a { color: var(--gray-500); text-decoration: none; }
        .cl-manual__toc a:hover { color: var(--primary-600); }
        .cl-manual__hits { font-size: .8rem; color: var(--gray-500); }
        .cl-manual__body { max-width: 52rem; line-height: 1.65; font-size: .95rem; }
        .cl-manual__body h2 { font-size: 1.25rem; font-weight: 600; margin: 2.25rem 0 .75rem; scroll-margin-top: 5rem; }
        .cl-manual__body h3 { font-size: 1.05rem; font-weight: 600; margin: 1.5rem 0 .5rem; scroll-margin-top: 5rem; }
        .cl-manual__body section:first-child h2 { margin-top: 0; }
        .cl-manual__body p, .cl-manual__body ul, .cl-manual__body ol, .cl-manual__body table { margin: .6rem 0; }
        .cl-manual__body ul { list-style: disc; padding-left: 1.4rem; }
        .cl-manual__body ol { list-style: decimal; padding-left: 1.4rem; }
        .cl-manual__body li { margin: .25rem 0; }
        .cl-manual__body table { width: 100%; border-collapse: collapse; font-size: .9rem; display: block; overflow-x: auto; }
        .cl-manual__body th, .cl-manual__body td { border: 1px solid var(--gray-200); padding: .45rem .6rem; text-align: left; }
        .dark .cl-manual__body th, .dark .cl-manual__body td { border-color: var(--gray-700); }
        .cl-manual__body code { font-size: .85em; }
        .cl-manual__body a { color: var(--primary-600); text-decoration: underline; }
        ::highlight(cl-manual) { background-color: #fde68a; color: #111827; }
    </style>

    {{-- Search: accent-insensitive, every word must appear; filters sections and the index, highlights the words. --}}
    <div
        class="cl-manual"
        x-data="{
            q: new URLSearchParams(location.search).get('buscar') ?? '',
            hits: null,
            fold: (s) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(),
            filter() {
                const terms = this.fold(this.q).split(/[^\p{L}\p{N}]+/u).filter((t) => t.length > 1);
                let hits = 0;
                this.$root.querySelectorAll('[data-manual-section]').forEach((section) => {
                    section.dataset.text ??= this.fold(section.textContent);
                    const show = terms.every((t) => section.dataset.text.includes(t));
                    section.hidden = ! show;
                    hits += show && section.id !== 'inicio' ? 1 : 0;
                    const link = this.$root.querySelector(`[data-toc='${section.id}']`);
                    if (link) link.hidden = ! show;
                });
                this.hits = terms.length ? hits : null;
                this.highlight(terms);
            },
            highlight(terms) {
                if (! window.CSS?.highlights) return;
                CSS.highlights.delete('cl-manual');
                if (! terms.length) return;
                const ranges = [];
                this.$root.querySelectorAll('[data-manual-section]:not([hidden])').forEach((section) => {
                    const walker = document.createTreeWalker(section, NodeFilter.SHOW_TEXT);
                    for (let node = walker.nextNode(); node; node = walker.nextNode()) {
                        const text = this.fold(node.data);
                        if (text.length !== node.data.length) continue;
                        terms.forEach((t) => {
                            for (let i = text.indexOf(t); i > -1; i = text.indexOf(t, i + t.length)) {
                                const range = new Range();
                                range.setStart(node, i);
                                range.setEnd(node, i + t.length);
                                ranges.push(range);
                            }
                        });
                    }
                });
                CSS.highlights.set('cl-manual', new Highlight(...ranges));
            },
        }"
        x-init="
            filter();
            $watch('q', () => filter());
            if (location.hash) document.getElementById(decodeURIComponent(location.hash.slice(1)))?.scrollIntoView();
        "
    >
        <div class="cl-manual__side">
            <div>
                <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                    <x-filament::input type="search" x-model.debounce.250ms="q" :placeholder="__('Search the manual')" :aria-label="__('Search the manual')" />
                </x-filament::input.wrapper>
                <p class="cl-manual__hits" x-show="hits !== null" x-cloak x-text="hits === 0 ? @js(__('No section mentions these words.')) : @js(__('Sections found:')) + ' ' + hits"></p>
            </div>

            <details class="cl-manual__toc" x-init="$el.open = window.matchMedia('(min-width: 1024px)').matches">
                <summary>{{ __('Contents') }}</summary>
                <nav aria-label="{{ __('Contents') }}">
                    @foreach ($sections as $section)
                        @if ($section['title'] !== '')
                            <a href="#{{ $section['id'] }}" data-toc="apartado-{{ $section['id'] }}">{{ $section['title'] }}</a>
                        @endif
                    @endforeach
                </nav>
            </details>
        </div>

        <article class="cl-manual__body">
            @foreach ($sections as $section)
                <section id="{{ $section['title'] === '' ? 'inicio' : 'apartado-'.$section['id'] }}" data-manual-section>{!! $section['html'] !!}</section>
            @endforeach
        </article>
    </div>
</x-filament-panels::page>
