#!/usr/bin/env bash
# generate-pins.sh — atomically regenerate pins.json from Google Analytics data.
# Called by the Kubernetes CronJob every 15 minutes.
set -euo pipefail

cd /var/www/html

TMP_FILE="${PINS_FILE}.tmp"

php data.php > "${TMP_FILE}"
mv "${TMP_FILE}" "${PINS_FILE}"

echo "Generated ${PINS_FILE} at $(date -u +%FT%TZ)"
