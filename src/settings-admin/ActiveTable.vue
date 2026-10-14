<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * What the configuration resolves to: each MIME type processed, and each
 * listed extension, with its renderer and the minimum and maximum that
 * apply, each with the level it comes from. Computed by the server, by the
 * resolver the preview provider uses, and sent again with every save.
 */
import { rendererProblem } from './renderer'
import { t } from '../l10n'
import type { ActiveRow, Sourced } from './types'

defineProps<{
	rows: ActiveRow[]
}>()

function sizeText(value: number): string {
	return value === -1 ? t('large_file_previews', 'no limit') : String(value)
}

function sourceText(source: Sourced<number>['source']): string {
	switch (source) {
	case 'ext':
		return t('large_file_previews', 'extension')
	case 'mime':
		return t('large_file_previews', 'MIME type')
	default:
		return t('large_file_previews', 'default')
	}
}

function processingText(processing: ActiveRow['processing']): string {
	switch (processing) {
	case 'enabled':
		return t('large_file_previews', 'enabled')
	case 'excluded':
		return t('large_file_previews', 'excluded')
	default:
		return t('large_file_previews', 'disabled')
	}
}
</script>

<template>
	<table class="lfp-table lfp-active">
		<thead>
			<tr>
				<th>{{ t('large_file_previews', 'MIME type') }}</th>
				<th>{{ t('large_file_previews', 'Extension') }}</th>
				<th>{{ t('large_file_previews', 'Processing') }}</th>
				<th>{{ t('large_file_previews', 'Renderer') }}</th>
				<th>{{ t('large_file_previews', 'Minimum, MB') }}</th>
				<th>{{ t('large_file_previews', 'Maximum, MB') }}</th>
			</tr>
		</thead>
		<tbody>
			<tr v-for="row in rows"
				:key="(row.mime ?? '') + '|' + (row.extension ?? '')"
				:class="'lfp-active--' + row.processing">
				<td>{{ row.mime ?? '' }}</td>
				<td>{{ row.extension === null ? '' : '.' + row.extension }}</td>
				<td>{{ processingText(row.processing) }}</td>
				<td>
					{{ row.renderer.program ?? '' }}
					<span v-if="rendererProblem(row.renderer) !== null" class="lfp-badge">{{ rendererProblem(row.renderer)?.badge }}</span>
				</td>
				<td>{{ sizeText(row.min_size_mb.value) }} <span class="lfp-hint">({{ sourceText(row.min_size_mb.source) }})</span></td>
				<td>{{ sizeText(row.max_size_mb.value) }} <span class="lfp-hint">({{ sourceText(row.max_size_mb.source) }})</span></td>
			</tr>
			<tr v-if="rows.length === 0">
				<td colspan="6" class="lfp-hint">
					{{ t('large_file_previews', 'Nothing is processed: every MIME type is disabled.') }}
				</td>
			</tr>
		</tbody>
	</table>
</template>

<style scoped>
.lfp-active--excluded td,
.lfp-active--disabled td {
	color: var(--color-text-maxcontrast);
}
</style>
