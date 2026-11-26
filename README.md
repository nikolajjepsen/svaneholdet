# Svaneholdet

Shift planning for volunteer teams at Smukfest. A team admin invites volunteers, rolls out shifts over the festival period, and approves signups. Volunteers sign up from their phone. After the festival, the team has each person's recorded hours.

Svaneholdet, a team of more than 70 volunteers, is the first organisation to use it. The data model supports several teams from the start, but the pilot only has one.

## Status

The app is a scaffold so far: the Laravel starter kit with tooling and nothing from the domain yet. Features get built one at a time, in the order of the build sequence in [`PLAN.md`](PLAN.md).

## Stack

One Laravel 13 app, rendered on the server, with no API between backend and frontend. The UI is Livewire 4 with Flux components. The database is PostgreSQL. Tests use Pest, including browser tests on Playwright.

## Getting started

You need PHP 8.4, Composer, Node and Docker.

Profile picture uploads use PHP's GD extension with JPEG, PNG and WebP support. Set `upload_max_filesize=6M` and `post_max_size=8M` in the PHP configuration used by your local server so the app can accept pictures up to 5 MB.

```sh
docker compose up -d          # Postgres for dev and tests
composer setup                # install, .env, key, migrate, build assets
npx playwright install chromium
composer dev                  # server, queue, logs and Vite
```

`composer test` runs Pint, Larastan and every test suite. It must pass before a commit.

## Docs

- [`PLAN.md`](PLAN.md): the product plan for the first version, with the build sequence and open decisions
- [`docs/GLOSSARY.md`](docs/GLOSSARY.md): the domain language
- [`docs/adr/`](docs/adr/): architecture decisions
- [`AGENTS.md`](AGENTS.md): how coding agents work in this repo, with details in [`docs/agents/`](docs/agents/)
