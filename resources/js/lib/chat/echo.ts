import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// Echo's reverb/pusher connector looks for a global `Pusher` rather than importing
// pusher-js itself (keeps it out of Echo's own bundle for consumers who don't need it).
(window as unknown as { Pusher: typeof Pusher }).Pusher = Pusher;

let instance: Echo<'reverb'> | null = null;

/**
 * Lazily creates a single shared Reverb-backed Echo connection for the whole app.
 * Deliberately connects to `window.location.hostname` (whatever host/protocol the
 * page itself was loaded on) rather than a fixed build-time host: this server is
 * reachable both by IP and by domain name but its TLS cert only covers the IP, so
 * a hardcoded domain here would silently fail certificate validation for anyone
 * browsing by IP (and vice versa) — matching the current page's own origin is the
 * only way this always lines up with whatever cert is actually being served.
 */
export function getEcho(): Echo<'reverb'> {
    if (instance) return instance;

    const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
    const isHttps = window.location.protocol === 'https:';
    const port = window.location.port ? Number(window.location.port) : (isHttps ? 443 : 80);

    instance = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY as string,
        wsHost: window.location.hostname,
        wsPort: port,
        wssPort: port,
        // Reverb is proxied under /reverb-ws/ (not the default /app/), since this app
        // already has its own real /app route — see nginx site config + REVERB_SERVER_PATH.
        wsPath: '/reverb-ws',
        forceTLS: isHttps,
        enabledTransports: ['ws', 'wss'],
        authEndpoint: '/broadcasting/auth',
        auth: { headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' } },
    });

    return instance;
}
