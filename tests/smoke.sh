#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP="$(mktemp -d)"
PORT="${SMOKE_PORT:-8123}"
SERVER_PID=""

cleanup() {
    if [ -n "$SERVER_PID" ]; then
        kill "$SERVER_PID" 2>/dev/null || true
        wait "$SERVER_PID" 2>/dev/null || true
    fi
    rm -rf "$TMP"
}
trap cleanup EXIT

mkdir -p "$TMP"
tar -C "$ROOT" \
    --exclude=.git \
    --exclude=.build \
    --exclude=data/cms.sqlite \
    --exclude=data/cms.sqlite-wal \
    --exclude=data/cms.sqlite-shm \
    -cf - . | tar -C "$TMP" -xf -

# Create the schema and mark it as installed without driving the HTML wizard.
php -r '
chdir($argv[1]);
require "core/bootstrap.php";
$db = \Core\Database::getConnection();
$db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (\"install_completed\", \"1\")")->execute();
$db->prepare("UPDATE users SET password_hash = :h WHERE id = 1")->execute([":h" => password_hash("test1234", PASSWORD_BCRYPT)]);
$columns = array_column($db->query("PRAGMA table_info(categories)")->fetchAll(), "name");
if (!in_array("parent_id", $columns, true)) {
    fwrite(STDERR, "FAIL: categories.parent_id is missing\n");
    exit(1);
}
' "$TMP"

php -S "127.0.0.1:${PORT}" -t "$TMP" "$TMP/tests/router.php" >"$TMP/server.log" 2>&1 &
SERVER_PID=$!

for _ in $(seq 1 40); do
    if curl -fsS "http://127.0.0.1:${PORT}/admin/login.php" >/dev/null 2>&1; then
        break
    fi
    sleep 0.25
done

expect_status() {
    local path="$1" expected="$2"
    local status
    status="$(curl -sS -o /dev/null -w '%{http_code}' "http://127.0.0.1:${PORT}${path}")"
    if [ "$status" != "$expected" ]; then
        echo "FAIL ${path}: expected ${expected}, got ${status}" >&2
        echo "--- server log ---" >&2
        tail -n 40 "$TMP/server.log" >&2 || true
        exit 1
    fi
    echo "OK ${path} -> ${status}"
}

expect_status "/" 200
expect_status "/blog" 200
expect_status "/admin/login.php" 200
expect_status "/sitemap.xml" 200
expect_status "/robots.txt" 200
expect_status "/api/content.php" 401
expect_status "/admin/upload.php" 401

php "$ROOT/tests/category-tree.php"

echo "Smoke test passed."
