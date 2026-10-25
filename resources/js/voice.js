// Voice calls in the browser with Vapi's web SDK. The call page (livewire/app/call) asks the server to set up the
// call, then sends 'vapi-start' with only the public key and that call's assistant id: the contact's prompt and
// memory never reach the browser. Call events go back to the page as Livewire events.
let vapi = null;

async function client(publicKey) {
    if (!vapi) {
        const { default: Vapi } = await import('@vapi-ai/web');
        vapi = new Vapi(publicKey);
        vapi.on('call-start', () => {});
        vapi.on('call-end', () => window.Livewire?.dispatch('vapi-ended'));
        vapi.on('speech-start', () => window.dispatchEvent(new CustomEvent('vapi-speech', { detail: { talking: true } })));
        vapi.on('speech-end', () => window.dispatchEvent(new CustomEvent('vapi-speech', { detail: { talking: false } })));
        vapi.on('error', (error) => window.Livewire?.dispatch('vapi-error', { message: String(error?.message ?? error ?? '') }));
    }
    return vapi;
}

window.addEventListener('vapi-start', async (event) => {
    const { publicKey, assistantId, callId } = event.detail;
    try {
        const call = await (await client(publicKey)).start(assistantId, { metadata: { call_id: callId } });
        window.Livewire?.dispatch('vapi-connected', { vapiCallId: call?.id ?? null });
    } catch (error) {
        window.Livewire?.dispatch('vapi-error', { message: String(error?.message ?? error) });
    }
});

window.addEventListener('vapi-stop', () => vapi?.stop());

window.addEventListener('vapi-toggle-mute', () => {
    if (!vapi) return;
    const muted = !vapi.isMuted();
    vapi.setMuted(muted);
    window.dispatchEvent(new CustomEvent('vapi-muted', { detail: { muted } }));
});

// Leaving the page ends the call.
document.addEventListener('livewire:navigating', () => vapi?.stop());
