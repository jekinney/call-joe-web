# Call Joe (web)

An open-source AI companion web app: talk and text with AI friends ("contacts") who remember you, keep their own schedules, and reach out on their own. It's the web version of the Call Joe desktop app.

Built with Laravel 12, Livewire 4 (Volt and Flux), Postgres, Valkey, Reverb, and Vapi for voice. Installable as a PWA.

## Local setup

You need Docker (on Windows: Docker Desktop with WSL integration on, and this project inside WSL, not on a Windows drive, for speed).

```bash
git clone git@github.com:jekinney/call-joe-web.git
cd call-joe-web
cp .env.example .env
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app composer:latest composer install --ignore-platform-reqs
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate
./vendor/bin/sail npm install && ./vendor/bin/sail npm run build
```

Then open http://localhost:8090.

| Service | What it's for | Local address |
|---|---|---|
| App | The site | http://localhost:8090 |
| Postgres | Database (DigitalOcean Managed Postgres in production) | localhost:5433 |
| Valkey | Cache and queues (DigitalOcean Managed Caching in production) | localhost:6379 |
| RustFS | S3-compatible file storage (DigitalOcean Spaces in production) | http://localhost:9001 (console) |
| Mailpit | Catches every email the app sends | http://localhost:8025 |

Ports can be changed in `.env` (`APP_PORT`, `FORWARD_DB_PORT`, `VITE_PORT`, and so on).

## Tests

```bash
./vendor/bin/sail pest                      # the whole suite
./vendor/bin/sail pest --coverage --min=95  # what CI enforces
./vendor/bin/sail bin phpstan analyse       # static analysis (Larastan, level 8)
./vendor/bin/sail bin pint                  # code style
```

CI (GitHub Actions) runs Pint, Larastan and the full Pest suite against Postgres on every push and pull request, and fails below 95% line coverage. Outside services (LLMs, voice, images, payments) are always faked in tests; tests never touch the network.

## Roadmap

The MVP is built in small phases, each one tested before the next: environment, accounts, realtime (Reverb), plans and gating, contacts and the catalog, relationships and prompts, the LLM gateway, texting, memory, the PWA, voice calls, contacts reaching out (with relationship-based worry when you're away), safety, images and the shared image cache, billing, the admin dashboard, data rights, hardening, and launch on DigitalOcean.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Security issues: see [SECURITY.md](SECURITY.md).
