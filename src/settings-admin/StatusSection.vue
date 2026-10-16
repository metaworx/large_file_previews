<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * The app's version, and whether previews can be rendered: what the server
 * has, the last render that failed, and what is set but not used. Read only;
 * a program's path is set in config.php, and the page says how.
 */
import { computed } from 'vue'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import InstallHint from './InstallHint.vue'
import { t } from '../l10n'
import type { PrerequisiteItem, SettingsState } from './types'

const props = defineProps<{
	state: SettingsState
}>()

/** The table's column headers, each also the label of its cells where a row stacks. */
const COLUMNS = {
	needed: t('large_file_previews', 'Needed'),
	status: t('large_file_previews', 'Status'),
	for: t('large_file_previews', 'For'),
	detail: t('large_file_previews', 'Detail'),
}

/** Whether a prerequisite shows how to install it: where it is not available. */
function hinted(item: PrerequisiteItem): boolean {
	return item.status !== 'ok' && item.install !== null
}

/** Whether anything is processed at all: a MIME type or an extension switched on. */
const processing = computed(() => props.state.active.some((row) => row.processing === 'enabled'))

const failureTime = computed(() => props.state.lastFailure === null
	? ''
	: new Date(props.state.lastFailure.time * 1000).toLocaleString())

const platformText = computed(() => {
	switch (props.state.platform) {
	case 'debian':
		return t('large_file_previews', 'The packages named are Debian\'s and Ubuntu\'s.')
	case 'fedora':
		return t('large_file_previews', 'The packages named are Fedora\'s.')
	case 'rhel':
		return t('large_file_previews', 'The packages named are those of RHEL and its rebuilds.')
	case 'alpine':
		return t('large_file_previews', 'The packages named are Alpine\'s.')
	case 'arch':
		return t('large_file_previews', 'The packages named are Arch\'s.')
	default:
		return t('large_file_previews', 'This server\'s distribution is not one the app knows packages for; the README names each program\'s package.')
	}
})

function statusText(status: PrerequisiteItem['status']): string {
	switch (status) {
	case 'ok':
		return t('large_file_previews', 'available')
	case 'optional':
		return t('large_file_previews', 'optional')
	case 'disabled':
		return t('large_file_previews', 'disabled')
	default:
		return t('large_file_previews', 'missing')
	}
}
</script>

<template>
	<div class="lfp-status">
		<h3>{{ t('large_file_previews', 'Status') }}</h3>
		<p class="lfp-hint lfp-version">
			{{ t('large_file_previews', 'Large File Previews, v{version}', { version: state.version }) }}
		</p>
		<NcNoteCard v-if="state.ready" type="success">
			{{ t('large_file_previews', 'Everything the app needs is available.') }}
		</NcNoteCard>
		<NcNoteCard v-else type="error">
			{{ t('large_file_previews', 'Some large previews are not rendered: something the app needs is missing. Install it, or switch off what needs it; see the list below, or run occ large-file-previews:check.') }}
		</NcNoteCard>

		<!-- Every type ships off: installed, the app takes nothing until told. -->
		<NcNoteCard v-if="!processing" type="warning" class="lfp-nothing-processed">
			{{ t('large_file_previews', 'Nothing is processed: every MIME type is disabled.') }}
		</NcNoteCard>

		<NcNoteCard v-if="state.lastFailure !== null" type="warning">
			{{ t('large_file_previews', 'The last render that failed, at {time}, was a file of type {mime}: {reason}.', { time: failureTime, mime: state.lastFailure.mime, reason: state.lastFailure.reason }) }}
		</NcNoteCard>

		<NcNoteCard v-for="problem in state.problems" :key="problem" type="warning">
			{{ t('large_file_previews', 'Set but not used: {problem}', { problem }) }}
		</NcNoteCard>

		<div class="lfp-table-wrap">
			<table class="lfp-table">
				<thead>
					<tr>
						<th>{{ COLUMNS.needed }}</th>
						<th>{{ COLUMNS.status }}</th>
						<th>{{ COLUMNS.for }}</th>
						<th>{{ COLUMNS.detail }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="item in state.prerequisites" :key="item.group + item.name" :class="'lfp-status--' + item.status">
						<td>{{ item.name }}</td>
						<td :data-label="COLUMNS.status">
							{{ statusText(item.status) }}
						</td>
						<td :data-label="COLUMNS.for">
							{{ item.needed_for }}
						</td>
						<td :data-label="COLUMNS.detail" :class="{ 'lfp-cell--empty': item.detail === '' && !hinted(item) }">
							{{ item.detail }}
							<InstallHint v-if="hinted(item) && item.install !== null" :install="item.install" />
						</td>
					</tr>
				</tbody>
			</table>
		</div>
		<p class="lfp-hint lfp-platform">
			{{ platformText }}
		</p>
		<p class="lfp-hint">
			{{ t('large_file_previews', 'A program\'s path is found by Nextcloud, or set in system config only, never here; Advanced, below, shows each path and the command that sets it.') }}
		</p>
	</div>
</template>

<style scoped>
.lfp-status--missing td:nth-child(2),
.lfp-status--disabled td:nth-child(2) {
	color: var(--color-error-text);
	font-weight: bold;
}
</style>
