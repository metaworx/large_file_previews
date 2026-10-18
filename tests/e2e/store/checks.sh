# This app's checks after an install from the store, sourced by the vendored
# tests/e2e/store/check-install.sh after its generic ones: the obsolete
# settings gone, nothing processed until switched on, everything the app
# needs there (`lfp:check`), and, PDF switched on, a PDF's preview rendered
# by `occ preview:generate`. APP, VERSION, NC, occ() and check() are the
# caller's.
#
# shellcheck shell=bash
# shellcheck disable=SC2317

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

# Installed, the app takes nothing: every type ships off, and nothing is
# stored that would switch one on.
nothing_processed() {
	local shown
	shown="$(occ lfp:config:show --output=json)"
	printf '%s' "$shown" | python3 -c "
import sys, json
on = [row['mime'] for row in json.load(sys.stdin)['resolved'] if row['enabled']['value']]
print('enabled:', ', '.join(on) or 'none')
sys.exit(1 if on else 0)
"
}

# Everything the app needs is there: lfp:check exits 1 while something is
# missing, and lists what.
ready() {
	occ lfp:check
}

# A one-page PDF in the administrator's files, its preview made through
# Nextcloud's preview generation, which asks this app first once PDF is
# switched on, as an administrator does. pdftoppm rebuilds the missing
# cross-reference table, as it does for many real files. A render that
# fails leaves no PDF provider to fall back to, so the preview fails with
# it; the app's last failure says why.
preview_rendered() {
	local data files
	occ lfp:config:set --mime application/pdf --enabled=yes
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

app_checks() {
	check "no obsolete setting is stored"                     no_obsolete_settings
	check "nothing is processed until switched on"            nothing_processed
	check "everything the app needs is there (lfp:check)"     ready
	check "a PDF's preview is rendered"                       preview_rendered
}
