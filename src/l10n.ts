/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * The app's translation functions. The app ships no translations yet;
 * every text still goes through here, so that adding them is tooling only.
 */
import { translate, translatePlural } from '@nextcloud/l10n'

type Values = Record<string, string | number>

/** The text in the user's language, with `{name}` placeholders filled from `values`. */
export function t(app: string, text: string, values?: Values): string {
	return translate(app, text, values, undefined, { escape: false, sanitize: false })
}

/** The singular or plural text for `count`, which fills `%n`; `{name}` placeholders as in t(). */
export function n(app: string, singular: string, plural: string, count: number, values?: Values): string {
	return translatePlural(app, singular, plural, count, values, { escape: false, sanitize: false })
}
