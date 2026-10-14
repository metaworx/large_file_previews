/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Why a type's renderer cannot render it here, said once for the page: the
 * sentence a type's row shows and the badge the active table shows.
 */
import { t } from '../l10n'
import type { Renderer } from './types'

export interface RendererProblem {
	text: string
	badge: string
}

/** What is wrong with a type's renderer; null where it can render. */
export function rendererProblem(renderer: Renderer): RendererProblem | null {
	if (renderer.program === null) {
		return {
			text: t('large_file_previews', 'Not a type this app handles'),
			badge: t('large_file_previews', 'not handled'),
		}
	}
	switch (renderer.unsupported?.problem) {
	case 'untrusted':
		return {
			text: t('large_file_previews', 'Its decoder, {decoder}, is untrusted, and untrusted decoders are blocked', { decoder: renderer.unsupported.decoder }),
			badge: t('large_file_previews', 'decoder blocked'),
		}
	case 'unblockable':
		return {
			text: t('large_file_previews', 'libvips here is older than 8.13, or did not report its version, and cannot block untrusted decoders, so no image is rendered with it'),
			badge: t('large_file_previews', 'libvips too old'),
		}
	case 'missing':
		return {
			text: t('large_file_previews', 'libvips here has no decoder for it ({decoder})', { decoder: renderer.unsupported.decoder }),
			badge: t('large_file_previews', 'no decoder'),
		}
	}
	if (!renderer.available) {
		return {
			text: t('large_file_previews', 'No suitable renderer ({program})', { program: renderer.program }),
			badge: t('large_file_previews', 'not available'),
		}
	}
	return null
}
