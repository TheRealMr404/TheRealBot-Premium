#!/usr/bin/env bash
set -euo pipefail

root=$(cd "$(dirname "$0")/.." && pwd)
source <(sed -n '/^docker_prepare_inapp_backup_runtime() {/,/^}/p' "$root/install.sh")

test_dir=$(mktemp -d)
cleanup() {
    rm -f "$test_dir/Dockerfile" "$test_dir/container-start.sh"
    rmdir "$test_dir"
}
trap cleanup EXIT

printf 'FROM php:8.2-apache\nCMD ["apache2-foreground"]\n' > "$test_dir/Dockerfile"
docker_prepare_inapp_backup_runtime "$test_dir"
docker_prepare_inapp_backup_runtime "$test_dir"

[ "$(grep -c '^# mirza-backup-runtime-v1$' "$test_dir/Dockerfile")" -eq 1 ]
grep -q 'default-mysql-client' "$test_dir/Dockerfile"
grep -q 'backupbot.php' "$test_dir/container-start.sh"
grep -q 'fragment_orders.php' "$test_dir/container-start.sh"
bash -n "$test_dir/container-start.sh"
echo 'docker_backup_runtime_test: OK'
