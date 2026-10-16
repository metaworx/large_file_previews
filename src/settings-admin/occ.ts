/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * The occ command that sets what the page shows, as it stands: every
 * setting can be set from the shell as well, and the page says how.
 * Commands are not translated; they are what is typed.
 */
import type { Rule, Scalar } from './types'

const SET = 'occ lfp:config:set'
const UNSET = 'occ lfp:config:unset'

/** A word or a path, quoted where the shell would split or read it. */
function quoted(text: string): string {
	return /^[\w./:=+-]+$/.test(text) ? text : "'" + text.replace(/'/g, "'\\''") + "'"
}

export function scalarCommand(key: string, value: number | boolean | string): string {
	return [SET, key, typeof value === 'boolean' ? String(value) : quoted(String(value))].join(' ')
}

/** A setting as stored: set where it is, unset where it follows its default. */
export function storedScalarCommand(key: string, scalar: Scalar<number | boolean | string>): string {
	return scalar.stored ? scalarCommand(key, scalar.value) : [UNSET, key].join(' ')
}

/**
 * A MIME type's or an extension's rule, with the fields it sets; one that
 * sets none is cleared.
 */
export function ruleCommand(kind: 'mime' | 'ext', name: string, rule: Rule): string {
	const fields: string[] = []
	if (rule.enabled !== undefined) {
		fields.push('--enabled=' + (rule.enabled ? 'yes' : 'no'))
	}
	if (rule.min_size_mb !== undefined) {
		fields.push('--min=' + rule.min_size_mb)
	}
	if (rule.max_size_mb !== undefined) {
		fields.push('--max=' + rule.max_size_mb)
	}
	return fields.length === 0
		? [UNSET, '--' + kind, quoted(name)].join(' ')
		: [SET, '--' + kind, quoted(name), ...fields].join(' ')
}

/** A program's path, in system config. */
export function binaryCommand(program: string, path: string): string {
	return [SET, 'binaries.' + program, quoted(path)].join(' ')
}

/**
 * What changes whether libvips' untrusted decoders are allowed, in system
 * config: allowing them, or blocking them again.
 */
export function untrustedCommand(allowed: boolean): string {
	return allowed
		? [UNSET, 'vips_allow_untrusted'].join(' ')
		: [SET, 'vips_allow_untrusted', 'true'].join(' ')
}

/** One of Nextcloud's own system settings, as it stands; a number stored as one. */
export function systemCommand(key: string, value: string | number): string {
	const command = ['occ config:system:set', key, '--value=' + quoted(String(value))]
	if (typeof value === 'number') {
		command.push('--type=integer')
	}
	return command.join(' ')
}

/** One of Nextcloud's own app settings, as it stands. */
export function appConfigCommand(app: string, key: string, value: string | number): string {
	return ['occ config:app:set', app, key, '--value=' + quoted(String(value))].join(' ')
}

/** The scratch directory, in system config. */
export function scratchCommand(path: string): string {
	return [SET, 'scratch_directory', quoted(path)].join(' ')
}
