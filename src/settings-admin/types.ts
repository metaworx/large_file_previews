/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * What GET /settings answers, as SettingsController::state() builds it.
 */

/** Each setting and its type, as ConfigLexicon::SCALARS declares them. */
export interface ScalarValues {
	min_size_mb: number
	max_size_mb: number
	timeout_enabled: boolean
	timeout_seconds: number
	timeout_kill_after_seconds: number
	failure_retry_minutes: number
	prlimit_enabled: boolean
	prlimit_as_mb: number
	vips_prlimit_as_mb: number
	prlimit_fsize_mb: number
	tiff_restrip_max_mb: number
	choom_enabled: boolean
	choom_adjust: number
	scan_native_enabled: boolean
	scan_coverage_percent: number
	vips_concurrency: number
	preview_format: string
	preview_background: string
}

export type ScalarKey = keyof ScalarValues

/** The settings whose value is of the type given: the switches, the numbers. */
export type ScalarKeyOf<T> = { [K in ScalarKey]: ScalarValues[K] extends T ? K : never }[ScalarKey]

export interface Scalar<T extends number | boolean | string = number | boolean | string> {
	value: T
	default: T
	min: number | null
	max: number | null
	/** The words a word may be; null for a number or a switch. */
	choices: string[] | null
	stored: boolean
}

export type Scalars = { [K in ScalarKey]: Scalar<ScalarValues[K]> }

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
 * untrusted and blocked, or libvips cannot block untrusted decoders at all.
 */
export interface Unsupported {
	decoder: string
	problem: 'missing' | 'untrusted' | 'unblockable'
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
	/** The rule in force: the shipped default with what is stored laid over it. */
	rule: Rule
	/** What is stored for the type, its own fields: what its command sets. */
	stored: Rule
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
	scalars: Scalars
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
	/**
	 * Whether libvips' untrusted decoders are allowed, in system config only,
	 * and whether this libvips can block them at all; null where it is not
	 * there.
	 */
	untrusted: { allowed: boolean, blockable: boolean | null }
	/** The app's version, as installed. */
	version: string
	/** Nextcloud's own preview settings, read only. */
	nextcloud: { preview_format: string, jpeg_quality: number, webp_quality: number }
	/** The distribution's family, where it is one the app knows packages for. */
	platform: 'debian' | 'fedora' | 'rhel' | 'alpine' | 'arch' | null
}

/** What PUT /settings takes; every part optional, null clears. */
export interface Changes {
	scalars?: { [K in ScalarKey]?: ScalarValues[K] | null }
	mimetypes?: Record<string, RuleChange | null>
	extensions?: Record<string, RuleChange | null>
}

/** One setting changed, or returned to its default with null. */
export function scalarChange<K extends ScalarKey>(key: K, value: ScalarValues[K] | null): Changes {
	return { scalars: { [key]: value } as Changes['scalars'] }
}
