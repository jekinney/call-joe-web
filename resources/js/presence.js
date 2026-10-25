// For a signed-in page in /app: tell the server "I'm here" (so contacts know you're online), and listen on your
// private channel for live events. The server's Presence class decides online/offline from these check-ins.
// Livewire's wire:navigate swaps pages without reloading this script, so setup re-runs after every navigation.
const HEARTBEAT_MS = 45 * 1000; // matches Presence::HEARTBEAT_SECONDS
const meta = (name) => document.querySelector(`meta[name="${name}"]`)?.content;

let listeningAs = null;
let heartbeat = null;

function checkIn() {
    const url = meta('presence-url');
    if (!url || document.visibilityState === 'hidden') return;
    fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': meta('csrf-token') ?? '', Accept: 'application/json' } }).catch(() => {});
}

// A small notice in the corner, gone after a few seconds.
export function showNotice(message) {
    const box = document.createElement('div');
    box.setAttribute('role', 'status');
    box.dataset.notice = '';
    box.className = 'fixed bottom-4 right-4 z-50 max-w-sm rounded-xl bg-zinc-900 px-4 py-3 text-sm text-white shadow-lg ring-1 ring-white/10';
    box.textContent = message;
    document.body.append(box);
    setTimeout(() => box.remove(), 6000);
}

function setUp() {
    const userId = meta('user-id') ?? null;
    if (userId === listeningAs) return;

    if (listeningAs) window.Echo?.leave(`App.Models.User.${listeningAs}`);
    listeningAs = userId;
    if (!userId) return;

    window.Echo?.private(`App.Models.User.${userId}`).listen('.notice', (e) => showNotice(e.message));
    checkIn();
    heartbeat ??= setInterval(checkIn, HEARTBEAT_MS);
}

document.addEventListener('visibilitychange', checkIn);
document.addEventListener('livewire:navigated', setUp);
setUp();
