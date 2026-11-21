<style>
    .clp { --clp-line: var(--gray-200); --clp-soft: var(--gray-50); --clp-muted: var(--gray-500); font-size: .95rem; }
    .dark .clp { --clp-line: var(--gray-700); --clp-soft: rgba(255,255,255,.03); --clp-muted: var(--gray-400); }
    .clp-search { display: flex; gap: .5rem; flex-wrap: wrap; }
    .clp-search input[type=search] { flex: 1 1 18rem; font-size: 1.1rem; padding: .8rem 1rem; border-radius: .75rem; border: 1px solid var(--clp-line); background: transparent; }
    .clp-btn { display: inline-flex; align-items: center; gap: .35rem; padding: .55rem .9rem; border-radius: .6rem; border: 1px solid var(--clp-line); font-weight: 500; font-size: .9rem; cursor: pointer; background: transparent; text-decoration: none; }
    .clp-btn--primary { background: var(--primary-600); border-color: var(--primary-600); color: #fff; }
    .clp-btn:hover { border-color: var(--primary-500); }
    .clp-grid { display: grid; gap: .9rem; grid-template-columns: repeat(auto-fill, minmax(17rem, 1fr)); }
    .clp-card { border: 1px solid var(--clp-line); border-radius: .75rem; padding: .9rem 1rem; display: flex; flex-direction: column; gap: .35rem; background: var(--clp-soft); }
    .clp-card h3 { font-weight: 600; line-height: 1.3; }
    .clp-card h3 a { text-decoration: none; }
    .clp-card h3 a:hover { color: var(--primary-600); }
    .clp-card p { color: var(--clp-muted); font-size: .875rem; }
    .clp-meta { display: flex; flex-wrap: wrap; gap: .35rem .75rem; font-size: .8rem; color: var(--clp-muted); align-items: center; }
    .clp-tag { display: inline-block; padding: .1rem .5rem; border-radius: 999px; font-size: .75rem; border: 1px solid var(--clp-line); }
    .clp-tag--demo { background: #fdf2e9; border-color: #e8b98f; color: #8a4b16; }
    .clp-tag--warn { background: #fef3c7; border-color: #f59e0b; color: #78350f; }
    .clp-tag--ok { background: #dcfce7; border-color: #22c55e; color: #14532d; }
    .clp-section { margin-top: 1.75rem; }
    .clp-section > h2 { font-size: 1.1rem; font-weight: 600; margin-bottom: .75rem; }
    .clp-chips { display: flex; flex-wrap: wrap; gap: .4rem; }
    .clp-chip { padding: .3rem .7rem; border-radius: 999px; border: 1px solid var(--clp-line); font-size: .85rem; cursor: pointer; background: transparent; }
    .clp-chip[aria-pressed=true] { background: var(--primary-600); color: #fff; border-color: var(--primary-600); }
    .clp-empty { color: var(--clp-muted); font-size: .9rem; }
    .clp-star { background: none; border: 0; cursor: pointer; font-size: 1.15rem; line-height: 1; color: var(--clp-muted); }
    .clp-star[aria-pressed=true] { color: #d97706; }
    /* Procedure page */
    .clp-doc { display: grid; gap: 2rem; grid-template-columns: minmax(0, 1fr); }
    @media (min-width: 1100px) { .clp-doc { grid-template-columns: 15rem minmax(0, 1fr); } }
    .clp-toc { position: sticky; top: 5rem; align-self: start; display: flex; flex-direction: column; gap: .3rem; font-size: .85rem; }
    .clp-toc a { color: var(--clp-muted); text-decoration: none; }
    .clp-toc a:hover { color: var(--primary-600); }
    @media (max-width: 1099px) { .clp-toc { position: static; border: 1px solid var(--clp-line); border-radius: .75rem; padding: .75rem 1rem; } }
    .clp-body { max-width: 54rem; line-height: 1.65; }
    .clp-body h2 { font-size: 1.2rem; font-weight: 600; margin: 2rem 0 .75rem; scroll-margin-top: 5rem; }
    .clp-prose p, .clp-prose ul, .clp-prose ol { margin: .4rem 0; }
    .clp-prose ul { list-style: disc; padding-left: 1.3rem; }
    .clp-prose ol { list-style: decimal; padding-left: 1.3rem; }
    .clp-prose a { color: var(--primary-600); text-decoration: underline; }
    .clp-dl { display: grid; grid-template-columns: minmax(8rem, 12rem) 1fr; gap: .35rem 1rem; }
    .clp-dl dt { color: var(--clp-muted); font-size: .85rem; }
    @media (max-width: 640px) { .clp-dl { grid-template-columns: 1fr; } .clp-dl dt { margin-top: .4rem; } }
    .clp-step { border: 1px solid var(--clp-line); border-radius: .75rem; padding: 1rem 1.1rem; margin: .8rem 0; scroll-margin-top: 5rem; }
    .clp-step header { display: flex; gap: .75rem; align-items: flex-start; }
    .clp-step__n { flex: none; width: 2rem; height: 2rem; border-radius: 999px; background: var(--primary-600); color: #fff; display: grid; place-items: center; font-weight: 600; }
    .clp-step h3 { font-weight: 600; font-size: 1.05rem; }
    .clp-step__done { margin-left: auto; display: flex; align-items: center; gap: .35rem; font-size: .8rem; color: var(--clp-muted); white-space: nowrap; }
    .clp-step__done input { width: 1.25rem; height: 1.25rem; }
    .clp-step--checked { border-color: #22c55e; }
    .clp-check { border-left: 3px solid #22c55e; padding-left: .75rem; margin: .5rem 0; }
    .clp-decision { border: 1px dashed var(--primary-500); border-radius: .75rem; padding: .8rem 1rem; margin: .8rem 0; scroll-margin-top: 5rem; }
    .clp-decision ul { list-style: none; padding: 0; margin: .4rem 0 0; display: grid; gap: .3rem; }
    .clp-callout { border-radius: .6rem; padding: .7rem .9rem; margin: .6rem 0; border: 1px solid var(--clp-line); }
    .clp-callout--importante { background: #fdf2e9; border-color: #e8b98f; color: #5c2f0c; }
    .clp-callout--aviso { background: #fef9c3; border-color: #eab308; color: #422006; }
    .clp-callout--info { background: #eff6ff; border-color: #93c5fd; color: #1e3a8a; }
    .clp-img { max-width: 100%; border-radius: .5rem; border: 1px solid var(--clp-line); cursor: zoom-in; }
    .clp-zoom { position: fixed; inset: 0; background: rgba(0,0,0,.85); display: grid; place-items: center; z-index: 60; cursor: zoom-out; padding: 1rem; }
    .clp-zoom img { max-width: 100%; max-height: 100%; }
    .clp-mm { max-width: 100%; height: auto; }
    .clp-mm-wrap { overflow-x: auto; border: 1px solid var(--clp-line); border-radius: .75rem; padding: .75rem; background: #fff; }
    .clp-mm-wrap .cl-mm-node { fill: #f0f7f6; stroke: #173D38; stroke-width: 1.4; }
    .clp-mm-wrap .cl-mm-decision { fill: #fbf1ec; stroke: #BD7968; }
    .clp-mm-wrap .cl-mm-label { font: 13px system-ui, sans-serif; fill: #12302c; }
    .clp-mm-wrap .cl-mm-elabel { font: 12px system-ui, sans-serif; fill: #8a4b16; }
    .clp-mm-wrap .cl-mm-edge { fill: none; stroke: #4b5563; stroke-width: 1.3; }
    .clp-mm-wrap .cl-mm-arrowhead { fill: #4b5563; }
    .clp-table { width: 100%; border-collapse: collapse; font-size: .875rem; }
    .clp-table th, .clp-table td { border-bottom: 1px solid var(--clp-line); padding: .45rem .5rem; text-align: left; vertical-align: top; }
    .clp-diff-add { color: #166534; } .clp-diff-del { color: #991b1b; text-decoration: line-through; }
    .clp-stepper { position: sticky; bottom: 0; display: flex; gap: .5rem; justify-content: space-between; padding: .75rem 0; background: var(--clp-bg, inherit); }
    .clp-stepper .clp-btn { flex: 1; justify-content: center; padding: .9rem; font-size: 1rem; }
    .clp-notice { border: 1px solid #f59e0b; background: #fffbeb; color: #78350f; border-radius: .6rem; padding: .6rem .9rem; margin-bottom: 1rem; }
</style>
