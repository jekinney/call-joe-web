"""
Call Joe's voice service: one Pipecat pipeline per call, speaking with Gemini Live (speech in, speech out).

Laravel (App\\Support\\Voice\\PipecatClient) creates a session here with the contact's prompt, voice and the call's time
limit (POST /sessions, with the shared secret). The browser then connects over WebRTC (/api/offer?session=...) knowing only
that session id: the prompt never reaches it. When the call ends, this service reports the length, transcript and token
usage back to Laravel (/webhooks/voice, same secret).
"""

import asyncio
import os
import secrets
import time
import uuid
from pathlib import Path

import httpx
from fastapi import FastAPI, Header, HTTPException, Request
from fastapi.middleware.cors import CORSMiddleware
from loguru import logger
from pipecat.frames.frames import Frame, LLMRunFrame, MetricsFrame, TranscriptionFrame, TTSTextFrame
from pipecat.metrics.metrics import LLMUsageMetricsData
from pipecat.pipeline.pipeline import Pipeline
from pipecat.pipeline.runner import PipelineRunner
from pipecat.pipeline.task import PipelineParams, PipelineTask
from pipecat.processors.aggregators.llm_context import LLMContext
from pipecat.processors.aggregators.llm_response_universal import LLMContextAggregatorPair
from pipecat.processors.frame_processor import FrameDirection, FrameProcessor
from pipecat.services.google.gemini_live.llm import (
    ContextWindowCompressionParams,
    GeminiLiveLLMService,
)
from pipecat.transports.base_transport import TransportParams
from pipecat.transports.smallwebrtc.request_handler import (
    SmallWebRTCPatchRequest,
    SmallWebRTCRequest,
    SmallWebRTCRequestHandler,
)
from pipecat.transports.smallwebrtc.transport import SmallWebRTCTransport


def load_env() -> None:
    """Reads Laravel's .env (one folder up) for anything not already in the environment."""
    path = Path(__file__).resolve().parent.parent / ".env"
    if not path.exists():
        return
    for line in path.read_text().splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        os.environ.setdefault(key.strip(), value.strip().strip('"').strip("'"))


load_env()

SECRET = os.environ.get("VOICE_SECRET", "")
GEMINI_KEY = os.environ.get("GEMINI_API_KEY", "")
REPORT_URL = os.environ.get("VOICE_REPORT_URL") or os.environ.get("APP_URL", "http://localhost:8090").rstrip("/") + "/webhooks/voice"
ALLOWED_ORIGINS = [o for o in os.environ.get("VOICE_ALLOWED_ORIGINS", os.environ.get("APP_URL", "http://localhost:8090")).split(",") if o]
SECRET_HEADER = "X-Call-Joe-Secret"
SESSION_WAIT_SECONDS = 600

app = FastAPI(title="Call Joe voice")
app.add_middleware(CORSMiddleware, allow_origins=ALLOWED_ORIGINS, allow_methods=["*"], allow_headers=["*"])

# session id -> {"config": {...}, "created": monotonic seconds, "used": bool}
sessions: dict[str, dict] = {}
handler = SmallWebRTCRequestHandler()


def check_secret(value: str | None) -> None:
    if not SECRET or not value or not secrets.compare_digest(SECRET, value):
        raise HTTPException(status_code=401, detail="Not allowed.")


def forget_stale_sessions() -> None:
    now = time.monotonic()
    for sid in [s for s, v in sessions.items() if not v["used"] and now - v["created"] > SESSION_WAIT_SECONDS]:
        sessions.pop(sid, None)


@app.get("/health")
async def health() -> dict:
    return {"ok": True, "gemini_key": bool(GEMINI_KEY), "secret": bool(SECRET)}


@app.post("/sessions")
async def create_session(request: Request, x_call_joe_secret: str | None = Header(default=None)) -> dict:
    check_secret(x_call_joe_secret)
    body = await request.json()
    for field in ("call_id", "prompt", "voice", "model", "max_seconds"):
        if body.get(field) in (None, ""):
            raise HTTPException(status_code=422, detail=f"Missing {field}.")
    forget_stale_sessions()
    session_id = uuid.uuid4().hex
    sessions[session_id] = {"config": body, "created": time.monotonic(), "used": False}
    return {"id": session_id}


@app.delete("/sessions/{session_id}")
async def delete_session(session_id: str, x_call_joe_secret: str | None = Header(default=None)) -> dict:
    check_secret(x_call_joe_secret)
    sessions.pop(session_id, None)
    return {"ok": True}


@app.post("/api/offer")
async def offer(request: SmallWebRTCRequest, session: str = "") -> dict:
    existing = request.pc_id is not None
    entry = sessions.get(session)
    if entry is None or (entry["used"] and not existing):
        raise HTTPException(status_code=404, detail="That call isn't available.")

    async def start(connection) -> None:
        entry["used"] = True
        asyncio.create_task(run_call(session, entry["config"], connection))

    return await handler.handle_web_request(request=request, webrtc_connection_callback=start)


@app.patch("/api/offer")
async def ice(request: SmallWebRTCPatchRequest) -> dict:
    await handler.handle_patch_request(request)
    return {"status": "success"}


class CallRecorder:
    """Keeps the transcript and the token usage, fed by two taps in the pipeline."""

    def __init__(self) -> None:
        self.lines: list[tuple[str, str]] = []
        self.input_tokens = 0
        self.output_tokens = 0

    def add(self, speaker: str, text: str) -> None:
        text = text.strip()
        if not text:
            return
        if self.lines and self.lines[-1][0] == speaker:
            self.lines[-1] = (speaker, f"{self.lines[-1][1]} {text}")
        else:
            self.lines.append((speaker, text))

    def hear(self, frame: Frame) -> None:
        if isinstance(frame, TranscriptionFrame):
            self.add("User", frame.text)
        elif isinstance(frame, TTSTextFrame):
            self.add("AI", frame.text)
        elif isinstance(frame, MetricsFrame):
            for data in frame.data:
                if isinstance(data, LLMUsageMetricsData):
                    self.input_tokens += data.value.prompt_tokens or 0
                    self.output_tokens += data.value.completion_tokens or 0

    def transcript(self) -> str:
        return "\n".join(f"{speaker}: {text}" for speaker, text in self.lines)


class Tap(FrameProcessor):
    """Passes everything through, telling the recorder what goes by. Gemini Live sends what the user said upstream,
    so one tap sits before it (the user's words) and one after (the contact's words and the usage)."""

    def __init__(self, recorder: CallRecorder) -> None:
        super().__init__()
        self._recorder = recorder

    async def process_frame(self, frame: Frame, direction: FrameDirection) -> None:
        await super().process_frame(frame, direction)
        self._recorder.hear(frame)
        await self.push_frame(frame, direction)


async def report(payload: dict) -> None:
    """Tells Laravel; tries a few times because the length and transcript are all it gets."""
    for attempt in range(4):
        try:
            async with httpx.AsyncClient(timeout=15) as client:
                response = await client.post(REPORT_URL, json={"message": payload}, headers={SECRET_HEADER: SECRET})
            if response.status_code < 300:
                return
            logger.warning(f"Laravel answered {response.status_code} to the call report")
        except httpx.HTTPError as error:
            logger.warning(f"Couldn't reach Laravel with the call report: {error}")
        await asyncio.sleep(2 * (attempt + 1))
    logger.error("Gave up reporting a call to Laravel")


async def run_call(session_id: str, config: dict, connection) -> None:
    call_id = int(config["call_id"])
    max_seconds = int(config["max_seconds"])
    base = {"type": "", "assistant": {"id": session_id}, "call": {"assistantId": session_id, "metadata": {"call_id": call_id}}}

    transport = SmallWebRTCTransport(
        webrtc_connection=connection,
        params=TransportParams(audio_in_enabled=True, audio_out_enabled=True),
    )
    llm = GeminiLiveLLMService(
        api_key=GEMINI_KEY,
        settings=GeminiLiveLLMService.Settings(
            model=config["model"],
            voice=config["voice"],
            system_instruction=config["prompt"],
            context_window_compression=ContextWindowCompressionParams(enabled=True),
        ),
    )
    context = LLMContext([{"role": "user", "content": "The call just connected. Greet them the way you would on the phone."}])
    aggregators = LLMContextAggregatorPair(context)
    recorder = CallRecorder()
    task = PipelineTask(
        Pipeline([transport.input(), aggregators.user(), Tap(recorder), llm, Tap(recorder), transport.output(), aggregators.assistant()]),
        params=PipelineParams(enable_metrics=True, enable_usage_metrics=True),
    )

    started = {"at": None}
    reason = {"value": "customer-ended-call"}

    @transport.event_handler("on_client_connected")
    async def connected(_transport, _client) -> None:
        started["at"] = time.monotonic()
        await report({**base, "type": "status-update", "status": "in-progress"})
        await task.queue_frames([LLMRunFrame()])

    @transport.event_handler("on_client_disconnected")
    async def disconnected(_transport, _client) -> None:
        await task.cancel()

    async def limit() -> None:
        await asyncio.sleep(max_seconds)
        reason["value"] = "exceeded-max-duration"
        await task.cancel()

    timer = asyncio.create_task(limit())
    try:
        await PipelineRunner(handle_sigint=False).run(task)
    except Exception as error:  # the call is over either way; Laravel still needs its report
        logger.exception(f"Call {call_id} failed: {error}")
        reason["value"] = "pipeline-error"
    finally:
        timer.cancel()
        seconds = 0 if started["at"] is None else int(time.monotonic() - started["at"] + 0.999)
        sessions.pop(session_id, None)
        await report({
            **base,
            "type": "end-of-call-report",
            "durationSeconds": min(seconds, max_seconds),
            "endedReason": reason["value"],
            "transcript": recorder.transcript(),
            "usage": {"inputTokens": recorder.input_tokens, "outputTokens": recorder.output_tokens},
        })
