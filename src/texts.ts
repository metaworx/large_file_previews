/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * The texts of the shared components and toasts, in this app's
 * translations: spelled out here, so that the translation tool finds them
 * in the app's own sources, and handed over once, in the page's entry.
 */
import { t, type Texts } from '@metaworx/nextcloud-vue'

export const texts: Texts = {
	save: t('large_file_previews', 'Save'),
	saveLabel: (label) => t('large_file_previews', 'Save {label}', { label }),
	reset: t('large_file_previews', 'Reset'),
	resetLabel: (label) => t('large_file_previews', 'Reset {label}', { label }),
	help: (label) => t('large_file_previews', 'Help: {label}', { label }),
	copyCommand: t('large_file_previews', 'Copy the command'),
	commandCopied: t('large_file_previews', 'Command copied.'),
	commandNotCopied: t('large_file_previews', 'The command could not be copied; select it instead.'),
	saved: t('large_file_previews', 'Saved.'),
}
