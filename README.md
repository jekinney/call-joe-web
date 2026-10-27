# Call Joe (web)

An AI companion web app by 120 Customs LLC: talk and text with AI friends ("contacts") who remember you, keep their own schedules, and reach out on their own. It's the web version of the Call Joe desktop app.

> **Proprietary software.** This code is public so it can be viewed as a sample of the work. It is not open source: no license is granted to use, copy, modify or host it. See [LICENSE](LICENSE).

Built with Laravel 12, Livewire 4 (Volt and Flux), Postgres, Valkey, Reverb, and a small Pipecat service (`voice/`) for voice calls with Gemini Live. Installable as a PWA.

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
| Voice | Voice calls: Pipecat + Gemini Live, run on your machine, not in Docker (see `voice/README.md`) | http://localhost:8787 |

**The brain:** two keys run most of it. **Grok** (xAI) writes texts and makes pictures (Grok Imagine, $0.02 each); **Claude** does the careful jobs (memory notes, safety checks, picture scenes). A **Gemini** key adds cheap models for lower-tier contacts, and runs calls. Each model has a provider and a fallback on another provider, and is managed in `/admin/models` (Claude goes through Anthropic's PHP SDK; an "Answer fast" switch turns a model's thinking down).

**Who can do what:** regular users have no role; their tier is their plan (Free, Paid). Admins have a role, a named set of permissions (: overview, users, plan changes, suspending, contacts, safety, pictures, AI models, prompt preview, roles). Roles are made in  and given on a user's page. Support and Content editor start as examples; the Owner role has everything and can't be edited. Owners only come from the command line:  (or  for another role,  to remove). Nobody can give a permission they lack or change their own role.

**A contact's brain** is the model that writes their texts. Whoever creates the contact chooses it, in `/admin/contacts` (a cheap Gemini for a lower-tier contact, Grok or Claude for a better one, with prices shown), and only an admin can change it: users never see or set it. A contact can't be published without one, and contacts without one use the default (Grok).

**Paid plans** are sold through Braintree: the card form is Braintree's own (card details never touch our server), a subscription puts the user on the plan until the end of the period they paid for (plus 2 days), and Braintree's webhooks (`/webhooks/braintree`) move that date on at each renewal. A failed card or a missed webhook ends on Free, never on free paid service. Add your Braintree sandbox keys and plan id to `.env` (see `.env.example`); until then the plan page says upgrading is coming soon.

**Voice calls** use Gemini Live through our own Pipecat service in `voice/` (one Gemini key, no cloned voices for now): a contact's voice is one of Gemini's built-in voices, picked in `/admin/contacts`. Start it with the steps in `voice/README.md` before making a call.

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

## Branches and releases

- `main` is what has been released. `develop` is where work comes together; branch off `develop` (`feature/...`, `fix/...`) and open a pull request back into it.
- Both branches are protected: changes arrive by pull request, and the `tests` check (Pint, Larastan, the Pest suite with coverage, and the front-end build) must pass first. Merges are squashed.
- The release number is the `VERSION` file, and a release is the git tag `v` + that number. `0.0.0.1` is the first alpha, tagged `v0.0.0.1`; anything under 1.0 is published as a pre-release.
- No tags have been made yet; the `release` workflow only runs once the first one is pushed.
- To release: open a pull request that sets `VERSION` into `develop`, then a pull request from `develop` into `main`, then tag the merge commit on `main` (`git tag v0.0.0.2 && git push origin v0.0.0.2`). The `release` workflow checks the tag matches `VERSION`, runs the tests again and publishes the GitHub release with generated notes.
- The running version shows at the bottom of the admin sidebar.

## Roadmap

The MVP is built in small phases, each one tested before the next: environment, accounts, realtime (Reverb), plans and gating, contacts and the catalog, relationships and prompts, the LLM gateway, texting, memory, the PWA, voice calls, contacts reaching out (with relationship-based worry when you're away), safety, images and the shared image cache, billing, the admin dashboard, data rights, hardening, and launch on DigitalOcean.

## Development guidelines

See [CONTRIBUTING.md](CONTRIBUTING.md) for how the code is kept tested and tidy. Security issues: see [SECURITY.md](SECURITY.md).

## License

Copyright © 2026 120 Customs LLC. All rights reserved. See [LICENSE](LICENSE).
