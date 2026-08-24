<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pago simulado — {{ $order['folio'] }}</title>
    <style>
        body { font-family: ui-sans-serif, system-ui, sans-serif; max-width: 26rem; margin: 3rem auto; padding: 0 1rem; color: #1c1917; }
        .card { border: 1px solid #e7e5e4; border-radius: 10px; padding: 1.5rem; text-align: center; }
        h1 { font-size: 1.2rem; }
        .total { font-size: 1.6rem; font-weight: 700; margin: 0.5rem 0 1.25rem; }
        button { font: inherit; padding: 0.6rem 1rem; border-radius: 6px; border: 0; cursor: pointer; }
        .pay { background: #16a34a; color: #fff; width: 100%; margin-bottom: 0.5rem; }
        .cancel { background: #f3f4f6; color: #44403c; width: 100%; }
        .note { color: #78716c; font-size: 0.8rem; margin-top: 1rem; }
        .ok { color: #166534; font-size: 1.1rem; font-weight: 600; }
        .err { color: #b91c1c; font-size: 0.9rem; }
        [hidden] { display: none; }
    </style>
</head>
<body>
    <div class="card" id="pay">
        <h1>Pago simulado</h1>
        <p>Pedido {{ $order['folio'] }}</p>
        <p class="total">${{ $order['total'] }}</p>

        {{-- Dispara el webhook aprobado, igual que lo haría la pasarela real (servidor a servidor → JSON). Se envía por
             fetch para no dejar al cliente mirando el JSON crudo y mostrarle un cierre limpio. --}}
        <form id="pay-form" method="POST" action="/t/{{ $slug }}/webhook/fake">
            <input type="hidden" name="reference" value="{{ $order['ulid'] }}">
            <input type="hidden" name="approved" value="1">
            <input type="hidden" name="amount" value="{{ $order['total'] }}">
            <button type="submit" class="pay">Aprobar pago</button>
        </form>
        <a href="/t/{{ $slug }}"><button type="button" class="cancel">Cancelar</button></a>

        <p class="err" id="err" hidden>No se pudo confirmar el pago. Intenta de nuevo.</p>
        <p class="note">Pasarela de prueba (desarrollo). No se cobra nada.</p>
    </div>

    <div class="card" id="done" hidden>
        <p class="ok">✓ Pago aprobado</p>
        <p>Tu pedido {{ $order['folio'] }} quedó pagado.</p>
        <a href="/t/{{ $slug }}"><button type="button" class="pay">Volver a la tienda</button></a>
    </div>

    <script>
        const form = document.getElementById('pay-form');
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = form.querySelector('button');
            const err = document.getElementById('err');
            btn.disabled = true; btn.textContent = 'Procesando…'; err.hidden = true;
            try {
                const res = await fetch(form.action, {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: new URLSearchParams(new FormData(form)),
                });
                if (!res.ok) throw new Error('rechazado');
                document.getElementById('pay').hidden = true;
                document.getElementById('done').hidden = false;
            } catch {
                btn.disabled = false; btn.textContent = 'Aprobar pago'; err.hidden = false;
            }
        });
    </script>
</body>
</html>
