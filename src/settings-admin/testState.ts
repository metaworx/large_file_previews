/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * A settings state as the server answers it out of the box, for specs.
 */
import type { ActiveRow, MimeRow, Renderer, Scalar, SettingsState } from './types'

const POPPLER: Renderer = { program: 'pdftoppm', available: true, unsupported: null }
const VIPS: Renderer = { program: 'vipsthumbnail', available: false, unsupported: null }

function scalar<T extends number | boolean>(value: T, min: number | null = null, max: number | null = null): Scalar<T> {
	return { value, default: value, min, max, choices: null, stored: false }
}

function word(value: string, choices: string[]): Scalar<string> {
	return { value, default: value, min: null, max: null, choices, stored: false }
}

function pdf(): MimeRow {
	return {
		mime: 'application/pdf',
		rule: { enabled: true },
		stored: {},
		default: { enabled: true },
		renderer: { ...POPPLER },
	}
}

function tiff(): MimeRow {
	return {
		mime: 'image/tiff',
		rule: { enabled: false, min_size_mb: 50 },
		stored: {},
		default: { enabled: false, min_size_mb: 50 },
		renderer: { ...VIPS },
	}
}

function activePdf(): ActiveRow {
	return {
		mime: 'application/pdf',
		extension: null,
		processing: 'enabled',
		renderer: { ...POPPLER },
		min_size_mb: { value: 0, source: 'default' },
		max_size_mb: { value: -1, source: 'default' },
	}
}

export function testState(): SettingsState {
	return {
		scalars: {
			min_size_mb: scalar(0, 0),
			max_size_mb: scalar(-1, -1),
			timeout_enabled: scalar(true),
			timeout_seconds: scalar(30, 1, 600),
			timeout_kill_after_seconds: scalar(5, 1, 60),
			failure_retry_minutes: scalar(60, 0, 10080),
			prlimit_enabled: scalar(true),
			prlimit_as_mb: scalar(512, 256, 65536),
			vips_prlimit_as_mb: scalar(1024, 256, 65536),
			prlimit_fsize_mb: scalar(1024, 64, 65536),
			tiff_restrip_max_mb: scalar(4096, 256, 1048576),
			choom_enabled: scalar(true),
			choom_adjust: scalar(1000, 0, 1000),
			scan_native_enabled: scalar(true),
			scan_coverage_percent: scalar(90, 50, 100),
			vips_concurrency: scalar(1, 1, 64),
			preview_format: word('nextcloud', ['nextcloud', 'png', 'jpeg', 'webp']),
			preview_background: word('white', ['white', 'black']),
		},
		active: [activePdf()],
		mimetypes: [pdf(), tiff()],
		extensions: [],
		prerequisites: [
			{ group: 'program', name: 'pdftoppm', status: 'ok', needed_for: 'rendering', detail: '/usr/bin/pdftoppm (finder)', install: { command: 'apt install poppler-utils', note: null } },
			{ group: 'program', name: 'choom', status: 'optional', needed_for: 'the OOM score, switched off', detail: '', install: { command: 'apt install util-linux', note: null } },
		],
		ready: true,
		problems: [],
		lastFailure: null,
		overrideKey: 'large_file_previews.binaries',
		programs: [
			{ name: 'pdftoppm', path: '/usr/bin/pdftoppm', source: 'finder', problem: null, version: 'pdftoppm version 24.02.0', install: { command: 'apt install poppler-utils', note: null } },
			{ name: 'vipsthumbnail', path: null, source: 'finder', problem: null, version: null, install: { command: 'apt install libvips-tools', note: null } },
		],
		scratch: { path: '/var/tmp/nextcloud', overridden: false },
		untrusted: { allowed: false, blockable: true },
		version: '0.1.0',
		platform: 'debian',
	}
}
