/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * The page's notices: one for a change the server confirmed, one for a
 * change it refused or never got.
 */
import { showError, showSuccess } from '@nextcloud/dialogs'
import '@nextcloud/dialogs/style.css'
import './toast.css'

/** Long enough to read, short enough not to need dismissing. */
const SUCCESS_TIMEOUT = 5000

export function toastSaved(text: string): void {
	showSuccess(text, { timeout: SUCCESS_TIMEOUT })
}

/** Errors keep the library's timing: they stay while hovered. */
export function toastError(text: string): void {
	showError(text)
}
