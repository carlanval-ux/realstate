{{-- Content blocks ($blocks) of a version; $anchor(i) optional id prefix («contenido-»). Attachments only through the private route. --}}
@foreach ($blocks as $i => $block)
    @php($data = (array) ($block['data'] ?? []))
    <div @isset($anchorPrefix) id="{{ $anchorPrefix }}{{ $i + 1 }}" style="scroll-margin-top:5rem" @endisset>
        @switch($block['type'] ?? '')
            @case('text')
                <div class="clp-prose">{{ $this->md($data['markdown'] ?? null) }}</div>
                @break
            @case('callout')
                <div class="clp-callout clp-callout--{{ in_array($data['tone'] ?? '', ['info', 'aviso', 'importante'], true) ? $data['tone'] : 'info' }} clp-prose">{{ $this->md($data['markdown'] ?? null) }}</div>
                @break
            @case('image')
                @if ($url = $this->attachmentUrl($data['attachment_id'] ?? null))
                    <figure style="margin:.6rem 0">
                        <img class="clp-img" src="{{ $url }}" alt="{{ $data['alt'] ?? '' }}" loading="lazy" @click="zoom = $el.src">
                        @if (filled($data['caption'] ?? null))<figcaption class="clp-meta">{{ $data['caption'] }} · {{ __('procesos.ver.ampliar') }}</figcaption>@endif
                    </figure>
                @endif
                @break
            @case('video')
                @if ($url = $this->attachmentUrl($data['attachment_id'] ?? null))
                    <video controls preload="metadata" src="{{ $url }}" style="max-width:100%;border-radius:.5rem"></video>
                @elseif (preg_match('#^https://(www\.)?(youtube\.com|youtu\.be|vimeo\.com)/#', (string) ($data['url'] ?? '')))
                    <p><a class="clp-btn" href="{{ $data['url'] }}" target="_blank" rel="noopener noreferrer">▶ {{ $data['caption'] ?: __('procesos.ver.ver_video') }}</a></p>
                @endif
                @if (filled($data['caption'] ?? null) && ($data['attachment_id'] ?? null))<p class="clp-meta">{{ $data['caption'] }}</p>@endif
                @break
            @case('file')
                @if ($url = $this->attachmentUrl($data['attachment_id'] ?? null))
                    <p><a class="clp-btn" href="{{ $url }}" target="_blank" rel="noopener">📄 {{ $data['label'] ?: __('procesos.campos.file') }}</a></p>
                @endif
                @break
            @case('link')
                @if ($url = \App\Processes\ProcessLinks::resolve($data['url'] ?? null, $teamId))
                    <p><a class="clp-btn" href="{{ $url }}" @if (str_starts_with($url, 'https://') && ! str_starts_with($url, url('/'))) target="_blank" rel="noopener noreferrer" @endif>🔗 {{ $data['label'] ?: $url }}</a></p>
                @endif
                @break
            @case('diagram')
                @if ($svg = $this->diagram($data['mermaid'] ?? null))
                    <div class="clp-mm-wrap" role="img" aria-label="{{ $data['caption'] ?? __('procesos.secciones.diagrama') }}">{!! $svg !!}</div>
                @elseif (filled($data['mermaid'] ?? null))
                    <pre style="white-space:pre-wrap;font-size:.8rem">{{ $data['mermaid'] }}</pre>
                @endif
                @if (filled($data['caption'] ?? null))<p class="clp-meta">{{ $data['caption'] }}</p>@endif
                @break
        @endswitch
    </div>
@endforeach
