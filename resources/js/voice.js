// Voice calls in the browser: plain WebRTC to our voice service (voice/app.py, Pipecat speaking with Gemini Live).
// The call page (livewire/app/call) asks the server to set up the call, then sends 'voice-start' with only the
// connect URL for that call's session: the contact's prompt and memory never reach the browser. Call events go back
// to the page as Livewire events.
let session = null;

const tell = (name, params = {}) => window.Livewire?.dispatch(name, params);

async function start(connectUrl) {
    const stream = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true } });
    const pc = new RTCPeerConnection({ iceServers: [{ urls: 'stun:stun.l.google.com:19302' }] });
    const audio = new Audio();
    audio.autoplay = true;
    const current = { pc, stream, audio, ended: false, context: null, timer: null };
    session = current;

    stream.getAudioTracks().forEach((track) => pc.addTrack(track, stream));
    pc.addTransceiver('video', { direction: 'recvonly' }); // the service's transport expects a video line in the offer

    pc.ontrack = (event) => {
        if (event.track.kind !== 'audio') return;
        audio.srcObject = event.streams[0];
        watchSpeech(current, event.streams[0]);
    };
    pc.onconnectionstatechange = () => {
        if (pc.connectionState === 'connected') tell('voice-connected');
        if (['failed', 'disconnected', 'closed'].includes(pc.connectionState)) finish(current);
    };

    // Send our network addresses to the service as they're found.
    let pcId = null;
    const queued = [];
    const send = (candidates) => fetch(connectUrl.split('?')[0], {
        method: 'PATCH', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ pc_id: pcId, candidates }),
    }).catch(() => {});
    pc.onicecandidate = (event) => {
        if (!event.candidate) return;
        const candidate = { candidate: event.candidate.candidate, sdp_mid: event.candidate.sdpMid, sdp_mline_index: event.candidate.sdpMLineIndex };
        pcId ? send([candidate]) : queued.push(candidate);
    };

    await pc.setLocalDescription(await pc.createOffer());
    const response = await fetch(connectUrl, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ sdp: pc.localDescription.sdp, type: pc.localDescription.type }),
    });
    if (!response.ok) throw new Error(`The voice service answered ${response.status}`);
    const answer = await response.json();
    pcId = answer.pc_id;
    await pc.setRemoteDescription({ type: answer.type, sdp: answer.sdp });
    if (queued.length) send(queued.splice(0));
}

// Lights the ring around the contact while they talk.
function watchSpeech(current, remote) {
    try {
        current.context = new AudioContext();
        const analyser = current.context.createAnalyser();
        analyser.fftSize = 512;
        current.context.createMediaStreamSource(remote).connect(analyser);
        const data = new Uint8Array(analyser.fftSize);
        let talking = false;
        current.timer = setInterval(() => {
            analyser.getByteTimeDomainData(data);
            const loud = data.some((v) => Math.abs(v - 128) > 6);
            if (loud !== talking) {
                talking = loud;
                window.dispatchEvent(new CustomEvent('voice-speech', { detail: { talking } }));
            }
        }, 120);
    } catch {
        // No speaking ring; the call still works.
    }
}

function finish(current, notify = true) {
    if (current.ended) return;
    current.ended = true;
    clearInterval(current.timer);
    current.context?.close().catch(() => {});
    current.stream.getTracks().forEach((track) => track.stop());
    current.audio.srcObject = null;
    current.pc.close();
    window.dispatchEvent(new CustomEvent('voice-speech', { detail: { talking: false } }));
    if (session === current) session = null;
    if (notify) tell('voice-ended');
}

window.addEventListener('voice-start', async (event) => {
    try {
        await start(event.detail.connectUrl);
    } catch (error) {
        if (session) finish(session, false);
        tell('voice-error', { message: String(error?.message ?? error) });
    }
});

window.addEventListener('voice-stop', () => session && finish(session));

window.addEventListener('voice-toggle-mute', () => {
    if (!session) return;
    const tracks = session.stream.getAudioTracks();
    const muted = tracks.some((track) => track.enabled);
    tracks.forEach((track) => { track.enabled = !muted; });
    window.dispatchEvent(new CustomEvent('voice-muted', { detail: { muted } }));
});

// Leaving the page ends the call.
document.addEventListener('livewire:navigating', () => session && finish(session, false));
