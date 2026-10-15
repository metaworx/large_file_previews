#!/usr/bin/env bash
#
# The install steps of the app, checked on a server it was just installed
# on from the app store, and a smoke test of the installed copy: the
# version the server runs and records, no upgrade pending, the obsolete
# settings gone, everything the app needs there (`lfp:check`), and a PDF's
# preview rendered by `occ preview:generate`. Prints one line per check and
# exits non-zero if any failed. The app id is the checkout's manifest's.
#
# Usage: tests/e2e/store/check-install.sh <nextcloud-dir> <version>

set -uo pipefail

NC="${1:?the Nextcloud directory}"
VERSION="${2:?the version the store published}"
APP="$(sed -n 's|.*<id>\([^<]*\)</id>.*|\1|p' appinfo/info.xml | head -n1 | tr -d '[:space:]')"

occ() { php "$NC/occ" "$@"; }

failed=0

check() {
	local what="$1"
	shift
	local out
	if out="$( "$@" 2>&1 )"; then
		echo "ok      ${what}"
	else
		echo "FAILED  ${what}"
		# What the check read, so a failure on a runner can be read too.
		printf '%s\n' "$out" | sed 's/^/        | /' | head -40
		failed=1
	fi
}

# Each check prints what it read before judging it, so the lines above have
# something to show.

enabled_version() {
	local got
	got="$(occ app:list --output=json | python3 -c "import sys, json; print(json.load(sys.stdin)['enabled'].get('${APP}', ''))")"
	echo "enabled: ${got}"
	[ "$got" = "$VERSION" ]
}

recorded_version() {
	local got
	got="$(occ config:app:get "$APP" installed_version)"
	echo "installed_version: ${got}"
	[ "$got" = "$VERSION" ]
}

no_upgrade_pending() {
	local status
	status="$(occ status --output=json)"
	echo "$status"
	printf '%s' "$status" | python3 -c "import sys, json; sys.exit(0 if json.load(sys.stdin)['needsDbUpgrade'] is False else 1)"
}

# The repair step that removes settings the app no longer has ran on
# install: none of them is stored.
no_obsolete_settings() {
	local keys
	keys="$(occ config:list "$APP" --output=json)"
	echo "$keys"
	printf '%s' "$keys" | python3 -c "
import sys, json
stored = json.load(sys.stdin).get('apps', {}).get('${APP}', {})
sys.exit(1 if 'vips_block_untrusted' in stored else 0)
"
}

# Everything the app needs is there: lfp:check exits 1 while something is
# missing, and lists what.
ready() {
	occ lfp:check
}

# A one-page PDF in the administrator's files, its preview made through
# Nextcloud's preview generation, which asks this app first. pdftoppm
# rebuilds the missing cross-reference table, as it does for many real
# files. A render that fails leaves no PDF provider to fall back to, so
# the preview fails with it; the app's last failure says why.
preview_rendered() {
	local data files
	data="$(occ config:system:get datadirectory)"
	files="${data}/admin/files"
	mkdir -p "$files"
	printf '%s\n' '%PDF-1.4' \
		'1 0 obj <</Type /Catalog /Pages 2 0 R>> endobj' \
		'2 0 obj <</Type /Pages /Kids [3 0 R] /Count 1>> endobj' \
		'3 0 obj <</Type /Page /Parent 2 0 R /MediaBox [0 0 200 100]>> endobj' \
		'trailer <</Root 1 0 R>>' \
		'%%EOF' > "${files}/lfp-smoke.pdf"
	occ files:scan --path=/admin/files/lfp-smoke.pdf > /dev/null
	if ! occ preview:generate /admin/files/lfp-smoke.pdf --size 256x256; then
		occ config:app:get "$APP" last_failure || true
		return 1
	fi
	echo "last_failure: $(occ config:app:get "$APP" last_failure 2>/dev/null || echo none)"
	! occ config:app:get "$APP" last_failure > /dev/null 2>&1
}

check "the server runs ${VERSION}"                      enabled_version
check "the server records ${VERSION} as installed"      recorded_version
check "no upgrade is pending"                           no_upgrade_pending
check "no obsolete setting is stored"                   no_obsolete_settings
check "everything the app needs is there (lfp:check)"   ready
check "a PDF's preview is rendered"                     preview_rendered

exit "$failed"
