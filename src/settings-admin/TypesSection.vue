<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Which files the app processes: what is active, then the defaults, scanned
 * pages, each MIME type and each extension.
 *
 * Each table shows only what is set at its own level; an empty size field
 * is not set there. What applies in the end is the "Active rendering"
 * table at the top. A switch saves on click, a size on its own button. A
 * row whose type has no usable renderer is shown, and cannot be changed.
 */
import { ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ActiveTable from './ActiveTable.vue'
import NumberField from './NumberField.vue'
import OccCommand from './OccCommand.vue'
import { ruleCommand, scalarCommand } from './occ'
import { t } from '../l10n'
import type { Changes, Renderer, Rule, RuleChange, SettingsState } from './types'

defineProps<{
	state: SettingsState
	saving: boolean
}>()

const emit = defineEmits<{
	save: [changes: Changes]
}>()

const newExtension = ref('')

function noRenderer(renderer: Renderer): string {
	if (renderer.program === null) {
		return t('large_file_previews', 'Not a type this app handles')
	}
	if (renderer.unsupported?.problem === 'untrusted') {
		return t('large_file_previews', 'Its decoder, {decoder}, is untrusted, and untrusted decoders are blocked', { decoder: renderer.unsupported.decoder })
	}
	if (renderer.unsupported?.problem === 'unblockable') {
		return t('large_file_previews', 'libvips here is older than 8.13 and cannot block untrusted decoders, so no image is rendered with it')
	}
	if (renderer.unsupported?.problem === 'missing') {
		return t('large_file_previews', 'libvips here has no decoder for it ({decoder})', { decoder: renderer.unsupported.decoder })
	}
	return t('large_file_previews', 'No suitable renderer ({program})', { program: renderer.program })
}

function differs(rule: Rule, shipped: Rule): boolean {
	return JSON.stringify(rule) !== JSON.stringify(shipped)
}

function saveMime(mime: string, change: RuleChange | null): void {
	emit('save', { mimetypes: { [mime]: change } })
}

function saveExtension(extension: string, change: RuleChange | null): void {
	emit('save', { extensions: { [extension]: change } })
}

function addExtension(): void {
	const extension = newExtension.value.trim().replace(/^\.+/, '').toLowerCase()
	if (extension !== '') {
		saveExtension(extension, { enabled: true })
		newExtension.value = ''
	}
}
</script>

<template>
	<div class="lfp-types">
		<h3>{{ t('large_file_previews', 'Which files') }}</h3>
		<p class="lfp-hint">
			{{ t('large_file_previews', 'Sizes are in MB. A file is processed where its type is enabled and its size lies within the minimum and the maximum, both included. Anything this app does not process goes to Nextcloud\'s other preview providers.') }}
		</p>

		<h4>{{ t('large_file_previews', 'Active rendering') }}</h4>
		<ActiveTable :rows="state.active" />

		<h4>{{ t('large_file_previews', 'Defaults') }}</h4>
		<div class="lfp-row">
			<div>
				<NumberField id="lfp-min-size"
					:label="t('large_file_previews', 'Minimum size')"
					:value="state.scalars.min_size_mb.value as number"
					:min="0"
					:disabled="saving"
					@save="emit('save', { scalars: { min_size_mb: $event } })" />
				<OccCommand :command="scalarCommand('min_size_mb', state.scalars.min_size_mb.value)" />
			</div>
			<div>
				<NumberField id="lfp-max-size"
					:label="t('large_file_previews', 'Maximum size (-1: no limit)')"
					:value="state.scalars.max_size_mb.value as number"
					:min="-1"
					:disabled="saving"
					@save="emit('save', { scalars: { max_size_mb: $event } })" />
				<OccCommand :command="scalarCommand('max_size_mb', state.scalars.max_size_mb.value)" />
			</div>
		</div>

		<h4>{{ t('large_file_previews', 'PDF scanned pages') }}</h4>
		<div class="lfp-scan" data-setting="scan">
			<NcCheckboxRadioSwitch type="switch"
				:model-value="state.scalars.scan_native_enabled.value as boolean"
				:disabled="saving"
				@update:model-value="emit('save', { scalars: { scan_native_enabled: $event as boolean } })">
				{{ t('large_file_previews', 'Render a scanned page at its own resolution, not enlarged') }}
			</NcCheckboxRadioSwitch>
			<p class="lfp-hint">
				{{ t('large_file_previews', 'A page counts as scanned where one image covers it, each way, to at least the share set here. A 300 dpi A4 scan then gives a preview 3508 pixels tall rather than the full preview size.') }}
			</p>
			<OccCommand :command="scalarCommand('scan_native_enabled', state.scalars.scan_native_enabled.value)" />
			<NumberField id="lfp-scan-coverage"
				:label="t('large_file_previews', 'Coverage, percent')"
				:value="state.scalars.scan_coverage_percent.value as number"
				:min="state.scalars.scan_coverage_percent.min"
				:max="state.scalars.scan_coverage_percent.max"
				:disabled="saving || !state.scalars.scan_native_enabled.value"
				@save="emit('save', { scalars: { scan_coverage_percent: $event } })" />
			<OccCommand :command="scalarCommand('scan_coverage_percent', state.scalars.scan_coverage_percent.value)" />
		</div>

		<h4>{{ t('large_file_previews', 'MIME types') }}</h4>
		<p class="lfp-hint">
			{{ t('large_file_previews', 'A MIME type overrides the defaults, field by field: a minimum or maximum left empty follows the default.') }}
		</p>
		<table class="lfp-table">
			<thead>
				<tr>
					<th>{{ t('large_file_previews', 'MIME type') }}</th>
					<th>{{ t('large_file_previews', 'Processing') }}</th>
					<th>{{ t('large_file_previews', 'Minimum') }}</th>
					<th>{{ t('large_file_previews', 'Maximum') }}</th>
					<th />
				</tr>
			</thead>
			<tbody>
				<tr v-for="row in state.mimetypes"
					:key="row.mime"
					:data-mime="row.mime"
					:class="{ 'lfp-unavailable': !row.renderer.available }">
					<td>
						{{ row.mime }}
						<span v-if="!row.renderer.available" class="lfp-note lfp-note--error">{{ noRenderer(row.renderer) }}</span>
						<OccCommand :command="ruleCommand('mime', row.mime, row.rule)" />
					</td>
					<td>
						<NcCheckboxRadioSwitch type="switch"
							:model-value="row.rule.enabled === true"
							:disabled="saving || !row.renderer.available"
							@update:model-value="saveMime(row.mime, { enabled: $event as boolean })">
							{{ row.rule.enabled === true ? t('large_file_previews', 'enabled') : t('large_file_previews', 'disabled') }}
						</NcCheckboxRadioSwitch>
					</td>
					<td>
						<NumberField :id="'lfp-min-' + row.mime"
							:label="t('large_file_previews', 'Minimum')"
							:value="row.rule.min_size_mb ?? null"
							:min="0"
							clearable
							narrow
							:disabled="saving || !row.renderer.available"
							@save="saveMime(row.mime, { min_size_mb: $event })" />
					</td>
					<td>
						<NumberField :id="'lfp-max-' + row.mime"
							:label="t('large_file_previews', 'Maximum')"
							:value="row.rule.max_size_mb ?? null"
							:min="-1"
							clearable
							narrow
							:disabled="saving || !row.renderer.available"
							@save="saveMime(row.mime, { max_size_mb: $event })" />
					</td>
					<td>
						<NcButton v-if="differs(row.rule, row.default)"
							class="lfp-reset"
							variant="tertiary"
							:disabled="saving || !row.renderer.available"
							@click="saveMime(row.mime, null)">
							{{ t('large_file_previews', 'Reset') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>

		<h4>{{ t('large_file_previews', 'Extensions') }}</h4>
		<p class="lfp-hint">
			{{ t('large_file_previews', 'An extension overrides its MIME type, field by field. Processing "enabled" selects its files even where the MIME type is disabled; "excluded" never selects them. A minimum or maximum set here replaces the MIME type\'s; one left empty follows it.') }}
		</p>
		<table v-if="state.extensions.length > 0" class="lfp-table">
			<thead>
				<tr>
					<th>{{ t('large_file_previews', 'Extension') }}</th>
					<th>{{ t('large_file_previews', 'Processing') }}</th>
					<th>{{ t('large_file_previews', 'Minimum') }}</th>
					<th>{{ t('large_file_previews', 'Maximum') }}</th>
					<th />
				</tr>
			</thead>
			<tbody>
				<tr v-for="row in state.extensions"
					:key="row.extension"
					:data-extension="row.extension"
					:class="{ 'lfp-unavailable': !row.renderer.available }">
					<td>
						.{{ row.extension }}
						<span class="lfp-hint">{{ row.mime ?? '' }}</span>
						<span v-if="!row.renderer.available" class="lfp-note lfp-note--error">{{ noRenderer(row.renderer) }}</span>
						<span v-else-if="row.noEffect" class="lfp-note lfp-note--warning lfp-no-effect">{{ t('large_file_previews', 'No effect: its MIME type already selects it') }}</span>
						<OccCommand :command="ruleCommand('ext', row.extension, row.rule)" />
					</td>
					<td>
						<NcCheckboxRadioSwitch type="switch"
							:model-value="row.rule.enabled === true"
							:disabled="saving || !row.renderer.available"
							@update:model-value="saveExtension(row.extension, { enabled: $event as boolean })">
							<template v-if="row.rule.enabled === undefined">
								{{ t('large_file_previews', 'as MIME type') }}
							</template>
							<template v-else>
								{{ row.rule.enabled ? t('large_file_previews', 'enabled') : t('large_file_previews', 'excluded') }}
							</template>
						</NcCheckboxRadioSwitch>
					</td>
					<td>
						<NumberField :id="'lfp-min-ext-' + row.extension"
							:label="t('large_file_previews', 'Minimum')"
							:value="row.rule.min_size_mb ?? null"
							:min="0"
							clearable
							narrow
							:disabled="saving || !row.renderer.available"
							@save="saveExtension(row.extension, { min_size_mb: $event })" />
					</td>
					<td>
						<NumberField :id="'lfp-max-ext-' + row.extension"
							:label="t('large_file_previews', 'Maximum')"
							:value="row.rule.max_size_mb ?? null"
							:min="-1"
							clearable
							narrow
							:disabled="saving || !row.renderer.available"
							@save="saveExtension(row.extension, { max_size_mb: $event })" />
					</td>
					<td>
						<NcButton class="lfp-remove"
							variant="tertiary"
							:disabled="saving"
							@click="saveExtension(row.extension, null)">
							{{ t('large_file_previews', 'Remove') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>
		<form class="lfp-row" @submit.prevent="addExtension">
			<NcTextField id="lfp-new-extension"
				v-model="newExtension"
				class="lfp-new-extension"
				:label="t('large_file_previews', 'Extension, e.g. tif')"
				:disabled="saving" />
			<NcButton type="submit" :disabled="saving || newExtension.trim() === ''">
				{{ t('large_file_previews', 'Add') }}
			</NcButton>
		</form>
	</div>
</template>
