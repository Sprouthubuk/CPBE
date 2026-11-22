@php($games = $this->games())
<x-filament-panels::page>
    <div x-data="{ src: @js($games[0]['looks'][0]['url'] . '&embed=1'), name: @js($games[0]['label'] . ' · Classic') }" class="cp-games">
        <p class="cp-games-note">Every game and every look it comes in. They play here with made-up tickets: nothing is bought or won. A real result is fixed when a ticket is bought; the game only shows it.</p>
        <div class="cp-games-grid">
            <nav class="cp-games-list" aria-label="Games">
                @foreach ($games as $g)
                    <div class="cp-game">
                        <div class="cp-game-name">{{ $g['label'] }}</div>
                        <div class="cp-game-looks">
                            @foreach ($g['looks'] as $look)
                                <button type="button" class="cp-look"
                                    :class="src === @js($look['url'] . '&embed=1') && 'is-on'"
                                    x-on:click="src = @js($look['url'] . '&embed=1'); name = @js($g['label'] . ' · ' . $look['label']); if (window.innerWidth < 1024) $nextTick(() => $refs.frame.scrollIntoView({ behavior: 'smooth', block: 'start' }))">{{ $look['label'] }}</button>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </nav>
            <section class="cp-games-view">
                <div class="cp-games-bar">
                    <strong x-text="name"></strong>
                    <span class="cp-games-actions">
                        <button type="button" class="cp-look" x-on:click="$refs.frame.src = src">Play Again</button>
                        <a class="cp-look" :href="src.replace('&embed=1', '')" target="_blank" rel="noopener">Open In New Tab</a>
                    </span>
                </div>
                <iframe x-ref="frame" :src="src" title="Game preview" class="cp-games-frame"></iframe>
            </section>
        </div>
    </div>
    <style>
        .cp-games-note{font-size:.875rem;opacity:.75;margin:0 0 1rem}
        .cp-games-grid{display:grid;gap:1rem;grid-template-columns:minmax(0,1fr)}
        @media (min-width:1024px){.cp-games-grid{grid-template-columns:300px minmax(0,1fr)}}
        .cp-games-list{display:flex;flex-direction:column;gap:.5rem;max-height:80vh;overflow:auto;padding-right:.25rem}
        .cp-game{border:1px solid rgba(255,255,255,.1);border-radius:.75rem;padding:.6rem .75rem;background:rgba(255,255,255,.02)}
        .cp-game-name{font-weight:800;margin-bottom:.4rem}
        .cp-game-looks{display:flex;flex-wrap:wrap;gap:.35rem}
        .cp-look{display:inline-flex;align-items:center;min-height:2rem;padding:.25rem .7rem;border-radius:999px;border:1px solid rgba(255,255,255,.18);font-size:.8rem;font-weight:700;background:transparent;color:inherit;text-decoration:none;cursor:pointer}
        .cp-look:hover{border-color:#2FD36B}
        .cp-look.is-on{background:#2FD36B;border-color:#2FD36B;color:#fff}
        .cp-games-view{border:1px solid rgba(255,255,255,.1);border-radius:.75rem;overflow:hidden;background:#07090A}
        .cp-games-bar{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;justify-content:space-between;padding:.6rem .75rem;border-bottom:1px solid rgba(255,255,255,.08)}
        .cp-games-actions{display:flex;gap:.4rem}
        .cp-games-frame{display:block;width:100%;height:min(820px,80vh);border:0;background:#07090A}
    </style>
</x-filament-panels::page>
