# Contributing

Thanks for helping. A few rules keep the project healthy:

1. **Open an issue first** for anything bigger than a small fix, so we can agree on the approach.
2. **Branch from `develop`** and open your pull request against `develop`.
3. **Tests come with the change.** CI fails below 95% line coverage. New services and domain code should be fully covered.
4. **Never call real outside services in tests.** LLMs, voice, images and payments each sit behind an interface with a fake; use the fake. `Http::preventStrayRequests()` is on for the whole suite.
5. **Run the checks before pushing:**
   ```bash
   ./vendor/bin/sail bin pint
   ./vendor/bin/sail bin phpstan analyse
   ./vendor/bin/sail pest --coverage --min=95
   ```
6. **No secrets in the repo.** `.env` is ignored; `.env.example` lists every setting with no real values.
7. **Contacts' assets must be licensed.** Any voice clip or picture added to the seed contacts needs a license that allows it, recorded in `docs/credits.md`. No real people, no celebrities.

Be kind and constructive in reviews and discussions.
