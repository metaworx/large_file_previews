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
import { getRequestToken } from '@nextcloud/auth'
import { generateOcsUrl } from '@nextcloud/router'
import { t } from '../l10n'
import { toastError, toastSaved } from '../toast'
import type { Changes, SettingsState } from './types'

export const SETTINGS_URL = '/apps/large_file_previews/settings'

const state = ref<SettingsState | null>(null)
const saving = ref(false)
const failed = ref(false)

/** What the server answered: the state, or why not, with the state. */
interface Answer {
	state: SettingsState | null
	error: string | null
}

/**
 * One request to the settings API, with the request token; never throws.
 * A refusal's reason is the server's where it gave one.
 */
async function request(method: 'GET' | 'PUT' | 'POST', path: string, changes?: Changes): Promise<Answer> {
	try {
		const response = await fetch(generateOcsUrl(SETTINGS_URL + path), {
			method,
			headers: {
				requesttoken: getRequestToken() ?? '',
				'OCS-APIRequest': 'true',
				...(changes === undefined ? {} : { 'Content-Type': 'application/json' }),
			},
			...(changes === undefined ? {} : { body: JSON.stringify(changes) }),
		})
		const body = await response.json().catch(() => null) as SettingsState | { error?: unknown, state?: SettingsState } | null
		if (response.ok) {
			return { state: body as SettingsState, error: null }
		}
		const refusal = body !== null && 'error' in body ? body : null
		return {
			state: refusal?.state ?? null,
			error: typeof refusal?.error === 'string' ? refusal.error : '',
		}
	} catch (e) {
		return { state: null, error: '' }
	}
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

	return { state, saving, failed, load, save, refresh }
}
