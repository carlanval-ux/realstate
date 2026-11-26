{{-- Floating «Preguntar a CLARTO» (App\Filament\ManualChat). The conversation is kept per browser tab and user. --}}
<div
    class="cl-chat"
    x-data="{
        open: $persist(false).using(sessionStorage).as(@js('clarto-chat-open-'.auth()->id())),
        messages: $persist([]).using(sessionStorage).as(@js('clarto-chat-'.auth()->id())),
        q: '',
        busy: false,
        async send() {
            const question = this.q.trim();
            if (question === '' || this.busy) return;
            this.q = '';
            this.messages.push({ role: 'user', text: question });
            this.busy = true;
            this.scroll();
            try {
                const reply = await $wire.ask(question);
                this.messages.push({ role: 'bot', entries: reply.entries });
            } catch (e) {
                this.messages.push({ role: 'bot', error: true });
            }
            this.busy = false;
            this.scroll();
        },
        show() {
            this.open = true;
            this.scroll();
            this.$nextTick(() => this.$refs.input.focus());
        },
        scroll() {
            this.$nextTick(() => { if (this.$refs.log) this.$refs.log.scrollTop = this.$refs.log.scrollHeight; });
        },
    }"
    x-init="if (open) scroll()"
    x-on:keydown.escape.window="open = false"
>
    <style>
        /* Room below the page so the button never covers the last row's actions. */
        .fi-main { padding-bottom: 5.5rem; }
        .cl-chat__fab { position: fixed; right: 1rem; bottom: 1rem; z-index: 30; display: flex; align-items: center; gap: .5rem;
            padding: .75rem 1rem; border-radius: 9999px; background: var(--primary-600); color: #fff; font-weight: 600; font-size: .875rem;
            box-shadow: 0 6px 20px rgb(0 0 0 / .2); }
        .cl-chat__fab:hover { background: var(--primary-500); }
        .cl-chat__fab svg { width: 1.35rem; height: 1.35rem; }
        .cl-chat__panel { position: fixed; right: 1rem; bottom: 1rem; z-index: 30; width: 24rem; height: min(36rem, calc(100vh - 2rem));
            display: flex; flex-direction: column; border-radius: .9rem; overflow: hidden; background: #fff; color: var(--gray-950);
            box-shadow: 0 12px 40px rgb(0 0 0 / .25); border: 1px solid var(--gray-200); }
        .dark .cl-chat__panel { background: var(--gray-900); color: var(--gray-100); border-color: var(--gray-700); }
        @media (max-width: 640px) {
            .cl-chat__panel { inset: 0; width: auto; height: auto; border-radius: 0; border: 0; }
            .cl-chat__fab span { display: none; }
        }
        .cl-chat__head { display: flex; align-items: center; gap: .5rem; padding: .75rem 1rem; background: var(--primary-600); color: #fff; }
        .cl-chat__head strong { flex: 1; font-size: .95rem; }
        .cl-chat__head button { padding: .25rem .4rem; border-radius: .4rem; font-size: .8rem; }
        .cl-chat__head button:hover { background: rgb(255 255 255 / .15); }
        .cl-chat__log { flex: 1; overflow-y: auto; padding: 1rem; display: flex; flex-direction: column; gap: .75rem; font-size: .875rem; line-height: 1.5; }
        .cl-chat__msg { max-width: 90%; padding: .6rem .8rem; border-radius: .75rem; background: var(--gray-100); }
        .dark .cl-chat__msg { background: var(--gray-800); }
        .cl-chat__msg--user { align-self: flex-end; background: var(--primary-600); color: #fff; }
        .cl-chat__entry { display: block; margin-top: .5rem; padding: .5rem .6rem; border-radius: .5rem; background: #fff; border: 1px solid var(--gray-200); text-decoration: none; }
        .dark .cl-chat__entry { background: var(--gray-900); border-color: var(--gray-700); }
        .cl-chat__entry:hover { border-color: var(--primary-500); }
        .cl-chat__entry b { display: block; color: var(--primary-600); }
        .cl-chat__entry small { display: block; color: var(--gray-500); }
        .cl-chat__form { display: flex; gap: .5rem; padding: .75rem; border-top: 1px solid var(--gray-200); }
        .dark .cl-chat__form { border-color: var(--gray-700); }
        .cl-chat__form input { flex: 1; min-width: 0; padding: .55rem .75rem; border-radius: .5rem; border: 1px solid var(--gray-300); background: transparent; font-size: 1rem; }
        .cl-chat__form button { padding: .55rem .9rem; border-radius: .5rem; background: var(--primary-600); color: #fff; font-weight: 600; font-size: .875rem; }
        .cl-chat__form button:disabled { opacity: .5; }
    </style>

    <button type="button" class="cl-chat__fab" x-show="! open" x-on:click="show()" aria-label="{{ __('Ask CLARTO') }}">
        <x-filament::icon icon="heroicon-o-chat-bubble-left-right" />
        <span>{{ __('Ask CLARTO') }}</span>
    </button>

    <section class="cl-chat__panel" x-show="open" x-cloak role="dialog" aria-label="{{ __('Ask CLARTO') }}">
        <header class="cl-chat__head">
            <strong>{{ __('Ask CLARTO') }}</strong>
            <button type="button" x-show="messages.length" x-on:click="messages = []">{{ __('New conversation') }}</button>
            <button type="button" x-on:click="open = false" aria-label="{{ __('Close') }}">✕</button>
        </header>

        <div class="cl-chat__log" x-ref="log" aria-live="polite">
            <div class="cl-chat__msg">{{ $forCollaborators ? __('Ask me how to do something in your panel and I will show you where your manual explains it.') : __('Ask how to do something in the panel and I will show you where the user manual explains it.') }}</div>

            <template x-for="(message, index) in messages" :key="index">
                <div class="cl-chat__msg" :class="message.role === 'user' && 'cl-chat__msg--user'">
                    <template x-if="message.role === 'user'"><span x-text="message.text"></span></template>
                    <template x-if="message.role === 'bot' && message.error"><span>{{ $forCollaborators ? __('Something went wrong. Try again.') : __('Something went wrong. Please try again.') }}</span></template>
                    <template x-if="message.role === 'bot' && ! message.error && message.entries.length === 0">
                        <span>{{ $forCollaborators ? __('I found nothing about that in your manual. Try other words or') : __('I found nothing about that in the manual. Try other words or') }} <a href="{{ $manualUrl }}" class="underline">{{ $forCollaborators ? __('open your user manual') : __('open the user manual') }}</a>.</span>
                    </template>
                    <template x-if="message.role === 'bot' && ! message.error && message.entries.length">
                        <div>
                            <span>{{ __('This is where the manual explains it:') }}</span>
                            <template x-for="entry in message.entries" :key="entry.url">
                                <a class="cl-chat__entry" :href="entry.url">
                                    <b x-text="entry.title"></b>
                                    <small x-show="entry.section" x-text="entry.section"></small>
                                    <span x-text="entry.excerpt"></span>
                                </a>
                            </template>
                        </div>
                    </template>
                </div>
            </template>

            <div class="cl-chat__msg" x-show="busy">{{ __('Searching…') }}</div>
        </div>

        <form class="cl-chat__form" x-on:submit.prevent="send()">
            <input x-ref="input" x-model="q" type="text" maxlength="{{ \App\Filament\ManualChat::MAX_QUESTION }}" placeholder="{{ $forCollaborators ? __('Type your question') : __('Write your question') }}" aria-label="{{ $forCollaborators ? __('Type your question') : __('Write your question') }}" autocomplete="off" />
            <button type="submit" :disabled="busy || q.trim() === ''">{{ __('Send') }}</button>
        </form>
    </section>
</div>
