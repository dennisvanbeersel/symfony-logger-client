# Fresh-install recipe verification

`verify-fresh-install.sh` is a real, re-runnable end-to-end harness that proves the
AppLogger Symfony client bundle (`dennisvanbeersel/symfony-logger-client`):

1. **Installs cleanly** into a brand-new Symfony skeleton app via a `path` repository
   configured with **`symlink: false`** (a mirror/copy, mimicking a real Packagist
   install — not a live symlink into the monorepo).
2. **Wires its services with zero errors** after the recipe is applied
   (`cache:clear`, `debug:container`, and `lint:container` all succeed in a clean app).
3. **Is inert with the shipped placeholder credentials** when
   `APPLICATION_LOGGER_ENABLED=false` — the **A4** guarantee. Proven by pointing the
   DSN host at a local listener we control and asserting it receives **zero** requests
   when an exception is triggered.
4. **Activates correctly once opted in** — with `APPLICATION_LOGGER_ENABLED=true` the
   same exception produces a `POST /api/v1/errors` to the listener.

## Run it

```bash
bash libraries/symfony-bundle/tests/recipe/verify-fresh-install.sh
```

The script prints `PASS:` / `FAIL:` lines and a summary, and **exits non-zero** if any
assertion fails. It does all its work in a `mktemp -d` directory and cleans up on exit.

### Requirements

- `php` 8.4+ (CLI)
- `composer` 2.x
- `symfony` CLI (for the local web server)
- `curl`
- Network access (to scaffold the skeleton and resolve Symfony dependencies)

## What it does, step by step

| Step | Action |
|------|--------|
| 1 | `composer create-project symfony/skeleton:^7.0` (falls back to `symfony new`). |
| 1b | Brings the skeleton to a realistic web-app baseline: `symfony/runtime`, `symfony/http-client`, `symfony/asset-mapper`, `symfony/twig-bundle`, `twig/twig` — packages the bundle expects (it prepends an AssetMapper path and registers a Twig extension). |
| 2 | Registers a `path` repo for the monorepo bundle with `options.symlink=false`, then `composer require dennisvanbeersel/symfony-logger-client:*@dev`. Asserts the package is a real **copy** in `vendor/`, not a symlink. |
| 3 | Applies the recipe. Prefers Flex auto-application; **replicates `recipe/manifest.json` faithfully** (parsed with PHP, not hardcoded) when Flex doesn't fully apply it — see note below. |
| 3.5 | Adds a throwaway `/boom` route that throws, and points `APPLICATION_LOGGER_DSN` at the local listener. |
| a–e | Asserts clean wiring and recipe artifacts (`cache:clear`, `debug:container`, `lint:container`, `bundles.php`, `config/packages/application_logger.yaml`, `.env` keys). |
| f | **Inertness (A4):** `ENABLED=false`, trigger `/boom`, assert listener got **0** requests. |
| g | **Opt-in:** `ENABLED=true`, trigger `/boom`, assert listener got a `POST /api/v1/errors`. |

## Notes / gotchas worth knowing

- **Flex does not fully apply the recipe for a `path` repo.** Flex generates an
  *auto* recipe that only registers the bundle in `config/bundles.php`; the
  `copy-from-recipe` config file and the `env` block come from the recipes server,
  which a local `path` package has no entry in. The harness detects this (the
  `config/packages/application_logger.yaml` won't exist) and replicates the manifest
  exactly: bundle registration, config copy, and the `.env` env block. The summary
  line `Recipe applied by Flex: yes|no` reports which path was taken.

- **The bundle's services are registered even when disabled.** `enabled` is an env
  placeholder (`%env(bool:APPLICATION_LOGGER_ENABLED)%`) that can't be resolved at
  container-compile time, so it reads as truthy then and the services compile in. The
  real gate is at **runtime** inside the sdk-core transport layer (`ApplicationLoggerHandler::write()` no-ops when the bundle is disabled),
  which is exactly what assertion **f** exercises over a real network listener.

- **Opt-in delivery timing.** The bundle ships async/fire-and-forget error sending. Under
  the short-lived `symfony server` dev process the in-flight curl handle is not reliably
  driven to completion before teardown, so assertion **g** sets `async: false` in the
  app's config. This changes only *when* the POST flushes (it blocks until sent), never
  *what* is sent or where. Assertion **f** keeps the shipped async config untouched.
