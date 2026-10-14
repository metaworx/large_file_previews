<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * The type previews are stored in, and what a JPEG of a transparent image
 * is laid on. Nextcloud stores a preview in the type it is given, and
 * re-encodes it at its own quality. The choices are the server's; one this
 * page has no words for is shown as it is.
 */
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import OccCommand from './OccCommand.vue'
import { scalarCommand } from './occ'
import { t } from '../l10n'
import { scalarChange } from './types'
import type { Changes, SettingsState } from './types'

defineProps<{
	state: SettingsState
	saving: boolean
}>()

const emit = defineEmits<{
	save: [changes: Changes]
}>()

const LABELS: Record<string, string> = {
	nextcloud: t('large_file_previews', 'As Nextcloud\'s preview_format: WebP where it says webp, JPEG otherwise'),
	png: t('large_file_previews', 'PNG'),
	jpeg: t('large_file_previews', 'JPEG'),
	webp: t('large_file_previews', 'WebP; a PDF as JPEG where libvips is not there'),
	white: t('large_file_previews', 'White'),
	black: t('large_file_previews', 'Black'),
}

/** Each radio group's name, which ties its radios together. */
const FORMAT_GROUP = 'lfp-preview-format'
const BACKGROUND_GROUP = 'lfp-preview-background'

function label(choice: string): string {
	return LABELS[choice] ?? choice
}
</script>

<template>
	<div class="lfp-previews">
		<h3>{{ t('large_file_previews', 'Previews') }}</h3>
		<p class="lfp-hint">
			{{ t('large_file_previews', 'Nextcloud stores a preview in the type it is given, and re-encodes it at its own quality, the app config preview jpeg_quality and webp_quality.') }}
		</p>

		<h4>{{ t('large_file_previews', 'Format') }}</h4>
		<div class="lfp-choices" data-setting="preview_format">
			<NcCheckboxRadioSwitch v-for="format in state.scalars.preview_format.choices ?? []"
				:key="format"
				type="radio"
				:name="FORMAT_GROUP"
				:value="format"
				:model-value="state.scalars.preview_format.value"
				:disabled="saving"
				@update:model-value="emit('save', scalarChange('preview_format', $event as string))">
				{{ label(format) }}
			</NcCheckboxRadioSwitch>
			<OccCommand :command="scalarCommand('preview_format', state.scalars.preview_format.value)" />
		</div>

		<h4>{{ t('large_file_previews', 'Background of a JPEG') }}</h4>
		<p class="lfp-hint">
			{{ t('large_file_previews', 'A JPEG keeps no transparency: a transparent image is laid on this colour.') }}
		</p>
		<div class="lfp-choices" data-setting="preview_background">
			<NcCheckboxRadioSwitch v-for="background in state.scalars.preview_background.choices ?? []"
				:key="background"
				type="radio"
				:name="BACKGROUND_GROUP"
				:value="background"
				:model-value="state.scalars.preview_background.value"
				:disabled="saving"
				@update:model-value="emit('save', scalarChange('preview_background', $event as string))">
				{{ label(background) }}
			</NcCheckboxRadioSwitch>
			<OccCommand :command="scalarCommand('preview_background', state.scalars.preview_background.value)" />
		</div>
	</div>
</template>
