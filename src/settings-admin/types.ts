/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * What GET /settings answers, as SettingsController::state() builds it.
 */

export interface Scalar {
	value: number | boolean | string
	default: number | boolean | string
	min: number | null
	max: number | null
	/** The words a word may be; null for a number or a switch. */
	choices: string[] | null
	stored: boolean
}

/** A rule's fields; an absent one is left to the level below. */
export interface Rule {
	enabled?: boolean
	min_size_mb?: number
	max_size_mb?: number
}

/** A change to a rule: a field set, or cleared with null. */
export type RuleChange = {
	[K in keyof Rule]?: Rule[K] | null
}

/**
 * Why a renderer cannot decode a type here: its decoder is missing, or
 * untrusted and blocked.
 */
export interface Unsupported {
	decoder: string
	problem: 'missing' | 'untrusted'
}

/**
 * The program that renders a type, whether the server has it and it can
 * decode the type; no program where the type is not one this app handles
 * at all.
 */
export interface Renderer {
	program: string | null
	available: boolean
	unsupported: Unsupported | null
}

export interface Sourced<T> {
	value: T
	source: 'default' | 'mime' | 'ext'
}

/** One row of what the configuration resolves to. */
export interface ActiveRow {
	mime: string | null
	extension: string | null
	processing: 'enabled' | 'disabled' | 'excluded'
	renderer: Renderer
	min_size_mb: Sourced<number>
	max_size_mb: Sourced<number>
}

export interface MimeRow {
	mime: string
	rule: Rule
	default: Rule
	renderer: Renderer
}

export interface ExtensionRow {
	extension: string
	mime: string | null
	rule: Rule
	renderer: Renderer
	/** Selected anyway by its MIME type, with no size of its own. */
	noEffect: boolean
}

/**
 * What installs a program on the server's platform: the command, null where
 * no package brings it, and a note where one is due.
 */
export interface Install {
	command: string | null
	note: string | null
}

export interface PrerequisiteItem {
	group: 'permission' | 'extension' | 'program'
	name: string
	status: 'ok' | 'missing' | 'disabled' | 'optional'
	needed_for: string
	detail: string
	/** Null where the platform is not one the app knows packages for. */
	install: Install | null
}

export interface Failure {
	time: number
	mime: string
	outcome: string
	reason: string
	exit_status: number | null
}

/** A program the app runs: where it is, how it was found, its version. */
export interface ProgramRow {
	name: string
	path: string | null
	source: 'finder' | 'override'
	problem: string | null
	version: string | null
	install: Install | null
}

export interface SettingsState {
	scalars: Record<string, Scalar>
	active: ActiveRow[]
	mimetypes: MimeRow[]
	extensions: ExtensionRow[]
	prerequisites: PrerequisiteItem[]
	ready: boolean
	problems: string[]
	lastFailure: Failure | null
	overrideKey: string
	programs: ProgramRow[]
	/** Where a large working copy goes; set in system config only. */
	scratch: { path: string, overridden: boolean }
	/** The distribution's family, where it is one the app knows packages for. */
	platform: 'debian' | 'fedora' | 'rhel' | 'alpine' | 'arch' | null
}

/** What PUT /settings takes; every part optional, null clears. */
export interface Changes {
	scalars?: Record<string, number | boolean | string | null>
	mimetypes?: Record<string, RuleChange | null>
	extensions?: Record<string, RuleChange | null>
}
