# The voice service (Pipecat + Gemini Live). It runs with host networking (compose.prod.yaml), because WebRTC needs the
# browser to reach it over UDP directly.
FROM python:3.12-slim
WORKDIR /srv/voice
RUN apt-get update && apt-get install -y --no-install-recommends libgl1 libglib2.0-0 ffmpeg && rm -rf /var/lib/apt/lists/*
COPY voice/requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt
COPY voice/app.py ./
EXPOSE 8787
CMD ["python", "-m", "uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8787"]
