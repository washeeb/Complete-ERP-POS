// Built-in shop: live prices and availability over Reverb (public channel storefront.{id}).
// Connection details come from the page (data-realtime), so keys are never baked into the build.
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

const root = document.querySelector('[data-shop]');
const rt = root && root.dataset.realtime ? JSON.parse(root.dataset.realtime) : null;
const exponent = Number(root?.dataset.exponent || 2);
const number = new Intl.NumberFormat('en', { minimumFractionDigits: exponent, maximumFractionDigits: exponent });
// Same look as the server's Money::display: its rendering of zero, with the amount swapped in.
const money = (minor) => (root.dataset.zero || '0').replace(/[\d.,]+/, number.format(minor / 10 ** exponent));

function apply(e) {
    const card = document.querySelector(`[data-product="${e.id}"]`);
    if (!card) return;
    if (e.price_minor !== null && e.price_minor !== undefined) {
        card.querySelector('[data-price]').textContent = money(e.price_minor);
    }
    const stock = card.querySelector('[data-stock]');
    const button = card.querySelector('button');
    const out = !e.for_sale || (e.available !== null && e.available <= 0);
    if (stock) stock.textContent = e.available === null ? '' : out ? 'Out of stock' : `${e.available} available`;
    if (button) button.disabled = out;
    card.classList.toggle('out', out);
    card.classList.add('flash');
    setTimeout(() => card.classList.remove('flash'), 1200);
}

if (rt) {
    window.Pusher = Pusher;
    const echo = new Echo({
        broadcaster: 'reverb', key: rt.key, wsHost: rt.host, wsPort: rt.port, wssPort: rt.port,
        forceTLS: rt.scheme === 'https', enabledTransports: ['ws', 'wss'],
    });
    const live = document.querySelector('[data-live]');
    echo.connector.pusher.connection.bind('state_change', (s) => { if (live) live.dataset.live = s.current; });
    echo.channel(`storefront.${root.dataset.shop}`)
        .listen('.product.updated', apply)
        .listen('.catalog.reload', () => window.location.reload());
}
