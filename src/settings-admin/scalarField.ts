/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * A number setting's field, cleared back to its default by removing its
 * value: it shows what is stored, empty where nothing is, its default as
 * the placeholder, and its command unsets it where nothing is stored.
 */
import { t } from '../l10n'
import type { Scalar } from './types'

/** What is stored, or null where the setting follows its default. */
export function storedNumber(scalar: Scalar<number>): number | null {
	return scalar.stored ? scalar.value : null
}

/** The default, as the placeholder of an empty field: "30 (default)". */
export function defaultPlaceholder(scalar: Scalar<number>): string {
	return t('large_file_previews', '{value} (default)', { value: String(scalar.default) })
}
