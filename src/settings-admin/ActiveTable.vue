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
import ProviderList from './ProviderList.vue'
import { rendererProblem } from './renderer'
import { t } from '../l10n'
import type { ActiveRow, Provider, Sourced } from './types'

withDefaults(defineProps<{
	rows: ActiveRow[]
	/** Each MIME type's preview providers, in the order Nextcloud tries them. */
	providers?: Record<string, Provider[]>
}>(), {
	providers: () => ({}),
})

/** The table's column headers, each also the label of its cells where a row stacks. */
const COLUMNS = {
	mime: t('large_file_previews', 'MIME type'),
	extension: t('large_file_previews', 'Extension'),
	processing: t('large_file_previews', 'Processing'),
	renderer: t('large_file_previews', 'Renderer'),
	min: t('large_file_previews', 'Minimum, MiB'),
	max: t('large_file_previews', 'Maximum, MiB'),
	providers: t('large_file_previews', 'Providers'),
}

function sizeText(value: number): string {
	return value === -1 ? t('large_file_previews', 'no limit') : String(value)
}

function sourceText(source: Sourced<number>['source']): string {
	switch (source) {
	case 'ext':
		return t('large_file_previews', 'extension')
	case 'mime':
		return t('large_file_previews', 'MIME type')
	case 'shipped':
		return t('large_file_previews', 'as shipped')
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
	<div class="lfp-table-wrap">
		<table class="lfp-table lfp-active">
			<thead>
				<tr>
					<th>{{ COLUMNS.mime }}</th>
					<th>{{ COLUMNS.extension }}</th>
					<th>{{ COLUMNS.processing }}</th>
					<th>{{ COLUMNS.renderer }}</th>
					<th>{{ COLUMNS.min }}</th>
					<th>{{ COLUMNS.max }}</th>
					<th>{{ COLUMNS.providers }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="row in rows"
					:key="(row.mime ?? '') + '|' + (row.extension ?? '')"
					:class="'lfp-active--' + row.processing">
					<td>{{ row.mime ?? '' }}</td>
					<td :data-label="COLUMNS.extension" :class="{ 'lfp-cell--empty': row.extension === null }">
						{{ row.extension === null ? '' : '.' + row.extension }}
					</td>
					<td :data-label="COLUMNS.processing">
						{{ processingText(row.processing) }}
					</td>
					<td :data-label="COLUMNS.renderer">
						{{ row.renderer.program ?? '' }}
						<span v-if="rendererProblem(row.renderer) !== null" class="lfp-badge">{{ rendererProblem(row.renderer)?.badge }}</span>
					</td>
					<td :data-label="COLUMNS.min">
						<!-- On one line: the space between them is where they break. -->
						<span class="lfp-unbroken">{{ sizeText(row.min_size_mb.value) }}</span> <span class="lfp-hint lfp-unbroken">({{ sourceText(row.min_size_mb.source) }})</span>
					</td>
					<td :data-label="COLUMNS.max">
						<span class="lfp-unbroken">{{ sizeText(row.max_size_mb.value) }}</span> <span class="lfp-hint lfp-unbroken">({{ sourceText(row.max_size_mb.source) }})</span>
					</td>
					<td :data-label="COLUMNS.providers">
						<ProviderList :providers="providers[row.mime ?? ''] ?? []" />
					</td>
				</tr>
				<tr v-if="rows.length === 0">
					<td colspan="7" class="lfp-hint">
						{{ t('large_file_previews', 'Nothing is processed: every MIME type is disabled.') }}
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<style scoped>
.lfp-active--excluded td,
.lfp-active--disabled td {
	color: var(--color-text-maxcontrast);
}
</style>
