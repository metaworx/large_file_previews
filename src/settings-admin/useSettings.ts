/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * The page's one copy of the settings, and the only way it changes them.
 *
 * Each change is sent on its own, and the page shows what the server
 * answers rather than what was asked: the server holds the ranges and the
 * rules, and its refusal comes back in the reader's language, with the
 * state as it stands.
 */
import { ref } from 'vue'
import { ocsCall, t, toastError, toastSaved } from '@metaworx/nextcloud-vue'
import type { Changes, ImportAnswer, SettingsState } from './types'

export const SETTINGS_URL = '/apps/large_file_previews/settings'

const state = ref<SettingsState | null>(null)
const saving = ref(false)
const failed = ref(false)

/** What the server answered: the state, or why not, with the state. */
interface Answer {
	state: SettingsState | null
	error: string | null
}

/** A refusal's reason, where the server gave one; '' where it gave none. */
function reason(body: unknown): string {
	const error = body !== null && typeof body === 'object' && 'error' in body ? body.error : null
	return typeof error === 'string' ? error : ''
}

/**
 * One request that answers the state; a refusal's reason is the server's
 * where it gave one.
 */
async function request(method: 'GET' | 'PUT' | 'POST', path: string, changes?: Changes): Promise<Answer> {
	const answer = await ocsCall(method, SETTINGS_URL + path, changes)
	if (answer.ok) {
		return { state: answer.body as SettingsState, error: null }
	}
	const refusal = answer.body as { state?: SettingsState } | null
	return { state: refusal?.state ?? null, error: reason(answer.body) }
}

export function useSettings() {
	async function load(): Promise<void> {
		failed.value = false
		const answer = await request('GET', '')
		if (answer.error !== null) {
			failed.value = true
			return
		}
		state.value = answer.state
	}

	/** Sends one change; true where the server took it. */
	async function save(changes: Changes): Promise<boolean> {
		return send('PUT', '', changes, t('large_file_previews', 'Saved.'), t('large_file_previews', 'The setting could not be saved.'))
	}

	/** Has the server find every program again; true where it did. */
	async function refresh(): Promise<boolean> {
		return send('POST', '/refresh', undefined, t('large_file_previews', 'Programs found again.'), t('large_file_previews', 'The programs could not be found again.'))
	}

	/**
	 * A change or a refresh: the state the server answers is shown, a
	 * refusal's too, and the reader told how it went.
	 */
	async function send(method: 'PUT' | 'POST', path: string, changes: Changes | undefined, done: string, failure: string): Promise<boolean> {
		saving.value = true
		try {
			const answer = await request(method, path, changes)
			if (answer.state !== null) {
				state.value = answer.state
			}
			if (answer.error !== null) {
				toastError(answer.error === '' ? failure : answer.error)
				return false
			}
			toastSaved(done)
			return true
		} finally {
			saving.value = false
		}
	}

	/** What is stored, as the file `occ lfp:config:export` writes; null where it could not be had. */
	async function exportConfig(): Promise<Record<string, unknown> | null> {
		const answer = await ocsCall('GET', SETTINGS_URL + '/export')
		if (!answer.ok || answer.body === null || typeof answer.body !== 'object') {
			toastError(t('large_file_previews', 'The configuration could not be exported.'))
			return null
		}
		return answer.body as Record<string, unknown>
	}

	/**
	 * A configuration file, read by the server: what it would change, what
	 * it leaves out; with `apply`, written, and the state shown with it.
	 * The refusal's reason where it is refused.
	 */
	async function importConfig(document: string, replace: boolean, apply: boolean): Promise<ImportAnswer | { error: string }> {
		saving.value = apply
		try {
			const answer = await ocsCall('POST', SETTINGS_URL + '/import', { document, replace, apply })
			if (!answer.ok) {
				return { error: reason(answer.body) || t('large_file_previews', 'The file could not be read.') }
			}
			const read = answer.body as ImportAnswer
			if (read.state !== undefined) {
				state.value = read.state
			}
			return read
		} finally {
			saving.value = false
		}
	}

	return { state, saving, failed, load, save, refresh, exportConfig, importConfig }
}
