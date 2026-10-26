# Call Joe voice service

One Pipecat pipeline per call, speaking with Gemini Live (speech in, speech out, one Gemini key). Contacts' voices are Gemini's built-in voices (`config/contacts.php` → `voices`); there are no cloned voices for now.

How a call goes:

1. Laravel (`App\Services\Calling::start`) builds the contact's prompt and memory and asks this service for a session (`POST /sessions`, shared secret). The prompt stays here; the browser only gets the session id.
2. The browser connects over WebRTC (`POST /api/offer?session=…`, then `PATCH` for network candidates; `resources/js/voice.js`).
3. When the call ends (hang up, dropped connection, or the plan's time limit), the service reports the length, transcript and token usage to Laravel (`POST /webhooks/voice`). Laravel prices the usage at the call's model, uses up the call time, and updates the contact's memory.

## Run it (development)

The service runs on your machine, not in Docker (WebRTC needs real UDP between the browser and the service). Laravel's containers reach it at `host.docker.internal:8787`.

```bash
curl -LsSf https://astral.sh/uv/install.sh | sh        # once: uv gets Python 3.12 (Pipecat needs 3.11+)
cd voice
uv venv --python 3.12 .venv
uv pip install --python .venv/bin/python -r requirements.txt
.venv/bin/python -m uvicorn app:app --host 0.0.0.0 --port 8787
```

It reads the project's `.env` (one folder up): `GEMINI_API_KEY`, `VOICE_SECRET` (any long random string; Laravel uses the same one), and optionally `VOICE_REPORT_URL` (default `APP_URL/webhooks/voice`) and `VOICE_ALLOWED_ORIGINS` (default `APP_URL`).

`GET /health` says whether the key and secret are set.

## Try a call without a browser

`test_call.py` connects the way the browser does, plays a speech clip as the microphone, and records what the contact says back. Start a call in Laravel to get a connect URL, then:

```bash
.venv/bin/python test_call.py "<connect url>" speech.wav reply.wav 30
```

Leave some silence before and after the clip: a live microphone never stops streaming, and Gemini decides a turn is over from the silence.

## Production

Browsers need a route for UDP to this service: a host with a public IP and a UDP port range open (set a TURN server in `SmallWebRTCRequestHandler(ice_servers=…)` for users behind strict networks), or swap the transport for Daily or LiveKit. Set `VOICE_URL` (how Laravel reaches it), `VOICE_PUBLIC_URL` (how browsers do, over HTTPS) and `VOICE_ALLOWED_ORIGINS`.
