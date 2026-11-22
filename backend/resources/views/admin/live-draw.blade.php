<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Live Draw · {{ $c->title }}</title>
<style>
  html,body{margin:0;height:100%;background:#07090c;color:#fff;font-family:Barlow,system-ui,-apple-system,Segoe UI,Arial,sans-serif}
  .wrap{min-height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:28px;padding:32px;box-sizing:border-box;text-align:center}
  .brand{letter-spacing:.2em;text-transform:uppercase;color:#2FD36B;font-weight:700}
  h1{font-size:clamp(28px,5vw,56px);margin:0;max-width:1100px}
  .ticket{font-family:"JetBrains Mono",ui-monospace,monospace;font-size:clamp(64px,16vw,180px);font-weight:800;color:#2FD36B;letter-spacing:.06em;
    will-change:transform;min-height:1.1em;transition:transform .3s}
  .ticket.done{transform:scale(1.08)}
  .who{font-size:clamp(28px,5vw,60px);font-weight:700;opacity:0;transform:translateY(16px);transition:opacity .6s,transform .6s}
  .who.on{opacity:1;transform:none}
  .prize{color:#bfc7d1;font-size:clamp(18px,2.4vw,28px)}
  button{font:inherit;font-size:22px;font-weight:700;padding:16px 40px;border-radius:14px;border:0;background:#2FD36B;color:#07090c;cursor:pointer}
  button[disabled]{opacity:.4;cursor:default}
  .verify{color:#8b95a1;font-size:16px}
  .none{color:#bfc7d1;font-size:24px}
</style>
</head>
<body>
<div class="wrap">
  <div class="brand">{{ config('app.name') }} · Live Draw</div>
  <h1>{{ $c->title }}</h1>
  @if ($winners->isEmpty())
    <div class="none">No winner yet. Draw it from Admin → Live Draw, then reload.</div>
  @else
    <div class="prize" id="prize">{{ $winners[0]['prize'] }}</div>
    <div class="ticket" id="ticket" aria-live="polite">#····</div>
    <div class="who" id="who"></div>
    <button id="go">Reveal Winner</button>
    <div class="verify">Drawn by Verity from a public drand beacon round · check it at /verify/{{ $ref }}</div>
  @endif
</div>
@if ($winners->isNotEmpty())
<script>
  // Presentation only: the numbers below are the result Verity already drew.
  const winners = @json($winners);
  const max = {{ (int) $c->max_tickets }};
  let i = 0;
  const t = document.getElementById('ticket'), who = document.getElementById('who'), go = document.getElementById('go'), prize = document.getElementById('prize');
  go.addEventListener('click', () => {
    const w = winners[i];
    go.disabled = true; who.classList.remove('on'); t.classList.remove('done'); prize.textContent = w.prize;
    const start = performance.now(), len = 4500;
    const spin = (now) => {
      const p = Math.min(1, (now - start) / len);
      if (p < 1) { t.textContent = '#' + (1 + Math.floor(Math.random() * max)); setTimeout(() => requestAnimationFrame(spin), 40 + p * p * 260); return; }
      t.textContent = w.ticket ? '#' + w.ticket : 'Postal entry'; t.classList.add('done');
      who.textContent = w.name; who.classList.add('on');
      i++;
      if (i < winners.length) { go.textContent = 'Next Winner (' + (i + 1) + ' of ' + winners.length + ')'; go.disabled = false; } else { go.textContent = 'All Winners Revealed'; }
    };
    requestAnimationFrame(spin);
  });
</script>
@endif
</body>
</html>
