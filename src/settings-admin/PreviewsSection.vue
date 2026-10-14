<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * The type previews are stored in, and what a JPEG of a transparent image
 * is laid on. Nextcloud stores a preview in the type it is given, and
 * re-encodes it at its own quality.
 */
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import OccCommand from './OccCommand.vue'
import { scalarCommand } from './occ'
import { t } from '../l10n'
import type { Changes, SettingsState } from './types'

defineProps<{
	state: SettingsState
	saving: boolean
}>()

const emit = defineEmits<{
	save: [changes: Changes]
}>()

const FORMATS: { value: string, label: string }[] = [
	{ value: 'nextcloud', label: t('large_file_previews', 'As Nextcloud\'s preview_format: WebP where it says webp, JPEG otherwise') },
	{ value: 'png', label: t('large_file_previews', 'PNG') },
	{ value: 'jpeg', label: t('large_file_previews', 'JPEG') },
	{ value: 'webp', label: t('large_file_previews', 'WebP; a PDF as JPEG where libvips is not there') },
]

/** Each radio group's name, which ties its radios together. */
const FORMAT_GROUP = 'lfp-preview-format'
const BACKGROUND_GROUP = 'lfp-preview-background'

const BACKGROUNDS: { value: string, label: string }[] = [
	{ value: 'white', label: t('large_file_previews', 'White') },
	{ value: 'black', label: t('large_file_previews', 'Black') },
]
</script>

<template>
	<div class="lfp-previews">
		<h3>{{ t('large_file_previews', 'Previews') }}</h3>
		<p class="lfp-hint">
			{{ t('large_file_previews', 'Nextcloud stores a preview in the type it is given, and re-encodes it at its own quality, the app config preview jpeg_quality and webp_quality.') }}
		</p>

		<h4>{{ t('large_file_previews', 'Format') }}</h4>
		<div class="lfp-choices" data-setting="preview_format">
			<NcCheckboxRadioSwitch v-for="format in FORMATS"
				:key="format.value"
				type="radio"
				:name="FORMAT_GROUP"
				:value="format.value"
				:model-value="state.scalars.preview_format.value as string"
				:disabled="saving"
				@update:model-value="emit('save', { scalars: { preview_format: $event as string } })">
				{{ format.label }}
			</NcCheckboxRadioSwitch>
			<OccCommand :command="scalarCommand('preview_format', state.scalars.preview_format.value)" />
		</div>

		<h4>{{ t('large_file_previews', 'Background of a JPEG') }}</h4>
		<p class="lfp-hint">
			{{ t('large_file_previews', 'A JPEG keeps no transparency: a transparent image is laid on this colour.') }}
		</p>
		<div class="lfp-choices" data-setting="preview_background">
			<NcCheckboxRadioSwitch v-for="background in BACKGROUNDS"
				:key="background.value"
				type="radio"
				:name="BACKGROUND_GROUP"
				:value="background.value"
				:model-value="state.scalars.preview_background.value as string"
				:disabled="saving"
				@update:model-value="emit('save', { scalars: { preview_background: $event as string } })">
				{{ background.label }}
			</NcCheckboxRadioSwitch>
			<OccCommand :command="scalarCommand('preview_background', state.scalars.preview_background.value)" />
		</div>
	</div>
</template>
