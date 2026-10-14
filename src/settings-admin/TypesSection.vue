<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Which files the app processes: what is processed, then the defaults,
 * scanned pages, each MIME type and each extension.
 *
 * Each table shows only what is set at its own level; an empty size field
 * is not set there. What applies in the end is the table at the top, "What
 * is processed". Each rule's row is a RuleRow.
 */
import { ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ActiveTable from './ActiveTable.vue'
import NumberField from './NumberField.vue'
import OccCommand from './OccCommand.vue'
import RuleRow from './RuleRow.vue'
import { scalarCommand } from './occ'
import { t } from '../l10n'
import { scalarChange } from './types'
import type { Changes, RuleChange, SettingsState } from './types'

defineProps<{
	state: SettingsState
	saving: boolean
}>()

const emit = defineEmits<{
	save: [changes: Changes]
}>()

const newExtension = ref('')

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

		<h4>{{ t('large_file_previews', 'What is processed') }}</h4>
		<ActiveTable :rows="state.active" />

		<h4>{{ t('large_file_previews', 'Defaults') }}</h4>
		<div class="lfp-row">
			<div>
				<NumberField id="lfp-min-size"
					:label="t('large_file_previews', 'Minimum size')"
					:value="state.scalars.min_size_mb.value"
					:min="0"
					:disabled="saving"
					@save="emit('save', scalarChange('min_size_mb', $event))" />
				<OccCommand :command="scalarCommand('min_size_mb', state.scalars.min_size_mb.value)" />
			</div>
			<div>
				<NumberField id="lfp-max-size"
					:label="t('large_file_previews', 'Maximum size (-1: no limit)')"
					:value="state.scalars.max_size_mb.value"
					:min="-1"
					:disabled="saving"
					@save="emit('save', scalarChange('max_size_mb', $event))" />
				<OccCommand :command="scalarCommand('max_size_mb', state.scalars.max_size_mb.value)" />
			</div>
		</div>

		<h4>{{ t('large_file_previews', 'PDF scanned pages') }}</h4>
		<div class="lfp-scan" data-setting="scan">
			<NcCheckboxRadioSwitch type="switch"
				:model-value="state.scalars.scan_native_enabled.value"
				:disabled="saving"
				@update:model-value="emit('save', scalarChange('scan_native_enabled', $event as boolean))">
				{{ t('large_file_previews', 'Render a scanned page at its own resolution, not enlarged') }}
			</NcCheckboxRadioSwitch>
			<p class="lfp-hint">
				{{ t('large_file_previews', 'A page counts as scanned where one image covers it, each way, to at least the share set here. A 300 dpi A4 scan then gives a preview 3508 pixels tall rather than the full preview size.') }}
			</p>
			<OccCommand :command="scalarCommand('scan_native_enabled', state.scalars.scan_native_enabled.value)" />
			<NumberField id="lfp-scan-coverage"
				:label="t('large_file_previews', 'Coverage, percent')"
				:value="state.scalars.scan_coverage_percent.value"
				:min="state.scalars.scan_coverage_percent.min"
				:max="state.scalars.scan_coverage_percent.max"
				:disabled="saving || !state.scalars.scan_native_enabled.value"
				@save="emit('save', scalarChange('scan_coverage_percent', $event))" />
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
				<RuleRow v-for="row in state.mimetypes"
					:key="row.mime"
					:data-mime="row.mime"
					kind="mime"
					:name="row.mime"
					:rule="row.rule"
					:stored="row.stored"
					:shipped="row.default"
					:renderer="row.renderer"
					:saving="saving"
					@save="saveMime(row.mime, $event)" />
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
				<RuleRow v-for="row in state.extensions"
					:key="row.extension"
					:data-extension="row.extension"
					kind="ext"
					:name="row.extension"
					:mime="row.mime"
					:rule="row.rule"
					:renderer="row.renderer"
					:no-effect="row.noEffect"
					:saving="saving"
					@save="saveExtension(row.extension, $event)" />
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
