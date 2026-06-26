# Publishing the Flex recipe to symfony/recipes-contrib

A Composer package **cannot ship a recipe that applies itself**. Symfony Flex only
reads recipe endpoints from the *consumer's* root `composer.json`
(`extra.symfony.endpoint`) plus the two default trusted endpoints:
`symfony/recipes` and `symfony/recipes-contrib`
(`vendor/symfony/flex/src/Downloader.php:61`).

So for `composer require applogger/symfony-bundle` to auto-install the
config scaffolding + `.env` vars + post-install help, the recipe must live in
**symfony/recipes-contrib** (the only default endpoint open to third-party packages).

> The `recipe/` folder in this bundle is **not** a Flex source — it is the canonical
> copy we maintain and the input to `build-contrib.sh`. Flex never reads it.

Since the [config fix](../src/DependencyInjection/Configuration.php) made `dsn`/`api_key`
optional, a recipe-less install no longer breaks `cache:clear` — the bundle installs
clean and stays inert. The recipe is now **additive** (nice DX), not a correctness
requirement.

## Eligibility (as of v2.0)

| Requirement | Status |
|-------------|--------|
| Listed on Packagist | ✅ [applogger/symfony-bundle](https://packagist.org/packages/applogger/symfony-bundle) |
| Has a stable (non-dev) release | ✅ v2.0, MIT |
| Recipe is useful to the community | ⚠️ low adoption (1 install, 0 stars) — contrib reviewers occasionally push back on packages with little traction |

If the contrib PR stalls on the adoption point, fall back to a **private recipe
endpoint** (host a recipes repo and have consumers add its `index.json` to their
`extra.symfony.endpoint`). That is the only other way to get auto-apply, and it
requires consumer opt-in. Layer B keeps installs working in the meantime.

## What gets submitted

`build-contrib.sh` assembles the exact directory contrib expects — **two** version
dirs:

```
applogger/symfony-bundle/0.3/manifest.json   # base recipe, NO publishable_key
applogger/symfony-bundle/0.3/config/packages/application_logger.yaml
applogger/symfony-bundle/2.1/manifest.json   # full recipe, WITH publishable_key
applogger/symfony-bundle/2.1/config/packages/application_logger.yaml
```

- A version directory is the **lowest released version** that payload applies to;
  Flex applies it to that version and every version above, until a higher version
  dir takes over.
- **Why two dirs:** the canonical config carries `publishable_key:`, a node that
  only exists in the **2.1.0** Configuration. Applying it to 0.3–2.0.x installs
  would make `cache:clear` fail on an unrecognized option. So the `0.3` payload has
  `publishable_key` (and its env var) stripped and applies to `0.3 … 2.0.x`; the
  `2.1` payload carries `publishable_key` and applies to `2.1.0` and above. The
  build script derives the `0.3` payload from the canonical files automatically.
- `manifest.json` is already in Flex format: `bundles`, `copy-from-recipe`, `env`,
  `post-install-output`.

## Steps

```bash
# 1. Regenerate the payload from the canonical recipe/ files (emits BOTH the
#    0.3 base and the 2.1 publishable_key payloads).
bash recipe/build-contrib.sh 2.1        # writes recipe/contrib/...

# 2. Fork + clone symfony/recipes-contrib
gh repo fork symfony/recipes-contrib --clone
cd recipes-contrib

# 3. Drop the payload at the repo root (preserving the vendor/package/version path)
cp -R /path/to/libraries/symfony-bundle/recipe/contrib/applogger .

# 4. Validate locally with the contrib tooling
composer install
php src/Github.php   # or: vendor/bin/... per the contrib README's "Testing recipes"
# Quick syntax check at minimum (both version dirs):
php -r 'json_decode(file_get_contents("applogger/symfony-bundle/2.1/manifest.json"), false, 512, JSON_THROW_ON_ERROR); echo "2.1 manifest OK\n";'
php -r 'json_decode(file_get_contents("applogger/symfony-bundle/0.3/manifest.json"), false, 512, JSON_THROW_ON_ERROR); echo "0.3 manifest OK\n";'

# 5. Open the PR
git checkout -b add-applogger-symfony-bundle
git add applogger
git commit -m "Add applogger/symfony-bundle recipe"
git push -u origin add-applogger-symfony-bundle
gh pr create --repo symfony/recipes-contrib --fill
```

The contrib PR template will ask you to confirm the package is on Packagist and the
recipe is for the latest major version — both true here.

## Test the recipe end-to-end before submitting

Point Flex at a local checkout of your contrib fork via `SYMFONY_ENDPOINT`, then
install into a throwaway Symfony skeleton:

```bash
SYMFONY_ENDPOINT=https://raw.githubusercontent.com/<you>/recipes-contrib/<branch>/index.json \
  composer require applogger/symfony-bundle
```

(Or run the existing harness `tests/recipe/verify-fresh-install.sh`, which already
replicates the manifest and asserts clean wiring + inert-until-enabled behavior.)

## After it merges

`composer require applogger/symfony-bundle` will auto-apply the recipe:
copies `config/packages/application_logger.yaml`, appends the three `APPLICATION_LOGGER_*`
env vars, and prints the post-install instructions. No `extra.symfony.endpoint` needed
in the consumer project.
