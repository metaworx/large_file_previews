/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * What GET /settings answers, as SettingsController::state() builds it.
 */

export interface Scalar {
	value: number | boolean
	default: number | boolean
	min: number | null
	max: number | null
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

export interface Sourced<T> {
	value: T
	source: 'default' | 'mime' | 'ext'
}

export interface Resolved {
	mime: string | null
	extension: string | null
	reachable: boolean
	enabled: Sourced<boolean>
	min_size_mb: Sourced<number>
	max_size_mb: Sourced<number>
}

export interface MimeRow {
	mime: string
	rule: Rule
	default: Rule
	renderer: boolean
	resolved: Resolved
}

export interface ExtensionRow {
	extension: string
	rule: Rule
	resolved: Resolved
}

export interface PrerequisiteItem {
	group: 'permission' | 'extension' | 'program'
	name: string
	status: 'ok' | 'missing' | 'disabled' | 'optional'
	needed_for: string
	detail: string
}

export interface Failure {
	time: number
	mime: string
	outcome: string
	reason: string
	exit_status: number | null
}

export interface SettingsState {
	scalars: Record<string, Scalar>
	mimetypes: MimeRow[]
	extensions: ExtensionRow[]
	prerequisites: PrerequisiteItem[]
	ready: boolean
	problems: string[]
	lastFailure: Failure | null
	overrideKey: string
}

/** What PUT /settings takes; every part optional, null clears. */
export interface Changes {
	scalars?: Record<string, number | boolean | null>
	mimetypes?: Record<string, RuleChange | null>
	extensions?: Record<string, RuleChange | null>
}
