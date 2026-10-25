# Call Joe (web)

Proprietary to 120 Customs LLC, all rights reserved. The repository is public only as a work sample.

@AGENTS.md

## Project rules

- Deploys to DigitalOcean (App Platform, Managed Postgres, Spaces, Managed Caching), not Laravel Cloud.
- Never commit `.env`, `auth.json` or `public/storage`. Never write API keys, passwords or license keys for the owner: they add them to `.env` themselves.
- Every change keeps the checks green: `vendor/bin/sail pint`, `vendor/bin/sail php ./vendor/bin/phpstan analyse` (level 8), and Pest with coverage (CI requires 95%; the suite is at 100%).
- Run the suite in parallel with at most 8 processes (`vendor/bin/sail pest --parallel --processes=8`); more runs Postgres out of locks.
- Tests never reach real services: `tests/TestCase.php` blocks stray HTTP and binds fake LLM and voice clients.
