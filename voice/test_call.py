"""
Calls the voice service the way the browser does, without a browser: plays a speech clip as the microphone and
records what the contact says back. For checking the whole path (WebRTC -> Pipecat -> Gemini Live -> the report to
Laravel) by hand:

    .venv/bin/python test_call.py "<connect url>" hello.wav reply.wav 25
"""

import asyncio
import sys

import httpx
from aiortc import RTCPeerConnection, RTCSessionDescription
from aiortc.contrib.media import MediaPlayer, MediaRecorder


async def main(url: str, speech: str, reply: str, seconds: float) -> None:
    pc = RTCPeerConnection()
    recorder = MediaRecorder(reply)
    pc.addTrack(MediaPlayer(speech).audio)
    pc.addTransceiver("video", direction="recvonly")

    @pc.on("track")
    def on_track(track) -> None:
        print("got track", track.kind)
        if track.kind == "audio":
            recorder.addTrack(track)

    @pc.on("connectionstatechange")
    async def on_state() -> None:
        print("connection", pc.connectionState)

    await pc.setLocalDescription(await pc.createOffer())
    async with httpx.AsyncClient(timeout=30) as client:
        response = await client.post(url, json={"sdp": pc.localDescription.sdp, "type": pc.localDescription.type})
    response.raise_for_status()
    answer = response.json()
    await pc.setRemoteDescription(RTCSessionDescription(sdp=answer["sdp"], type=answer["type"]))
    await recorder.start()
    await asyncio.sleep(seconds)
    await recorder.stop()
    await pc.close()
    print("hung up")


asyncio.run(main(sys.argv[1], sys.argv[2], sys.argv[3], float(sys.argv[4])))
