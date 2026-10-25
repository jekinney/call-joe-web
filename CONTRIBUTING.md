# Development guidelines

This is proprietary software of 120 Customs LLC (see [LICENSE](LICENSE)). Outside pull requests aren't accepted. These are the rules the code is built by:

1. **Branch from `develop`**; `main` only moves forward from a green `develop`.
2. **Tests come with every change.** CI fails below 95% line coverage. Services and domain code are fully covered.
3. **Never call real outside services in tests.** LLMs, voice, images and payments each sit behind an interface with a fake; tests use the fake. `Http::preventStrayRequests()` is on for the whole suite.
4. **Run the checks before pushing:**
   ```bash
   ./vendor/bin/sail bin pint
   ./vendor/bin/sail bin phpstan analyse
   ./vendor/bin/sail pest --coverage --min=95
   ```
5. **No secrets in the repo.** `.env` is ignored; `.env.example` lists every setting with no real values.
6. **Contacts' assets must be licensed.** Any voice clip or picture used for a contact needs a license that allows it, recorded in `docs/credits.md`. No real people, no celebrities.
