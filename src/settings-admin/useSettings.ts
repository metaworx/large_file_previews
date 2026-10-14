/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * The page's one copy of the settings, and the only way it changes them.
 *
 * Each change is sent on its own, and the page shows what the server
 * answers rather than what was asked: the server holds the ranges and the
 * rules, and its refusal comes back in the reader's language.
 */
import { ref } from 'vue'
import { generateOcsUrl } from '@nextcloud/router'
import { t } from '../l10n'
import { toastError, toastSaved } from '../toast'
import type { Changes, SettingsState } from './types'

declare const OC: { requestToken: string }

export const SETTINGS_URL = '/apps/large_file_previews/settings'

const state = ref<SettingsState | null>(null)
const saving = ref(false)
const failed = ref(false)

export function useSettings() {
	async function load(): Promise<void> {
		failed.value = false
		try {
			const response = await fetch(generateOcsUrl(SETTINGS_URL), {
				headers: { 'OCS-APIRequest': 'true' },
			})
			if (!response.ok) {
				throw new Error(`HTTP ${response.status}`)
			}
			state.value = (await response.json()) as SettingsState
		} catch (e) {
			failed.value = true
		}
	}

	/** Sends one change; true where the server took it. */
	async function save(changes: Changes): Promise<boolean> {
		saving.value = true
		try {
			const response = await fetch(generateOcsUrl(SETTINGS_URL), {
				method: 'PUT',
				headers: {
					requesttoken: OC.requestToken,
					'OCS-APIRequest': 'true',
					'Content-Type': 'application/json',
				},
				body: JSON.stringify(changes),
			})
			const body = await response.json().catch(() => null) as SettingsState | { error?: string, state?: SettingsState } | null
			if (!response.ok) {
				const reason = body !== null && 'error' in body && typeof body.error === 'string'
					? body.error
					: t('large_file_previews', 'The setting could not be saved.')
				// Nothing was changed; the state shows what is, the field the
				// reader edited included.
				if (body !== null && 'error' in body && body.state !== undefined) {
					state.value = body.state
				}
				toastError(reason)
				return false
			}
			state.value = body as SettingsState
			toastSaved(t('large_file_previews', 'Saved.'))
			return true
		} catch (e) {
			toastError(t('large_file_previews', 'The setting could not be saved.'))
			return false
		} finally {
			saving.value = false
		}
	}

	/** Has the server find every program again; true where it did. */
	async function refresh(): Promise<boolean> {
		saving.value = true
		try {
			const response = await fetch(generateOcsUrl(SETTINGS_URL + '/refresh'), {
				method: 'POST',
				headers: {
					requesttoken: OC.requestToken,
					'OCS-APIRequest': 'true',
				},
			})
			if (!response.ok) {
				throw new Error(`HTTP ${response.status}`)
			}
			state.value = (await response.json()) as SettingsState
			toastSaved(t('large_file_previews', 'Programs found again.'))
			return true
		} catch (e) {
			toastError(t('large_file_previews', 'The programs could not be found again.'))
			return false
		} finally {
			saving.value = false
		}
	}

	return { state, saving, failed, load, save, refresh }
}
