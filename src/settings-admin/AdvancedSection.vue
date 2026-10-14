<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * What most servers leave as it is: libvips' untrusted decoders and threads,
 * and, read only, where each program is, its version, and the scratch
 * directory. A path is set in system config with occ, never here: a path is
 * code the web server runs.
 */
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import InstallHint from './InstallHint.vue'
import NumberField from './NumberField.vue'
import OccCommand from './OccCommand.vue'
import { binaryCommand, scalarCommand, scratchCommand } from './occ'
import { t } from '../l10n'
import type { Changes, ProgramRow, SettingsState } from './types'

defineProps<{
	state: SettingsState
	saving: boolean
}>()

const emit = defineEmits<{
	save: [changes: Changes]
	refresh: []
}>()

function foundBy(program: ProgramRow): string {
	return program.source === 'override'
		? t('large_file_previews', 'system config')
		: t('large_file_previews', 'Nextcloud')
}
</script>

<template>
	<div class="lfp-advanced">
		<h3>{{ t('large_file_previews', 'Advanced') }}</h3>

		<h4>{{ t('large_file_previews', 'libvips') }}</h4>
		<div data-setting="vips_block_untrusted">
			<NcCheckboxRadioSwitch type="switch"
				:model-value="state.scalars.vips_block_untrusted.value as boolean"
				:disabled="saving"
				@update:model-value="emit('save', { scalars: { vips_block_untrusted: $event as boolean } })">
				{{ t('large_file_previews', 'Block untrusted decoders') }}
			</NcCheckboxRadioSwitch>
			<p class="lfp-hint">
				{{ t('large_file_previews', 'libvips counts some of its decoders as untrusted, not hardened against hostile files: JPEG 2000, JPEG XL, PNM, and ImageMagick, which BMP needs. Blocked, those types go to Nextcloud\'s other providers.') }}
			</p>
			<OccCommand :command="scalarCommand('vips_block_untrusted', state.scalars.vips_block_untrusted.value)" />
		</div>
		<div data-setting="vips_concurrency">
			<NumberField id="lfp-vips-concurrency"
				:label="t('large_file_previews', 'Threads per render')"
				:value="state.scalars.vips_concurrency.value as number"
				:min="state.scalars.vips_concurrency.min"
				:max="state.scalars.vips_concurrency.max"
				:disabled="saving"
				@save="emit('save', { scalars: { vips_concurrency: $event } })" />
			<p class="lfp-hint">
				{{ t('large_file_previews', 'One thread was fastest for a preview, and each thread reserves memory under the cap.') }}
			</p>
			<OccCommand :command="scalarCommand('vips_concurrency', state.scalars.vips_concurrency.value)" />
		</div>

		<h4>{{ t('large_file_previews', 'Programs') }}</h4>
		<p class="lfp-hint">
			{{ t('large_file_previews', 'Found once and kept; found again after an upgrade, with the button below, or with occ lfp:check --refresh. A path is set in system config only, with the command beneath it.') }}
		</p>
		<table class="lfp-table lfp-programs">
			<thead>
				<tr>
					<th>{{ t('large_file_previews', 'Program') }}</th>
					<th>{{ t('large_file_previews', 'Path') }}</th>
					<th>{{ t('large_file_previews', 'Found by') }}</th>
					<th>{{ t('large_file_previews', 'Version') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="program in state.programs" :key="program.name" :data-program="program.name">
					<td>{{ program.name }}</td>
					<td>
						<template v-if="program.path !== null">
							{{ program.path }}
						</template>
						<span v-else class="lfp-hint">{{ program.problem ?? t('large_file_previews', 'not found') }}</span>
						<InstallHint v-if="program.path === null && program.install !== null" :install="program.install" />
						<OccCommand :command="binaryCommand(program.name, program.path ?? '/path/to/' + program.name)" />
					</td>
					<td>{{ foundBy(program) }}</td>
					<td>{{ program.version ?? '' }}</td>
				</tr>
			</tbody>
		</table>
		<NcButton class="lfp-refresh" :disabled="saving" @click="emit('refresh')">
			{{ t('large_file_previews', 'Find programs again') }}
		</NcButton>

		<h4>{{ t('large_file_previews', 'Scratch directory') }}</h4>
		<p class="lfp-hint">
			{{ t('large_file_previews', 'Where a TIFF in one strip that ran out of memory is copied, uncompressed, to be rendered again. It must be on disk, not tmpfs, with room for the image.') }}
		</p>
		<p data-setting="scratch_directory">
			{{ state.scratch.path }}
			<span class="lfp-hint">
				{{ state.scratch.overridden ? t('large_file_previews', '(system config)') : t('large_file_previews', '(Nextcloud\'s temporary directory)') }}
			</span>
		</p>
		<OccCommand :command="scratchCommand(state.scratch.path)" />
	</div>
</template>
