<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Backup and restore: what is stored, downloaded as the file `occ
 * lfp:config:export` writes; and such a file, uploaded, read by the server,
 * its changes shown before they are applied, all of them or none.
 *
 * The page writes the app's settings and rules only. The values a file
 * holds for system config - programs' paths, the untrusted decoders, the
 * scratch directory - are shown as the occ commands that set them.
 */
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import HelpPopover from './HelpPopover.vue'
import OccCommand from './OccCommand.vue'
import { systemValueCommand } from './occ'
import { useSettings } from './useSettings'
import { t } from '../l10n'
import { toastSaved } from '../toast'
import type { ImportAnswer, TransferChange, TransferValue, TransferWarning } from './types'

defineProps<{
	saving: boolean
}>()

const { exportConfig, importConfig } = useSettings()

const input = ref<HTMLInputElement | null>(null)
const fileName = ref<string | null>(null)
/** The file's text, as it is sent to the server. */
const text = ref<string | null>(null)
const replace = ref(false)
const read = ref<ImportAnswer | null>(null)
const refusal = ref<string | null>(null)

/** The table's column headers, each also the label of its cells where a row stacks. */
const COLUMNS = {
	name: t('large_file_previews', 'Name'),
	before: t('large_file_previews', 'Now'),
	after: t('large_file_previews', 'From the file'),
}

const appChanges = computed(() => read.value?.changes.filter((change) => change.part !== 'system') ?? [])
const systemChanges = computed(() => read.value?.changes.filter((change) => change.part === 'system') ?? [])

/** `large_file_previews-config.<host>.<date>.json` */
function downloadName(): string {
	return 'large_file_previews-config.' + window.location.hostname + '.' + new Date().toISOString().slice(0, 10) + '.json'
}

async function download(): Promise<void> {
	const exported = await exportConfig()
	if (exported === null) {
		return
	}
	const url = URL.createObjectURL(new Blob([JSON.stringify(exported, null, 4) + '\n'], { type: 'application/json' }))
	const link = document.createElement('a')
	link.href = url
	link.download = downloadName()
	link.click()
	URL.revokeObjectURL(url)
}

/** The file chosen, read by the server: nothing is written yet. */
async function chosen(event: Event): Promise<void> {
	const file = (event.target as HTMLInputElement).files?.[0]
	if (file === undefined) {
		return
	}
	fileName.value = file.name
	text.value = await file.text()
	await check()
	if (input.value !== null) {
		input.value.value = ''
	}
}

async function check(): Promise<void> {
	if (text.value === null) {
		return
	}
	const answer = await importConfig(text.value, replace.value, false)
	read.value = 'error' in answer ? null : answer
	refusal.value = 'error' in answer ? answer.error : null
}

async function apply(): Promise<void> {
	if (text.value === null) {
		return
	}
	const answer = await importConfig(text.value, replace.value, true)
	if ('error' in answer) {
		refusal.value = answer.error
		return
	}
	toastSaved(t('large_file_previews', 'Configuration imported.'))
	read.value = null
	text.value = null
	fileName.value = null
}

function cancel(): void {
	read.value = null
	refusal.value = null
	text.value = null
	fileName.value = null
}

async function replaceChanged(value: boolean): Promise<void> {
	replace.value = value
	await check()
}

function warningText(warning: TransferWarning): string {
	const name = { name: warning.name }
	switch (warning.kind) {
	case 'part':
		return t('large_file_previews', 'Part {name}: not one this app reads; left out.', name)
	case 'setting':
		return t('large_file_previews', 'Setting {name}: not one this app has; left out.', name)
	case 'type':
		return t('large_file_previews', 'MIME type {name}: not one this app handles; left out.', name)
	case 'field':
		return t('large_file_previews', 'Field {name}: not one a rule has; left out.', name)
	case 'extension':
		return t('large_file_previews', 'Extension {name}: of a type this app does not handle; left out.', name)
	case 'program':
		return t('large_file_previews', 'Program {name}: not one this app runs; left out.', name)
	default:
		return t('large_file_previews', 'System value {name}: not one this app has; left out.', name)
	}
}

/** What a side of a change holds; nothing stored, as what then applies. */
function valueText(change: TransferChange, value: TransferValue): string {
	if (value === null) {
		switch (change.part) {
		case 'settings':
			return t('large_file_previews', 'default')
		case 'mimetypes':
			return t('large_file_previews', 'as shipped')
		case 'extensions':
			return t('large_file_previews', 'no rule')
		default:
			return t('large_file_previews', 'not set')
		}
	}
	if (typeof value === 'object') {
		return Object.entries(value).map(([field, fieldValue]) => field + ': ' + String(fieldValue)).join(', ')
	}
	return String(value)
}

function partText(part: TransferChange['part']): string {
	switch (part) {
	case 'settings':
		return t('large_file_previews', 'Setting')
	case 'mimetypes':
		return t('large_file_previews', 'MIME type')
	default:
		return t('large_file_previews', 'Extension')
	}
}

function systemCommand(change: TransferChange): string {
	const after = change.after
	return systemValueCommand(change.name, typeof after === 'string' || typeof after === 'boolean' ? after : null)
}

const writtenBy = computed(() => {
	const exported = read.value?.exported
	return t('large_file_previews', 'Written by version {version} on {date}.', {
		version: exported?.version ?? '?',
		date: exported?.date ?? '?',
	})
})
</script>

<template>
	<div class="lfp-backup">
		<h3 class="lfp-named">
			{{ t('large_file_previews', 'Backup and restore') }}
			<HelpPopover :text="t('large_file_previews', 'The configuration stored, as a file, and such a file read back: before anything is written, it shows what would change and what it leaves out. All of it is written, or nothing. The values a file holds for config.php are set with occ, with the commands shown; occ lfp:config:import sets them too.')"
				:label="t('large_file_previews', 'Backup and restore')" />
		</h3>

		<div class="lfp-row">
			<NcButton class="lfp-backup__download" :disabled="saving" @click="download">
				{{ t('large_file_previews', 'Download configuration') }}
			</NcButton>
			<NcButton class="lfp-backup__choose" :disabled="saving" @click="input?.click()">
				{{ t('large_file_previews', 'Restore from a file…') }}
			</NcButton>
			<input ref="input"
				class="lfp-backup__file"
				type="file"
				accept=".json,application/json"
				:aria-label="t('large_file_previews', 'Restore from a file…')"
				@change="chosen">
		</div>
		<OccCommand command="occ lfp:config:export /path/to/file.json" />

		<NcNoteCard v-if="refusal !== null" type="error" class="lfp-backup__refused">
			{{ fileName }}: {{ refusal }}
		</NcNoteCard>

		<div v-if="read !== null" class="lfp-backup__read">
			<p class="lfp-hint">
				{{ fileName }} - {{ writtenBy }}
			</p>

			<NcNoteCard v-if="read.warnings.length > 0" type="warning" class="lfp-backup__warnings">
				<ul>
					<li v-for="warning in read.warnings" :key="warning.kind + warning.name">
						{{ warningText(warning) }}
					</li>
				</ul>
			</NcNoteCard>

			<NcCheckboxRadioSwitch type="switch"
				:model-value="replace"
				:disabled="saving"
				@update:model-value="replaceChanged($event as boolean)">
				{{ t('large_file_previews', 'Replace: return what the file does not name to its default') }}
			</NcCheckboxRadioSwitch>

			<div v-if="appChanges.length > 0" class="lfp-table-wrap">
				<table class="lfp-table lfp-backup__changes">
					<thead>
						<tr>
							<th>{{ COLUMNS.name }}</th>
							<th>{{ COLUMNS.before }}</th>
							<th>{{ COLUMNS.after }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="change in appChanges" :key="change.part + change.name">
							<td>{{ partText(change.part) }} {{ change.name }}</td>
							<td :data-label="COLUMNS.before">
								{{ valueText(change, change.before) }}
							</td>
							<td :data-label="COLUMNS.after">
								{{ valueText(change, change.after) }}
							</td>
						</tr>
					</tbody>
				</table>
			</div>
			<p v-else class="lfp-hint lfp-backup__nothing">
				{{ t('large_file_previews', 'The file changes none of the app\'s settings.') }}
			</p>

			<template v-if="systemChanges.length > 0">
				<p class="lfp-backup__system">
					{{ t('large_file_previews', 'Set in config.php, which this page does not write: run these with occ.') }}
				</p>
				<OccCommand v-for="change in systemChanges" :key="change.name" :command="systemCommand(change)" />
			</template>

			<div class="lfp-row">
				<NcButton class="lfp-backup__apply"
					variant="primary"
					:disabled="saving || appChanges.length === 0"
					@click="apply">
					{{ t('large_file_previews', 'Apply') }}
				</NcButton>
				<NcButton variant="tertiary" :disabled="saving" @click="cancel">
					{{ t('large_file_previews', 'Cancel') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<style scoped>
/* Opened by its button, which says what it does. */
.lfp-backup__file {
	display: none;
}

.lfp-backup__warnings ul {
	margin: 0;
	padding-inline-start: 1.2em;
	list-style: disc;
}
</style>
