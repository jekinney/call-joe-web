# Call Joe (web)

An AI companion web app by 120 Customs LLC: talk and text with AI friends ("contacts") who remember you, keep their own schedules, and reach out on their own. It's the web version of the Call Joe desktop app.

> **Proprietary software.** This code is public so it can be viewed as a sample of the work. It is not open source: no license is granted to use, copy, modify or host it. See [LICENSE](LICENSE).

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
| Reverb | Real-time WebSockets (texts, rings, notices) | ws://localhost:8091 |
| Queue | Background jobs and broadcasts | (no port) |
| Scheduler | Contacts reaching out on their own (`outreach:run`, every minute) | (no port) |

Contacts keep living while you're away: the scheduler moves each one through their relationship's worry stages and plans texts and calls (`App\Services\Outreach`). Calls ring your open page or become missed calls; texts are written only when you're online to read them, with their original times. Try it with `./vendor/bin/sail artisan outreach:run`.

Safety runs on every text, reply, reach-out, call and (later) picture prompt (`App\Services\Moderator`): fast word patterns, then a small AI classifier only when something looks risky. Anything sexual involving minors is blocked and counted as a strike; talk of self-harm or hurting others puts the contact on a crisis path that points to 988 or 911. Admins review blocked and flagged items and suspend accounts at `/admin/moderation`. The Terms, Privacy Policy and AI disclosure live at `/terms`, `/privacy` and `/ai` (drafts, to be reviewed by counsel); bump `terms_version` in `config/legal.php` to ask everyone to accept a change.

To see real-time delivery, sign in and run `./vendor/bin/sail artisan app:notify you@example.com "Hello"`: the notice pops up on your open page. A page checks in every 45 seconds; the server counts you as online while it does (`App\Services\Presence`).

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

## Development guidelines

See [CONTRIBUTING.md](CONTRIBUTING.md) for how the code is kept tested and tidy. Security issues: see [SECURITY.md](SECURITY.md).

## License

Copyright © 2026 120 Customs LLC. All rights reserved. See [LICENSE](LICENSE).
