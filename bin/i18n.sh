#!/usr/bin/env bash
# Regenerate AI SEO Filler translation files (POT / PO update / MO / PHP).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

DOMAIN="ai-seo-filler"
POT="languages/${DOMAIN}.pot"

echo "→ Generating POT…"
wp i18n make-pot . "$POT" \
  --domain="$DOMAIN" \
  --slug="$DOMAIN" \
  --package-name="AI SEO Filler" \
  --headers='{"Report-Msgid-Bugs-To":"https://github.com/mauromolina/ai-seo-filler/issues","Last-Translator":"Mauro Molina Mazón","Language-Team":"Español"}' \
  --exclude="tests,node_modules,vendor,.git,bin"

echo "→ Updating PO files from POT…"
wp i18n update-po "$POT" languages/

echo "→ Compiling MO files…"
wp i18n make-mo languages/ languages/

echo "→ Compiling PHP translation files…"
wp i18n make-php languages/

echo "✓ Done. Review new empty msgstr entries in languages/*-*.po before shipping."
