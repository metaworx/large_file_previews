<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Which files the app takes: the app's size window, then each MIME type,
 * then each extension, the most specific level that sets a field winning.
 *
 * A size field left empty is not set at that level; its placeholder shows
 * what applies instead. A switch saves on click, a size on its own button.
 */
import { ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NumberField from './NumberField.vue'
import { t } from '../l10n'
import type { Changes, Resolved, Rule, RuleChange, SettingsState } from './types'

defineProps<{
	state: SettingsState
	saving: boolean
}>()

const emit = defineEmits<{
	save: [changes: Changes]
}>()

const newExtension = ref('')

function sizeText(value: number): string {
	return value === -1 ? t('large_file_previews', 'no limit') : String(value)
}

function sourceText(source: string): string {
	switch (source) {
	case 'ext':
		return t('large_file_previews', 'from the extension')
	case 'mime':
		return t('large_file_previews', 'from the MIME type')
	default:
		return t('large_file_previews', 'from the default')
	}
}

/** What applies where the field is empty: the resolution, and its level. */
function placeholder(resolved: Resolved, field: 'min_size_mb' | 'max_size_mb'): string {
	return `${sizeText(resolved[field].value)} (${sourceText(resolved[field].source)})`
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
	<NcSettingsSection :name="t('large_file_previews', 'Which files')"
		:description="t('large_file_previews', 'Sizes are in MB. A file is taken where it is switched on and its size lies within the minimum and the maximum, both included. An extension overrides its MIME type, field by field, and both override the defaults.')">
		<h3>{{ t('large_file_previews', 'Defaults') }}</h3>
		<div class="lfp-row">
			<NumberField id="lfp-min-size"
				:label="t('large_file_previews', 'Minimum size')"
				:value="state.scalars.min_size_mb.value as number"
				:min="0"
				:disabled="saving"
				@save="emit('save', { scalars: { min_size_mb: $event } })" />
			<NumberField id="lfp-max-size"
				:label="t('large_file_previews', 'Maximum size (-1: no limit)')"
				:value="state.scalars.max_size_mb.value as number"
				:min="-1"
				:disabled="saving"
				@save="emit('save', { scalars: { max_size_mb: $event } })" />
		</div>

		<h3>{{ t('large_file_previews', 'Scanned pages') }}</h3>
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
			<NumberField id="lfp-scan-coverage"
				:label="t('large_file_previews', 'Coverage, percent')"
				:value="state.scalars.scan_coverage_percent.value as number"
				:min="state.scalars.scan_coverage_percent.min"
				:max="state.scalars.scan_coverage_percent.max"
				:disabled="saving || !state.scalars.scan_native_enabled.value"
				@save="emit('save', { scalars: { scan_coverage_percent: $event } })" />
		</div>

		<h3>{{ t('large_file_previews', 'MIME types') }}</h3>
		<table class="lfp-table">
			<thead>
				<tr>
					<th>{{ t('large_file_previews', 'MIME type') }}</th>
					<th>{{ t('large_file_previews', 'Taken') }}</th>
					<th>{{ t('large_file_previews', 'Minimum') }}</th>
					<th>{{ t('large_file_previews', 'Maximum') }}</th>
					<th />
				</tr>
			</thead>
			<tbody>
				<tr v-for="row in state.mimetypes" :key="row.mime" :data-mime="row.mime">
					<td>
						{{ row.mime }}
						<span v-if="!row.renderer" class="lfp-hint">{{ t('large_file_previews', 'not rendered yet') }}</span>
					</td>
					<td>
						<NcCheckboxRadioSwitch type="switch"
							:model-value="row.resolved.enabled.value"
							:disabled="saving"
							@update:model-value="saveMime(row.mime, { enabled: $event as boolean })">
							{{ t('large_file_previews', 'Taken') }}
						</NcCheckboxRadioSwitch>
					</td>
					<td>
						<NumberField :id="'lfp-min-' + row.mime"
							:label="t('large_file_previews', 'Minimum')"
							:value="row.rule.min_size_mb ?? null"
							:placeholder="placeholder(row.resolved, 'min_size_mb')"
							:min="0"
							clearable
							:disabled="saving"
							@save="saveMime(row.mime, { min_size_mb: $event })" />
					</td>
					<td>
						<NumberField :id="'lfp-max-' + row.mime"
							:label="t('large_file_previews', 'Maximum')"
							:value="row.rule.max_size_mb ?? null"
							:placeholder="placeholder(row.resolved, 'max_size_mb')"
							:min="-1"
							clearable
							:disabled="saving"
							@save="saveMime(row.mime, { max_size_mb: $event })" />
					</td>
					<td>
						<NcButton v-if="differs(row.rule, row.default)"
							class="lfp-reset"
							variant="tertiary"
							:disabled="saving"
							@click="saveMime(row.mime, null)">
							{{ t('large_file_previews', 'Reset') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>

		<h3>{{ t('large_file_previews', 'Extensions') }}</h3>
		<p class="lfp-hint">
			{{ t('large_file_previews', 'For a finer choice than the MIME type: "TIFF, but not .tif", or ".tif only above 200 MB".') }}
		</p>
		<table v-if="state.extensions.length > 0" class="lfp-table">
			<thead>
				<tr>
					<th>{{ t('large_file_previews', 'Extension') }}</th>
					<th>{{ t('large_file_previews', 'Taken') }}</th>
					<th>{{ t('large_file_previews', 'Minimum') }}</th>
					<th>{{ t('large_file_previews', 'Maximum') }}</th>
					<th />
				</tr>
			</thead>
			<tbody>
				<tr v-for="row in state.extensions" :key="row.extension" :data-extension="row.extension">
					<td>
						.{{ row.extension }}
						<span class="lfp-hint">{{ row.resolved.mime ?? '' }}</span>
					</td>
					<td>
						<NcCheckboxRadioSwitch type="switch"
							:model-value="row.resolved.enabled.value"
							:disabled="saving"
							@update:model-value="saveExtension(row.extension, { enabled: $event as boolean })">
							{{ sourceText(row.resolved.enabled.source) }}
						</NcCheckboxRadioSwitch>
					</td>
					<td>
						<NumberField :id="'lfp-min-ext-' + row.extension"
							:label="t('large_file_previews', 'Minimum')"
							:value="row.rule.min_size_mb ?? null"
							:placeholder="placeholder(row.resolved, 'min_size_mb')"
							:min="0"
							clearable
							:disabled="saving"
							@save="saveExtension(row.extension, { min_size_mb: $event })" />
					</td>
					<td>
						<NumberField :id="'lfp-max-ext-' + row.extension"
							:label="t('large_file_previews', 'Maximum')"
							:value="row.rule.max_size_mb ?? null"
							:placeholder="placeholder(row.resolved, 'max_size_mb')"
							:min="-1"
							clearable
							:disabled="saving"
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
				:label="t('large_file_previews', 'Extension, e.g. tif')"
				:disabled="saving" />
			<NcButton type="submit" :disabled="saving || newExtension.trim() === ''">
				{{ t('large_file_previews', 'Add') }}
			</NcButton>
		</form>
	</NcSettingsSection>
</template>
