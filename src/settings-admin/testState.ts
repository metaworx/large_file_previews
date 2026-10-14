/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * A settings state as the server answers it out of the box, for specs.
 */
import type { MimeRow, Resolved, Scalar, SettingsState } from './types'

function scalar(value: number | boolean, min: number | null = null, max: number | null = null): Scalar {
	return { value, default: value, min, max, stored: false }
}

function resolved(mime: string, enabled: boolean, min: number): Resolved {
	return {
		mime,
		extension: null,
		reachable: true,
		enabled: { value: enabled, source: 'mime' },
		min_size_mb: { value: min, source: min === 0 ? 'default' : 'mime' },
		max_size_mb: { value: -1, source: 'default' },
	}
}

function pdf(): MimeRow {
	return {
		mime: 'application/pdf',
		rule: { enabled: true },
		default: { enabled: true },
		renderer: true,
		resolved: resolved('application/pdf', true, 0),
	}
}

function tiff(): MimeRow {
	return {
		mime: 'image/tiff',
		rule: { enabled: false, min_size_mb: 50 },
		default: { enabled: false, min_size_mb: 50 },
		renderer: false,
		resolved: resolved('image/tiff', false, 50),
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
			prlimit_enabled: scalar(true),
			prlimit_as_mb: scalar(2048, 256, 65536),
			choom_enabled: scalar(true),
			choom_adjust: scalar(1000, 0, 1000),
			scan_native_enabled: scalar(true),
			scan_coverage_percent: scalar(90, 50, 100),
		},
		mimetypes: [pdf(), tiff()],
		extensions: [],
		prerequisites: [
			{ group: 'program', name: 'pdftoppm', status: 'ok', needed_for: 'rendering', detail: '/usr/bin/pdftoppm (finder)' },
			{ group: 'program', name: 'choom', status: 'optional', needed_for: 'the OOM score, switched off', detail: '' },
		],
		ready: true,
		problems: [],
		lastFailure: null,
		overrideKey: 'large_file_previews.binaries',
	}
}
