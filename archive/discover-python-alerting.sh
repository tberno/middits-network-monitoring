#!/usr/bin/env bash
set -euo pipefail

OUT_DIR="${OUT_DIR:-$HOME/python-alerting-discovery-$(date +%Y%m%d-%H%M%S)}"
LIBRENMS_DIR="${LIBRENMS_DIR:-/opt/librenms}"

mkdir -p \
  "$OUT_DIR/files" \
  "$OUT_DIR/metadata" \
  "$OUT_DIR/systemd" \
  "$OUT_DIR/cron" \
  "$OUT_DIR/env"

log() {
  printf '[python-discovery] %s\n' "$*"
}

redact_file() {
  local file="$1"
  [[ -f "$file" ]] || return 0

  perl -0pi -e '
    s#https://hooks\.slack\.com/services/[A-Za-z0-9/_-]+#https://hooks.slack.com/services/REDACTED#g;
    s#(?i)(password|passwd|secret|token|api[_-]?key|webhook_url|signing[_-]?secret)(["'\'' ]*[:=]["'\'' ]*)[^"'\''\n, }]+#$1$2REDACTED#g;
    s#(?i)(Authorization:\s*(?:Bearer|Basic)\s+)[A-Za-z0-9._~+/=-]+#$1REDACTED#g;
    s#xox[baprs]-[A-Za-z0-9-]+#xox-REDACTED#g;
  ' "$file" || true
}

redact_tree() {
  find "$OUT_DIR" -type f -print0 | while IFS= read -r -d '' f; do
    redact_file "$f"
  done
}

run_to_file() {
  local file="$1"
  shift
  if "$@" > "$file" 2>&1; then
    return 0
  fi
  printf 'command failed: %q ' "$@" > "$file"
  printf '\n' >> "$file"
  return 0
}

copy_file_preserving_path() {
  local file="$1"
  local normalized
  normalized="$(echo "$file" | sed 's#^/##; s#[/ ]#__#g')"
  cp -a "$file" "$OUT_DIR/files/$normalized" 2>/dev/null || true
}

is_noise_path() {
  local file="$1"
  case "$file" in
    */node_modules/*|*/site-packages/*|*/dist-packages/*|*/vendor/*|*/.cache/*|*/__pycache__/*)
      return 0
      ;;
    *)
      return 1
      ;;
  esac
}

log "Writing discovery output to $OUT_DIR"

{
  date
  hostname -f 2>/dev/null || hostname
  uname -a
  whoami
} > "$OUT_DIR/metadata/host.txt" 2>&1 || true

run_to_file "$OUT_DIR/metadata/python3-version.txt" python3 --version
run_to_file "$OUT_DIR/metadata/python-version.txt" python --version
run_to_file "$OUT_DIR/metadata/pip3-freeze.txt" pip3 freeze
run_to_file "$OUT_DIR/metadata/pip-freeze.txt" pip freeze

log "Finding Python files and alert-related scripts"
SEARCH_ROOTS=(
  "$LIBRENMS_DIR"
  /opt
  /usr/local/bin
  /usr/local/sbin
  /etc/librenms
  /etc/cron.d
  /etc/systemd/system
  "$HOME"
)

: > "$OUT_DIR/metadata/python-files.txt"
: > "$OUT_DIR/metadata/alert-related-files.txt"

for root in "${SEARCH_ROOTS[@]}"; do
  [[ -d "$root" ]] || continue

  find "$root" -maxdepth 6 -type f -name '*.py' \
    ! -path '*/node_modules/*' \
    ! -path '*/site-packages/*' \
    ! -path '*/dist-packages/*' \
    ! -path '*/vendor/*' \
    ! -path '*/__pycache__/*' \
    >> "$OUT_DIR/metadata/python-files.txt" 2>/dev/null || true

  find "$root" -maxdepth 6 -type f \( \
      -iname '*alert*' -o \
      -iname '*slack*' -o \
      -iname '*mist*' -o \
      -iname '*librenms*' -o \
      -iname '*graylog*' -o \
      -iname '*network*' -o \
      -iname '*switch*' \
    \) \
    ! -path '*/node_modules/*' \
    ! -path '*/site-packages/*' \
    ! -path '*/dist-packages/*' \
    ! -path '*/vendor/*' \
    ! -path '*/__pycache__/*' \
    >> "$OUT_DIR/metadata/alert-related-files.txt" 2>/dev/null || true
done

sort -u "$OUT_DIR/metadata/python-files.txt" -o "$OUT_DIR/metadata/python-files.txt"
sort -u "$OUT_DIR/metadata/alert-related-files.txt" -o "$OUT_DIR/metadata/alert-related-files.txt"

log "Searching file contents for alerting keywords"
KEYWORDS='slack|chat.postMessage|chat.update|response_url|webhook|LibreNMS|librenms|Mist|mist|alert|transport|syslog|graylog|interface|switch|dhcp|lease|blocks|attachments'

while IFS= read -r file; do
  [[ -f "$file" ]] || continue
  if is_noise_path "$file"; then
    continue
  fi
  grep -IlE "$KEYWORDS" "$file" 2>/dev/null || true
done < <(cat "$OUT_DIR/metadata/python-files.txt" "$OUT_DIR/metadata/alert-related-files.txt" | sort -u) \
  > "$OUT_DIR/metadata/content-matches.txt"

log "Copying matched files for review"
while IFS= read -r file; do
  [[ -f "$file" ]] || continue
  if is_noise_path "$file"; then
    continue
  fi
  copy_file_preserving_path "$file"
done < "$OUT_DIR/metadata/content-matches.txt"

log "Capturing references and invocation paths"
while IFS= read -r file; do
  [[ -f "$file" ]] || continue
  if is_noise_path "$file"; then
    continue
  fi
  {
    echo "### $file"
    grep -nEi "$KEYWORDS" "$file" 2>/dev/null || true
    echo
  } >> "$OUT_DIR/metadata/content-match-lines.txt"
done < "$OUT_DIR/metadata/content-matches.txt"

run_to_file "$OUT_DIR/cron/current-user-crontab.txt" crontab -l
run_to_file "$OUT_DIR/cron/etc-cron-d-list.txt" find /etc/cron.d -maxdepth 1 -type f -print

if [[ -d /etc/cron.d ]]; then
  find /etc/cron.d -maxdepth 1 -type f -print0 | while IFS= read -r -d '' f; do
    cp -a "$f" "$OUT_DIR/cron/$(basename "$f")" 2>/dev/null || true
  done
fi

run_to_file "$OUT_DIR/systemd/list-units.txt" systemctl list-units --type=service --all
run_to_file "$OUT_DIR/systemd/list-timers.txt" systemctl list-timers --all
run_to_file "$OUT_DIR/systemd/python-alert-units.txt" sh -c 'systemctl list-unit-files | grep -Ei "python|alert|slack|mist|librenms|graylog" || true'

systemctl list-unit-files 2>/dev/null \
  | awk '/python|alert|slack|mist|librenms|graylog/ {print $1}' \
  | while IFS= read -r unit; do
    [[ -n "$unit" ]] || continue
    systemctl cat "$unit" > "$OUT_DIR/systemd/$unit.txt" 2>/dev/null || true
  done

log "Capturing environment-style config names without dumping shell history"
env | sort \
  | grep -Ei 'librenms|graylog|slack|mist|alert|python|proxy' \
  > "$OUT_DIR/env/relevant-env.txt" 2>/dev/null || true

log "Building summary"
{
  echo "# Python Alerting Discovery Summary"
  echo
  echo "Generated: $(date)"
  echo "Host: $(hostname -f 2>/dev/null || hostname)"
  echo
  echo "## Counts"
  echo
  printf 'Python files found: '
  wc -l < "$OUT_DIR/metadata/python-files.txt"
  printf 'Alert-related filenames found: '
  wc -l < "$OUT_DIR/metadata/alert-related-files.txt"
  printf 'Content matches copied: '
  wc -l < "$OUT_DIR/metadata/content-matches.txt"
  echo
  echo "## Most Important Next Files"
  echo
  { sed 's#^#- #' "$OUT_DIR/metadata/content-matches.txt" | head -50; } || true
} > "$OUT_DIR/README.md"

log "Redacting copied output"
redact_tree

tarball="$OUT_DIR.tar.gz"
log "Creating $tarball"
tar -C "$(dirname "$OUT_DIR")" -czf "$tarball" "$(basename "$OUT_DIR")"

log "Done"
echo "$OUT_DIR"
echo "$tarball"
