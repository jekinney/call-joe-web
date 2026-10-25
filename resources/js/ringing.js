// A contact calling (App\Events\ContactCalling, over Reverb): the open page rings, with Answer and Decline.
// Answer opens the call page, which picks up (livewire/app/call). Decline, or 30 seconds of ringing, makes it a
// missed call on the server, which then sends 'ring-stopped' so every open page goes quiet.
const meta = (name) => document.querySelector(`meta[name="${name}"]`)?.content;

let screen = null;
let tone = null;

function startTone() {
    try {
        const audio = new AudioContext();
        const gain = audio.createGain();
        gain.gain.value = 0;
        gain.connect(audio.destination);
        for (const frequency of [440, 480]) {
            const oscillator = audio.createOscillator();
            oscillator.frequency.value = frequency;
            oscillator.connect(gain);
            oscillator.start();
        }
        // Like a phone: two seconds on, four off.
        const ring = () => {
            gain.gain.setValueAtTime(0.06, audio.currentTime);
            gain.gain.setValueAtTime(0, audio.currentTime + 2);
        };
        ring();
        tone = { audio, timer: setInterval(ring, 6000) };
    } catch {
        tone = null; // no sound allowed yet: the screen still shows
    }
}

function stopRinging(callId = null) {
    if (!screen || (callId !== null && Number(screen.dataset.callId) !== callId)) return;
    screen.remove();
    screen = null;
    if (tone) {
        clearInterval(tone.timer);
        tone.audio.close();
        tone = null;
    }
}

export function ring(call) {
    stopRinging();
    screen = document.createElement('div');
    screen.dataset.ringing = '';
    screen.dataset.callId = String(call.call_id);
    screen.setAttribute('role', 'alertdialog');
    screen.setAttribute('aria-label', `${call.name} is calling`);
    screen.className = 'fixed inset-0 z-50 flex flex-col items-center justify-center gap-6 bg-zinc-950/95 text-white';

    const face = call.avatar ? document.createElement('img') : document.createElement('div');
    face.className = 'size-32 rounded-full object-cover ring-4 ring-emerald-500 animate-pulse flex items-center justify-center text-5xl bg-zinc-700';
    if (call.avatar) face.src = call.avatar;
    else face.textContent = call.name.charAt(0);

    const name = document.createElement('div');
    name.className = 'text-center';
    name.innerHTML = '<div class="text-2xl font-semibold"></div><div class="text-zinc-300">is calling…</div>';
    name.firstChild.textContent = call.name;

    const buttons = document.createElement('div');
    buttons.className = 'flex gap-10';
    const button = (label, color, onClick) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.textContent = label;
        b.className = `rounded-full px-6 py-3 font-medium ${color}`;
        b.addEventListener('click', onClick);
        buttons.append(b);
    };
    button('Decline', 'bg-red-600 hover:bg-red-500', () => {
        stopRinging();
        fetch(call.decline_url, { method: 'POST', headers: { 'X-CSRF-TOKEN': meta('csrf-token') ?? '', Accept: 'application/json' } }).catch(() => {});
    });
    button('Answer', 'bg-emerald-600 hover:bg-emerald-500', () => {
        stopRinging();
        // Navigating within the page keeps the click's permission to use the microphone and play sound.
        window.Livewire ? window.Livewire.navigate(call.answer_url) : (window.location.href = call.answer_url);
    });

    screen.append(face, name, buttons);
    document.body.append(screen);
    startTone();
}

export function listenForCalls(channel) {
    channel.listen('.ringing', (call) => ring(call)).listen('.ring-stopped', (e) => stopRinging(e.call_id));
}
