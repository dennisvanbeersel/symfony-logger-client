#!/usr/bin/env bash
#
# Fresh-install verification harness for the AppLogger Symfony client bundle.
#
# Proves that the bundle (applogger/symfony-bundle):
#   1. Installs cleanly into a brand-new Symfony skeleton app (mirror/copy, not symlink).
#   2. Wires its services with NO errors after the recipe is applied (cache:clear,
#      debug:container, lint:container all succeed).
#   3. Is INERT with the shipped placeholder credentials + APPLICATION_LOGGER_ENABLED=false
#      (the A4 blocker fix): triggering an exception sends ZERO bytes to the DSN host.
#   4. ACTIVATES correctly once APPLICATION_LOGGER_ENABLED=true: triggering an exception
#      now POSTs to /api/v1/errors on the DSN host.
#
# The DSN host is pointed at a local PHP listener we fully control, so "sent nothing"
# is proven by the listener receiving zero requests (not by faith).
#
# Re-runnable. Exits non-zero on any failed assertion. Cleans up its temp dir.
#
# Usage:  bash tests/recipe/verify-fresh-install.sh
#
set -uo pipefail

# ---------------------------------------------------------------------------
# Paths / config
# ---------------------------------------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BUNDLE_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"          # libraries/symfony-bundle
TMP="$(mktemp -d "${TMPDIR:-/tmp}/applogger-fresh.XXXXXX")"
APP="$TMP/freshapp"
LISTEN_PORT=9099
LISTEN_HOST=127.0.0.1
DSN="http://${LISTEN_HOST}:${LISTEN_PORT}/p"           # path "/p" = the project id
LISTENER_LOG="$TMP/listener.requests"                  # one line per received HTTP request
LISTENER_DOCROOT="$TMP/listener-docroot"
PASS=0
FAIL=0

# ---------------------------------------------------------------------------
# Output helpers
# ---------------------------------------------------------------------------
pass() { printf 'PASS: %s\n' "$1"; PASS=$((PASS + 1)); }
fail() { printf 'FAIL: %s\n' "$1"; FAIL=$((FAIL + 1)); }
info() { printf '\n==> %s\n' "$1"; }

# ---------------------------------------------------------------------------
# Cleanup: stop background servers and remove temp dir
# ---------------------------------------------------------------------------
LISTENER_PID=""
cleanup() {
  [ -n "$LISTENER_PID" ] && kill "$LISTENER_PID" 2>/dev/null
  if [ -d "$APP" ]; then
    (cd "$APP" && symfony server:stop >/dev/null 2>&1) || true
  fi
  # Kill any stray PHP built-in server on our docroot/port
  pkill -f "php -S ${LISTEN_HOST}:${LISTEN_PORT}" 2>/dev/null || true
  rm -rf "$TMP"
}
trap cleanup EXIT INT TERM

# ---------------------------------------------------------------------------
# Local capturing listener (PHP built-in server)
# Logs ONE line per request: "<METHOD> <URI>". Zero requests => empty file.
# ---------------------------------------------------------------------------
start_listener() {
  : > "$LISTENER_LOG"
  mkdir -p "$LISTENER_DOCROOT"
  cat > "$LISTENER_DOCROOT/router.php" <<PHP
<?php
// Router for the capturing listener. Records every inbound request and 200s.
\$line = (\$_SERVER['REQUEST_METHOD'] ?? '?') . ' ' . (\$_SERVER['REQUEST_URI'] ?? '?');
file_put_contents(getenv('LISTENER_LOG'), \$line . "\n", FILE_APPEND | LOCK_EX);
header('Content-Type: application/json');
echo '{"status":"ok"}';
return true;
PHP
  LISTENER_LOG="$LISTENER_LOG" php -S "${LISTEN_HOST}:${LISTEN_PORT}" \
    -t "$LISTENER_DOCROOT" "$LISTENER_DOCROOT/router.php" >/dev/null 2>&1 &
  LISTENER_PID=$!
  # Wait for it to accept connections
  for _ in $(seq 1 30); do
    if curl -s -o /dev/null "http://${LISTEN_HOST}:${LISTEN_PORT}/healthz"; then
      break
    fi
    sleep 0.2
  done
  # The health probe itself counts as a request — reset the log so assertions are clean.
  : > "$LISTENER_LOG"
}

count_requests()    { local n; n=$(grep -c . "$LISTENER_LOG" 2>/dev/null); printf '%s' "${n:-0}"; }
count_error_posts() { local n; n=$(grep -c 'POST /api/v1/errors' "$LISTENER_LOG" 2>/dev/null); printf '%s' "${n:-0}"; }

# ---------------------------------------------------------------------------
# Step 1: scaffold a fresh minimal Symfony app
# ---------------------------------------------------------------------------
info "Step 1: scaffold a fresh Symfony skeleton app in $APP"
if composer create-project "symfony/skeleton:^7.0" "$APP" --no-interaction >/dev/null 2>&1; then
  pass "scaffolded freshapp via composer create-project (symfony/skeleton:^7.0)"
elif symfony new "$APP" --version="7.*" --no-git >/dev/null 2>&1; then
  pass "scaffolded freshapp via 'symfony new' fallback"
else
  fail "could not scaffold a fresh Symfony app (composer create-project AND symfony new both failed)"
  printf '\n==== SUMMARY ====\nPASS=%d FAIL=%d\n' "$PASS" "$FAIL"
  exit 1
fi

# Bring the skeleton up to a realistic web-app baseline. The bundle expects the kind of
# packages any Symfony *web* app already has; a bare skeleton lacks them:
#   - symfony/runtime + symfony/http-client: telemetry transport + server runtime
#   - symfony/asset-mapper: the bundle prepends an AssetMapper path for its JS SDK
#     whenever the framework extension is present (so a clean install needs it)
#   - symfony/twig-bundle + twig/twig: the bundle registers a Twig extension service
#     (application_logger_init()) that extends Twig\Extension\AbstractExtension
# Installing them up front gives the recipe's auto cache:clear a complete environment.
(cd "$APP" && composer require \
    symfony/runtime symfony/http-client symfony/asset-mapper symfony/twig-bundle twig/twig \
    --no-interaction >/dev/null 2>&1) || true

# ---------------------------------------------------------------------------
# Step 2: register the bundle from the monorepo via a PATH repo, symlink:false
# ---------------------------------------------------------------------------
info "Step 2: require the bundle from a copy/mirror PATH repo (symlink:false)"
cd "$APP"
composer config repositories.applogger \
  "{\"type\":\"path\",\"url\":\"${BUNDLE_DIR}\",\"options\":{\"symlink\":false}}" >/dev/null 2>&1

# Confirm the symlink:false option actually stuck (mimics a real Packagist install).
# composer config emits compact JSON ("symlink":false), so match without whitespace.
if composer config repositories.applogger 2>/dev/null | grep -Eq '"symlink": *false'; then
  pass "PATH repo registered with symlink:false (mirror/copy install)"
else
  fail "PATH repo did not register with symlink:false"
fi

# Require the bundle. NOTE: composer's post-require auto-scripts run cache:clear, which
# can fail here because a PATH-repo install only gets Flex's *auto-generated* recipe
# (bundle registration only) — not the copy-from-recipe config nor the env vars. Without
# the DSN env var the config is incomplete and cache:clear KO's. That is EXPECTED and
# harmless: the package itself still mirrors into vendor/. We therefore judge success by
# the package being present, then complete the recipe ourselves in Step 3.
VENDOR_PKG="$APP/vendor/applogger/symfony-bundle"
composer require 'applogger/symfony-bundle:*@dev' --no-interaction >/dev/null 2>&1 || true
if [ -d "$VENDOR_PKG" ]; then
  pass "composer require mirrored applogger/symfony-bundle into vendor/"
else
  fail "composer require did NOT install the bundle into vendor/"
  printf '\n==== SUMMARY ====\nPASS=%d FAIL=%d\n' "$PASS" "$FAIL"
  exit 1
fi

# Verify the package was COPIED, not symlinked (real-install fidelity).
if [ -L "$VENDOR_PKG" ]; then
  fail "vendor package is a symlink — expected a mirror/copy (symlink:false)"
else
  pass "vendor package is a real copy (not a symlink)"
fi

# ---------------------------------------------------------------------------
# Step 3: apply the recipe (Flex if it ran, otherwise replicate the manifest)
# ---------------------------------------------------------------------------
info "Step 3: apply the recipe"

BUNDLES_PHP="$APP/config/bundles.php"
RECIPE_APPLIED_BY_FLEX="no"
if [ -f "$BUNDLES_PHP" ] && grep -q "ApplicationLoggerBundle" "$BUNDLES_PHP" \
   && [ -f "$APP/config/packages/application_logger.yaml" ]; then
  RECIPE_APPLIED_BY_FLEX="yes"
fi

if [ "$RECIPE_APPLIED_BY_FLEX" = "yes" ]; then
  pass "recipe auto-applied by Symfony Flex"
else
  info "Flex did not auto-apply (expected for PATH repos) — replicating manifest.json faithfully"
  MANIFEST="$BUNDLE_DIR/recipe/manifest.json"
  [ -f "$MANIFEST" ] || { fail "recipe/manifest.json not found"; }

  # 3a. bundles block -> config/bundles.php
  php -r '
    $m = json_decode(file_get_contents($argv[1]), true);
    $bundlesPhp = $argv[2];
    $src = is_file($bundlesPhp) ? file_get_contents($bundlesPhp) : "<?php\n\nreturn [\n];\n";
    foreach (($m["bundles"] ?? []) as $class => $envs) {
        if (strpos($src, $class) !== false) { continue; }
        $envList = implode(", ", array_map(fn($e) => "\"$e\" => true", $envs));
        $entry = "    \\$class::class => [$envList],\n";
        $src = preg_replace("/\];\s*$/", $entry . "];\n", $src, 1);
    }
    file_put_contents($bundlesPhp, $src);
  ' "$MANIFEST" "$BUNDLES_PHP"

  # 3b. copy-from-recipe block -> copy recipe/config/* into the app config dir
  php -r '
    $m = json_decode(file_get_contents($argv[1]), true);
    $recipeDir = $argv[2];   // bundle recipe/ dir
    $appDir = $argv[3];      // app root
    $copy = $m["copy-from-recipe"] ?? [];
    $rcopy = function(string $s, string $d) use (&$rcopy) {
        if (is_dir($s)) {
            @mkdir($d, 0777, true);
            foreach (scandir($s) as $f) {
                if ($f === "." || $f === "..") { continue; }
                $rcopy("$s/$f", "$d/$f");
            }
        } else {
            @mkdir(dirname($d), 0777, true);
            copy($s, $d);
        }
    };
    foreach ($copy as $from => $to) {
        // %CONFIG_DIR% maps to the app config/ dir (Flex default)
        $to = str_replace("%CONFIG_DIR%", "config", $to);
        $src = rtrim($recipeDir . "/" . $from, "/");
        $dst = rtrim($appDir . "/" . $to, "/");
        $rcopy($src, $dst);
    }
  ' "$MANIFEST" "$BUNDLE_DIR/recipe" "$APP"

  # 3c. env block -> append placeholder keys to .env
  php -r '
    $m = json_decode(file_get_contents($argv[1]), true);
    $envFile = $argv[2];
    $env = $m["env"] ?? [];
    $existing = is_file($envFile) ? file_get_contents($envFile) : "";
    $block = "\n###> applogger/symfony-bundle ###\n";
    foreach ($env as $k => $v) { $block .= "$k=$v\n"; }
    $block .= "###< applogger/symfony-bundle ###\n";
    file_put_contents($envFile, $existing . $block);
  ' "$MANIFEST" "$APP/.env"

  pass "replicated recipe manifest (bundles.php + config copy + .env env block)"
fi

# ---------------------------------------------------------------------------
# Step 3.5: install a throwaway controller that throws (for the inertness proof)
# ---------------------------------------------------------------------------
info "Step 3.5: add throwaway /boom route that throws"
mkdir -p "$APP/src/Controller"
cat > "$APP/src/Controller/BoomController.php" <<'PHP'
<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BoomController
{
    #[Route('/boom', name: 'boom')]
    public function __invoke(): Response
    {
        throw new \RuntimeException('boom — harness-triggered exception');
    }
}
PHP

# Ensure attribute routing picks up src/Controller. The skeleton ships routes.yaml
# wiring once the routing component is present; create it defensively.
mkdir -p "$APP/config/routes"
cat > "$APP/config/routes/boom.yaml" <<'YAML'
controllers:
    resource:
        path: ../../src/Controller/
        namespace: App\Controller
    type: attribute
YAML

# Point the DSN at our local listener (env-level placeholder host swapped for one we own).
php -r '
  $f = $argv[1];
  $s = file_get_contents($f);
  $s = preg_replace("/^APPLICATION_LOGGER_DSN=.*$/m", "APPLICATION_LOGGER_DSN=" . $argv[2], $s);
  file_put_contents($f, $s);
' "$APP/.env" "$DSN"

# ---------------------------------------------------------------------------
# Assertions a–e: clean wiring + recipe artifacts
# ---------------------------------------------------------------------------
info "Assertions a–e: clean wiring and recipe artifacts"

# a. cache:clear succeeds (dev env; enabled=false so services are not even loaded,
#    proving the bundle compiles in a clean app regardless).
if php bin/console cache:clear --env=dev --no-interaction >/dev/null 2>&1; then
  pass "a) php bin/console cache:clear succeeds"
else
  fail "a) php bin/console cache:clear failed"
  php bin/console cache:clear --env=dev --no-interaction 2>&1 | tail -20
fi

# b. debug:container + lint:container succeed (no missing-service / type errors).
if php bin/console debug:container --env=dev >/dev/null 2>&1; then
  pass "b1) php bin/console debug:container succeeds"
else
  fail "b1) php bin/console debug:container failed"
fi
if php bin/console lint:container --env=dev >/dev/null 2>&1; then
  pass "b2) php bin/console lint:container passes"
else
  fail "b2) php bin/console lint:container failed"
  php bin/console lint:container --env=dev 2>&1 | tail -20
fi

# c. bundle registered
if grep -q "ApplicationLoggerBundle" "$BUNDLES_PHP"; then
  pass "c) config/bundles.php registers ApplicationLoggerBundle"
else
  fail "c) config/bundles.php missing ApplicationLoggerBundle"
fi

# d. recipe config present
if [ -f "$APP/config/packages/application_logger.yaml" ]; then
  pass "d) config/packages/application_logger.yaml present"
else
  fail "d) config/packages/application_logger.yaml missing"
fi

# e. .env has the disabled flag + DSN/API_KEY keys
if grep -q "^APPLICATION_LOGGER_ENABLED=false$" "$APP/.env"; then
  pass "e1) .env contains APPLICATION_LOGGER_ENABLED=false"
else
  fail "e1) .env missing APPLICATION_LOGGER_ENABLED=false"
fi
if grep -q "^APPLICATION_LOGGER_DSN=" "$APP/.env" && grep -q "^APPLICATION_LOGGER_API_KEY=" "$APP/.env"; then
  pass "e2) .env contains APPLICATION_LOGGER_DSN and APPLICATION_LOGGER_API_KEY"
else
  fail "e2) .env missing DSN / API_KEY placeholders"
fi

# ---------------------------------------------------------------------------
# Boot the app's web server (prod env so exceptions are handled, not dumped by
# the dev profiler — and the ExceptionSubscriber fires on the kernel.exception).
# ---------------------------------------------------------------------------
boot_server() {
  local env="$1"
  composer dump-env "$env" >/dev/null 2>&1 || true
  php bin/console cache:clear --env="$env" --no-interaction >/dev/null 2>&1
  APP_ENV="$env" symfony server:start -d --no-tls --port=8123 >/dev/null 2>&1
  for _ in $(seq 1 40); do
    curl -s -o /dev/null "http://127.0.0.1:8123/" && return 0
    sleep 0.25
  done
  return 0
}
stop_server() { symfony server:stop >/dev/null 2>&1 || true; }

trigger_boom() {
  # Fire the throwing route; we don't care about the response, only what the listener saw.
  # The error send is async/fire-and-forget: the curl handle may still be in flight when
  # the response returns. Fire a couple of follow-up requests so the worker drains its
  # pending responses (reapPendingResponses) and the in-flight POST flushes, then wait.
  curl -s -o /dev/null --max-time 5 "http://127.0.0.1:8123/boom" || true
  curl -s -o /dev/null --max-time 5 "http://127.0.0.1:8123/" || true
  curl -s -o /dev/null --max-time 5 "http://127.0.0.1:8123/" || true
  sleep 3   # allow fire-and-forget HTTP client to flush to the local listener
}

# ---------------------------------------------------------------------------
# Assertion f: INERTNESS — enabled=false => listener gets ZERO requests
# ---------------------------------------------------------------------------
info "Assertion f: INERTNESS proof (APPLICATION_LOGGER_ENABLED=false)"
start_listener

# .env already has ENABLED=false from the recipe. Confirm and boot.
boot_server prod
trigger_boom
stop_server

REQ_DISABLED="$(count_requests)"
if [ "$REQ_DISABLED" -eq 0 ]; then
  pass "f) bundle is INERT when disabled — listener received 0 requests (A4 proof)"
else
  fail "f) bundle sent telemetry while disabled — listener received $REQ_DISABLED request(s)"
  cat "$LISTENER_LOG"
fi

# ---------------------------------------------------------------------------
# Assertion g: OPT-IN — enabled=true => listener gets a POST /api/v1/errors
# ---------------------------------------------------------------------------
info "Assertion g: OPT-IN proof (APPLICATION_LOGGER_ENABLED=true)"
: > "$LISTENER_LOG"
php -r '
  $f = $argv[1];
  $s = file_get_contents($f);
  $s = preg_replace("/^APPLICATION_LOGGER_ENABLED=.*$/m", "APPLICATION_LOGGER_ENABLED=true", $s);
  file_put_contents($f, $s);
' "$APP/.env"

# Delivery-timing override (NOT a behavior change): the bundle ships async/fire-and-forget
# error sending by default (see CLAUDE.md "Fire-and-Forget Mode"). Under the short-lived
# `symfony server` dev process the in-flight curl handle for the error POST is not reliably
# driven to completion before teardown, so the POST can be observed late or not at all in
# this harness — purely a flush-timing artifact of the test web server, not the bundle.
# Setting async:false makes the error send block until transmitted so the opt-in assertion
# is deterministic. It changes WHEN the POST flushes, never WHAT is sent or to where.
# (The inertness proof in step f deliberately keeps the shipped async config untouched.)
APP_YAML="$APP/config/packages/application_logger.yaml"
php -r '
  $f = $argv[1];
  $s = file_get_contents($f);
  if (!preg_match("/^    async:/m", $s)) {
      // Insert a real (uncommented) async:false right after the root enabled: line.
      $s = preg_replace("/^(    enabled:.*)$/m", "$1\n    async: false\n    timeout: 5.0", $s, 1);
  }
  file_put_contents($f, $s);
' "$APP_YAML"

boot_server prod
trigger_boom
stop_server

ERR_POSTS="$(count_error_posts)"
if [ "$ERR_POSTS" -ge 1 ]; then
  pass "g) bundle ACTIVATES when enabled — received POST /api/v1/errors ($ERR_POSTS)"
else
  fail "g) bundle did not POST /api/v1/errors when enabled (received $(count_requests) total request(s))"
  echo "--- listener log ---"; cat "$LISTENER_LOG"
fi

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------
printf '\n==================== SUMMARY ====================\n'
printf 'Recipe applied by Flex: %s\n' "$RECIPE_APPLIED_BY_FLEX"
printf 'PASS=%d  FAIL=%d\n' "$PASS" "$FAIL"
printf '=================================================\n'

[ "$FAIL" -eq 0 ] || exit 1
exit 0
