#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd -- "${SCRIPT_DIR}/.." && pwd)"

failures=0
warnings=0

expected_roles=(
  'code-explorer.toml:xhigh'
  'domain-worker.toml:xhigh'
  'domain-deep-worker.toml:xhigh'
  'laravel-worker.toml:xhigh'
  'laravel-deep-worker.toml:xhigh'
  'angular-worker.toml:xhigh'
  'angular-deep-worker.toml:xhigh'
  'test-runner.toml:high'
  'reviewer.toml:xhigh'
  'deep-reviewer.toml:max'
)
expected_skills=(
  terra-feature-plan
  terra-feature-implement
  terra-feature-status
  terra-feature-harden
  terra-routing-doctor
)

require_project_file() {
  local path="$1"
  if [[ -f "$PROJECT_ROOT/$path" ]]; then
    printf 'OK   project %s\n' "$path"
  else
    printf 'MISS project %s\n' "$path"
    failures=$((failures + 1))
  fi
}

detect_windows_home_from_wsl() {
  local win_home=""
  command -v wslpath >/dev/null 2>&1 || return 1
  if command -v powershell.exe >/dev/null 2>&1; then
    win_home="$(powershell.exe -NoProfile -Command '[Environment]::GetFolderPath("UserProfile")' 2>/dev/null | tr -d '\r' | tail -n 1)"
  elif command -v cmd.exe >/dev/null 2>&1; then
    win_home="$(cmd.exe /C echo %USERPROFILE% 2>/dev/null | tr -d '\r' | tail -n 1)"
  fi
  [[ -n "$win_home" ]] || return 1
  wslpath -u "$win_home" 2>/dev/null
}

check_home() {
  local home_root="$1"
  local label="$2"
  local item file expected actual skill

  printf '\n%s runtime: %s\n' "$label" "$home_root"

  for item in "${expected_roles[@]}"; do
    file="${item%%:*}"
    expected="${item##*:}"
    if [[ ! -f "$home_root/.codex/agents/$file" ]]; then
      printf 'MISS role %s\n' "$file"
      warnings=$((warnings + 1))
      continue
    fi
    actual="$(sed -n 's/^model_reasoning_effort[[:space:]]*=[[:space:]]*"\([^"]*\)".*/\1/p' "$home_root/.codex/agents/$file" | head -n 1)"
    if grep -Eq '^model[[:space:]]*=[[:space:]]*"gpt-5\.6-luna"' "$home_root/.codex/agents/$file" && [[ "$actual" == "$expected" ]]; then
      printf 'OK   role %-28s Luna/%s\n' "$file" "$actual"
    else
      printf 'WARN role %-28s expected Luna/%s\n' "$file" "$expected"
      warnings=$((warnings + 1))
    fi
  done

  for skill in "${expected_skills[@]}"; do
    if [[ -f "$home_root/.agents/skills/$skill/SKILL.md" ]]; then
      printf 'OK   skill %s\n' "$skill"
    else
      printf 'MISS skill %s\n' "$skill"
      warnings=$((warnings + 1))
    fi
  done

  if [[ -f "$home_root/.agents/skills/terra-feature-plan/references/PLANS.md" ]]; then
    printf 'OK   skill terra-feature-plan bundled PLANS.md fallback\n'
  else
    printf 'WARN skill terra-feature-plan missing bundled PLANS.md fallback\n'
    warnings=$((warnings + 1))
  fi
}

check_legacy_orchestration() {
  local agents="$PROJECT_ROOT/AGENTS.md"
  local tmp
  [[ -f "$agents" ]] || return 0
  tmp="$(mktemp)"
  awk '
    /<!-- terra-luna-orchestration:start v[0-9]+ -->/ { in_block=1; next }
    /<!-- terra-luna-orchestration:end v[0-9]+ -->/ { in_block=0; next }
    !in_block { print }
  ' "$agents" > "$tmp"

  if grep -Eq 'Kolejność faz \(ściśle\)|Standardowy przepływ dla większej zmiany|Twarde reguły orkiestracji Sol' "$tmp"; then
    printf 'WARN project AGENTS.md contains legacy hard-coded orchestration outside the managed block\n'
    warnings=$((warnings + 1))
  else
    printf 'OK   project no known legacy hard-coded orchestration conflict detected\n'
  fi
  rm -f "$tmp"
}

require_project_file 'AGENTS.md'
require_project_file '.agent/PLANS.md'
require_project_file '.agent/plans/execplan-template-v2.md'
require_project_file 'docs/codex/terra-luna-orchestration.md'
require_project_file 'scripts/validate-terra-luna.sh'

if grep -Fq '<!-- terra-luna-orchestration:start v4 -->' "$PROJECT_ROOT/AGENTS.md" 2>/dev/null; then
  printf 'OK   project AGENTS.md managed v4 block\n'
else
  printf 'WARN project AGENTS.md does not contain managed v4 block\n'
  warnings=$((warnings + 1))
fi

check_legacy_orchestration

if [[ -n "${HOME:-}" ]]; then
  check_home "$HOME" 'WSL/current-user'
fi

windows_home="$(detect_windows_home_from_wsl || true)"
if [[ -n "$windows_home" && "$windows_home" != "${HOME:-}" ]]; then
  check_home "$windows_home" 'Windows'
fi

if command -v codex >/dev/null 2>&1; then
  printf '\nCodex CLI: '
  codex --version || true
fi

printf '\nFile/config-layout validation complete.\n'
printf 'Runtime routing still requires a fresh task and $terra-routing-doctor.\n'

if (( failures > 0 )); then
  printf 'FAIL %d required project file(s) missing.\n' "$failures"
  exit 1
fi
if (( warnings > 0 )); then
  printf 'WARN %d runtime/layout warning(s) detected.\n' "$warnings"
fi
