<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * The type previews are stored in, what a JPEG of a transparent image is
 * laid on, and the quality they are rendered at. Nextcloud stores a preview
 * in the type it is given: the largest, streamed, as rendered; every other,
 * and the largest where the stream is off, re-encoded at its own quality.
 * The choices are the server's; one this page has no words for is shown as
 * it is. Nextcloud's own settings are beneath, each with the occ command
 * that sets it: its preview format, read only, in config.php; its JPEG and
 * WebP quality, set here, emptied back to its default.
 */
import { computed } from 'vue'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import HelpPopover from './HelpPopover.vue'
import NumberField from './NumberField.vue'
import OccCommand from './OccCommand.vue'
import { appConfigCommand, scalarCommand, storedScalarCommand, systemCommand } from './occ'
import { defaultPlaceholder, storedNumber } from './scalarField'
import { t } from '../l10n'
import { scalarChange } from './types'
import type { Changes, SettingsState } from './types'

const props = defineProps<{
	state: SettingsState
	saving: boolean
}>()

/** What the quality is of: the largest preview where it is streamed. */
const qualityHelp = computed(() => props.state.scalars.preview_stream.value
	? t('large_file_previews', 'The largest preview\'s, JPEG or WebP, stored as rendered; the smaller sizes are Nextcloud\'s, at its own quality.')
	: t('large_file_previews', 'Not streamed, only the image Nextcloud encodes again at its own quality, which sets the preview\'s.'))

const emit = defineEmits<{
	save: [changes: Changes]
}>()

const LABELS: Record<string, string> = {
	nextcloud: t('large_file_previews', 'As Nextcloud\'s preview_format'),
	png: t('large_file_previews', 'PNG'),
	jpeg: t('large_file_previews', 'JPEG'),
	webp: t('large_file_previews', 'WebP'),
	white: t('large_file_previews', 'White'),
	black: t('large_file_previews', 'Black'),
}

/** Nextcloud's own default for either quality, where none is stored. */
const QUALITY_DEFAULT = 80

/** Nextcloud's qualities, the preview app's config, set on this page. */
const QUALITIES: { key: 'jpeg_quality' | 'webp_quality', label: string }[] = [
	{ key: 'jpeg_quality', label: t('large_file_previews', 'JPEG quality, 10 to 100') },
	{ key: 'webp_quality', label: t('large_file_previews', 'WebP quality, 10 to 100') },
]

/**
 * What Nextcloud stores, by this app's format and the stream: the largest
 * preview, and the sizes it makes from it. A row of one result holds for
 * both columns.
 */
const OUTCOMES: { format: string, stream: string, largest: string, smaller?: string }[] = [
	{ format: 'jpeg', stream: t('large_file_previews', 'on'), largest: t('large_file_previews', 'JPEG, this app\'s quality'), smaller: t('large_file_previews', 'JPEG, Nextcloud\'s JPEG quality') },
	{ format: 'jpeg', stream: t('large_file_previews', 'off'), largest: t('large_file_previews', 'JPEG, Nextcloud\'s JPEG quality'), smaller: t('large_file_previews', 'JPEG, Nextcloud\'s JPEG quality') },
	{ format: 'webp', stream: t('large_file_previews', 'on'), largest: t('large_file_previews', 'WebP, this app\'s quality'), smaller: t('large_file_previews', 'WebP, Nextcloud\'s WebP quality') },
	{ format: 'webp', stream: t('large_file_previews', 'off'), largest: t('large_file_previews', 'WebP, Nextcloud\'s WebP quality'), smaller: t('large_file_previews', 'WebP, Nextcloud\'s WebP quality') },
	{ format: 'png', stream: t('large_file_previews', 'on or off'), largest: t('large_file_previews', 'PNG, lossless') },
	{ format: 'nextcloud', stream: t('large_file_previews', 'on or off'), largest: t('large_file_previews', 'As WebP where Nextcloud\'s preview_format says webp, as JPEG otherwise') },
]

/** Each radio group's name, which ties its radios together. */
const FORMAT_GROUP = 'lfp-preview-format'
const BACKGROUND_GROUP = 'lfp-preview-background'

function label(choice: string): string {
	return LABELS[choice] ?? choice
}
</script>

<template>
	<div class="lfp-previews">
		<h3 class="lfp-named">
			{{ t('large_file_previews', 'Previews') }}
			<HelpPopover :text="t('large_file_previews', 'Nextcloud stores the largest preview in the type it is given, and makes the smaller sizes from it, in that type, at its own quality, the app config preview jpeg_quality and webp_quality. The largest keeps this app\'s quality where it is stored as rendered:')"
				:label="t('large_file_previews', 'Previews')">
				<table class="lfp-outcomes">
					<thead>
						<tr>
							<th>{{ t('large_file_previews', 'Format') }}</th>
							<th>{{ t('large_file_previews', 'As rendered') }}</th>
							<th>{{ t('large_file_previews', 'Largest') }}</th>
							<th>{{ t('large_file_previews', '64, 256, 1024 px') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="outcome in OUTCOMES" :key="outcome.format + outcome.stream" :data-outcome="outcome.format">
							<td>{{ label(outcome.format) }}</td>
							<td>{{ outcome.stream }}</td>
							<td :colspan="outcome.smaller ? 1 : 2">
								{{ outcome.largest }}
							</td>
							<td v-if="outcome.smaller">
								{{ outcome.smaller }}
							</td>
						</tr>
					</tbody>
				</table>
				<p class="lfp-outcomes__note">
					{{ t('large_file_previews', 'A render larger than Nextcloud\'s preview size is stored as with the switch off. A PDF as WebP without libvips is a JPEG.') }}
				</p>
			</HelpPopover>
		</h3>

		<h4 class="lfp-named">
			{{ t('large_file_previews', 'Format') }}
			<HelpPopover :text="t('large_file_previews', 'As Nextcloud\'s preview_format: WebP where that system setting says webp, JPEG otherwise. A PDF previewed as WebP is converted by libvips, and is a JPEG where libvips is not there.')"
				:label="t('large_file_previews', 'Format')" />
		</h4>
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
			<p class="lfp-hint" data-hint="webp">
				{{ t('large_file_previews', 'Nextcloud makes every smaller size from the largest, in its type: where a client cannot show WebP, choose JPEG.') }}
			</p>
		</div>

		<h4 class="lfp-named">
			{{ t('large_file_previews', 'Background of a JPEG') }}
			<HelpPopover :text="t('large_file_previews', 'A JPEG keeps no transparency: a transparent image is laid on this colour.')"
				:label="t('large_file_previews', 'Background of a JPEG')" />
		</h4>
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

		<h4>{{ t('large_file_previews', 'Quality') }}</h4>
		<div class="lfp-stream" data-setting="preview_stream">
			<div class="lfp-named">
				<NcCheckboxRadioSwitch type="switch"
					:model-value="state.scalars.preview_stream.value"
					:disabled="saving"
					@update:model-value="emit('save', scalarChange('preview_stream', $event as boolean))">
					{{ t('large_file_previews', 'Store the largest preview as rendered') }}
				</NcCheckboxRadioSwitch>
				<HelpPopover :text="t('large_file_previews', 'Handed to Nextcloud as a stream, the largest preview is stored as it was rendered, at the quality set here. Off, Nextcloud decodes it and encodes it again at its own. A render larger than Nextcloud\'s preview size is decoded either way, to be scaled.')"
					:label="t('large_file_previews', 'Quality')" />
			</div>
			<OccCommand :command="scalarCommand('preview_stream', state.scalars.preview_stream.value)" />
			<div class="lfp-fields" data-setting="preview_quality">
				<div>
					<NumberField id="lfp-preview-quality"
						:label="t('large_file_previews', 'Quality, 10 to 100')"
						:value="storedNumber(state.scalars.preview_quality)"
						:placeholder="defaultPlaceholder(state.scalars.preview_quality)"
						:min="state.scalars.preview_quality.min"
						:max="state.scalars.preview_quality.max"
						clearable
						label-outside
						:help="qualityHelp"
						:disabled="saving"
						@save="emit('save', scalarChange('preview_quality', $event))" />
					<OccCommand :command="storedScalarCommand('preview_quality', state.scalars.preview_quality)" />
				</div>
			</div>
		</div>

		<h4 class="lfp-named">
			{{ t('large_file_previews', 'Nextcloud\'s own settings') }}
			<HelpPopover :text="t('large_file_previews', 'The format is set in config.php, and read by Imaginary only: webp makes WebP, anything else JPEG. The quality, which Nextcloud has no page for, is set here or with occ.')"
				:label="t('large_file_previews', 'Nextcloud\'s own settings')" />
		</h4>
		<NcNoteCard type="warning" class="lfp-nextcloud-own">
			{{ t('large_file_previews', 'Nextcloud\'s own preview settings, which apply to every preview, not only this app\'s.') }}
		</NcNoteCard>
		<div class="lfp-fields" data-setting="nextcloud">
			<div data-nextcloud="preview_format">
				{{ t('large_file_previews', 'Preview format: {format} (jpeg or webp, in config.php)', { format: state.nextcloud.preview_format }) }}
				<OccCommand :command="systemCommand('preview_format', state.nextcloud.preview_format)" />
			</div>
			<div v-for="quality in QUALITIES" :key="quality.key" :data-nextcloud="quality.key">
				<NumberField :id="'lfp-' + quality.key"
					:label="quality.label"
					:value="state.nextcloud.quality_stored[quality.key] ? state.nextcloud[quality.key] : null"
					:placeholder="t('large_file_previews', '{value} (default)', { value: String(QUALITY_DEFAULT) })"
					:min="10"
					:max="100"
					clearable
					label-outside
					:help="t('large_file_previews', 'Nextcloud\'s, for every preview it stores from now on, not only this app\'s: of this app\'s, the smaller sizes, and the largest too where it is not stored as rendered. Previews stored already keep theirs.')"
					:disabled="saving"
					@save="emit('save', { nextcloud: { [quality.key]: $event } })" />
				<OccCommand :command="appConfigCommand('preview', quality.key, state.nextcloud.quality_stored[quality.key] ? state.nextcloud[quality.key] : null)" />
			</div>
		</div>
	</div>
</template>

<style scoped>
/* In the Previews help, which NcPopover puts in the body, outside the
   page's own styles. */
/* As wide as the popover lets it be, its cells wrapped to fit: Nextcloud's
   own styles keep a table's text on one line. */
.lfp-outcomes {
	width: 100%;
	border-collapse: collapse;
	white-space: normal;
}

.lfp-outcomes th,
.lfp-outcomes td {
	padding: 4px 6px;
	border-bottom: 1px solid var(--color-border);
	text-align: start;
	vertical-align: top;
}

/* In line with the help's text above it. */
.lfp-outcomes th:first-child,
.lfp-outcomes td:first-child {
	padding-inline-start: 0;
}

.lfp-outcomes th {
	font-weight: bold;
}

.lfp-outcomes__note {
	margin: 8px 0 0;
	color: var(--color-text-maxcontrast);
}
</style>
