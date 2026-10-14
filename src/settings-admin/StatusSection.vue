<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Whether previews can be rendered: what the server has, the last render
 * that failed, and what is set but not used. Read only; a program's path is
 * set in config.php, and the page says how.
 */
import { computed } from 'vue'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { t } from '../l10n'
import type { SettingsState } from './types'

const props = defineProps<{
	state: SettingsState
}>()

const failureTime = computed(() => props.state.lastFailure === null
	? ''
	: new Date(props.state.lastFailure.time * 1000).toLocaleString())

function statusText(status: string): string {
	switch (status) {
	case 'ok':
		return t('large_file_previews', 'available')
	case 'optional':
		return t('large_file_previews', 'not needed')
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
		<NcNoteCard v-if="state.ready" type="success">
			{{ t('large_file_previews', 'Everything the app needs is available.') }}
		</NcNoteCard>
		<NcNoteCard v-else type="error">
			{{ t('large_file_previews', 'Large previews are not rendered: something the app needs is missing. See the list below, or run occ large-file-previews:check.') }}
		</NcNoteCard>

		<NcNoteCard v-if="state.lastFailure !== null" type="warning">
			{{ t('large_file_previews', 'The last render that failed, at {time}, was a file of type {mime}: {reason}.', { time: failureTime, mime: state.lastFailure.mime, reason: state.lastFailure.reason }) }}
		</NcNoteCard>

		<NcNoteCard v-for="problem in state.problems" :key="problem" type="warning">
			{{ t('large_file_previews', 'Set but not used: {problem}', { problem }) }}
		</NcNoteCard>

		<table class="lfp-table">
			<thead>
				<tr>
					<th>{{ t('large_file_previews', 'Needed') }}</th>
					<th>{{ t('large_file_previews', 'Status') }}</th>
					<th>{{ t('large_file_previews', 'For') }}</th>
					<th>{{ t('large_file_previews', 'Detail') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="item in state.prerequisites" :key="item.group + item.name" :class="'lfp-status--' + item.status">
					<td>{{ item.name }}</td>
					<td>{{ statusText(item.status) }}</td>
					<td>{{ item.needed_for }}</td>
					<td>{{ item.detail }}</td>
				</tr>
			</tbody>
		</table>
		<p class="lfp-hint">
			{{ t('large_file_previews', 'A program\'s path is found by Nextcloud, or set in system config only, never here: occ lfp:config:set binaries.pdftoppm /path/to/pdftoppm') }}
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
