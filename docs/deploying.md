# Deploying to DigitalOcean

One server (a Droplet) runs everything in Docker: the site, the queue worker, the scheduler, Reverb (real-time), the voice service, Postgres, Valkey and Caddy (HTTPS). Pictures go to DigitalOcean Spaces. About $30/month to start: a 2 vCPU / 4 GB Droplet ($24), Spaces ($5) and Droplet backups (20% of the Droplet).

```
Browser ── Caddy ─┬─ site, queue, scheduler  (the `call-joe-web` image)
 (80/443)         ├─ /app/* WebSockets ───── Reverb
                  └─ /voice/* ────────────── voice service (Pipecat + Gemini Live)
 Browser ── UDP 32768-60999 ─────────────── voice service (the call audio itself)
 Postgres, Valkey: only on the server's private Docker network
```

## What you need first

- A DigitalOcean account with a payment method (you do this).
- `doctl` on your computer, signed in: `winget install DigitalOcean.Doctl`, then `doctl auth init` (you paste your API token; Claude never needs to see it).
- An SSH key in your DigitalOcean account (Settings > Security), so the server has no password login.
- A Space (storage) and its access key (Spaces > Create, then Access Keys), for pictures.
- API keys for the AIs (Anthropic, Gemini, xAI/Grok), an SMTP login for email, and the Braintree sandbox keys.
- A domain, when you have one. Until then everything works on the Droplet's IP over plain HTTP, except calls: browsers only allow the microphone on HTTPS.

## 1. Create the Droplet

```bash
doctl compute droplet create call-joe --region nyc1 --size s-2vcpu-4gb --image ubuntu-24-04-x64 \
  --ssh-keys <your key id> --enable-backups --wait
```

(`doctl compute ssh-key list` shows the key id.) Note the IP address it prints.

## 2. Set up the server (once)

```bash
ssh root@<ip>
apt-get update -y && apt-get install -y git
git clone --branch main https://github.com/jekinney/call-joe-web.git /opt/call-joe
bash /opt/call-joe/deploy/server-setup.sh
```

(Until this guide is merged to `main`, clone `develop` instead and deploy with `deploy.sh develop`.) The setup script installs Docker, opens only ports 22, 80, 443 and UDP 32768-60999 (calls), adds swap and puts the code in `/opt/call-joe`.

## 3. Fill in `.env`

```bash
cd /opt/call-joe
cp .env.production.example .env
nano .env        # every line marked CHANGE, plus the keys
```

- Without a domain: `APP_URL=http://<ip>`, `VITE_REVERB_HOST=<ip>`, `VOICE_PUBLIC_URL=http://<ip>/voice`, `SITE_ADDRESS=:80`.
- With a domain (point an A record at the IP first): `APP_URL=https://<domain>`, `SITE_ADDRESS=<domain>`, `VITE_REVERB_HOST=<domain>`, `VITE_REVERB_PORT=443`, `VITE_REVERB_SCHEME=https`, `VOICE_PUBLIC_URL=https://<domain>/voice`, `SESSION_SECURE_COOKIE=true`. Caddy gets the certificate by itself. Rebuild after changing the `VITE_` lines.
- Make random values for `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `VOICE_SECRET` and `DB_PASSWORD` (`openssl rand -hex 24`).

## 4. Start it

```bash
bash /opt/call-joe/deploy/deploy.sh main
```

The first run builds the images (about 5-10 minutes), makes the app key, starts everything and runs the database migrations. Then make the owner account (from `OWNER_*` in `.env`) and check:

```bash
cd /opt/call-joe
docker compose -f compose.prod.yaml exec app php artisan db:seed --class=OwnerSeeder --force
docker compose -f compose.prod.yaml ps
curl -I http://<ip>/up
```

## Updating

Merge to `main`, then on the server: `bash /opt/call-joe/deploy/deploy.sh main`. It pulls, rebuilds, restarts and runs migrations. The version shows at the bottom of the admin menu.

## Looking after it

- Logs: `docker compose -f compose.prod.yaml logs -f app queue voice`.
- Backups: Droplet backups (weekly snapshots) are on. Before risky changes, also dump the database: `docker compose -f compose.prod.yaml exec pgsql pg_dump -U calljoe call_joe_web > backup.sql`.
- Never change `APP_KEY` after launch (it protects sessions and encrypted data).
- `.env` holds every secret: it stays on the server and is never committed.

## Known limits

- One server, one voice service: fine for the first users. Past that, move Postgres to DigitalOcean Managed Postgres and put the voice service on its own Droplet.
- Calls use a direct UDP path. Users behind strict networks need a TURN server (set `ice_servers` in `voice/app.py`).
- The voice price per minute in `/admin/models` was an estimate; check the first real invoice.
