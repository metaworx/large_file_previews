<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * What most servers leave as it is: libvips' threads and the largest
 * re-striped copy, and, read only, whether libvips' untrusted decoders are
 * allowed, where each program is, its version, and the scratch directory. These are set in system config with
 * occ, never here: a path is code the web server runs, and an untrusted
 * decoder is code a user's file reaches.
 */
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import HelpPopover from './HelpPopover.vue'
import InstallHint from './InstallHint.vue'
import NumberField from './NumberField.vue'
import OccCommand from './OccCommand.vue'
import { binaryCommand, scalarCommand, scratchCommand, untrustedCommand } from './occ'
import { t } from '../l10n'
import { scalarChange } from './types'
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
		<div data-setting="vips_allow_untrusted">
			<p class="lfp-named">
				<span>
					{{ state.untrusted.allowed ? t('large_file_previews', 'Untrusted decoders: allowed') : t('large_file_previews', 'Untrusted decoders: blocked') }}
					<span class="lfp-hint">{{ t('large_file_previews', '(system config)') }}</span>
				</span>
				<HelpPopover :text="t('large_file_previews', 'libvips counts some of its decoders as untrusted, not hardened against hostile files: with libvips 8.15, JPEG 2000, JPEG XL, PNM, and ImageMagick, which BMP needs. Blocked, those types go to Nextcloud\'s other providers. They are allowed in system config only, with the command beneath.')"
					:label="t('large_file_previews', 'Untrusted decoders')" />
			</p>
			<NcNoteCard v-if="!state.untrusted.allowed && state.untrusted.blockable === false" type="warning">
				{{ t('large_file_previews', 'libvips here is older than 8.13, or did not report its version, and cannot block them, so no image is rendered until it is upgraded or they are allowed.') }}
			</NcNoteCard>
			<OccCommand :command="untrustedCommand(state.untrusted.allowed)" />
		</div>
		<div class="lfp-fields" data-setting="vips_concurrency">
			<div>
				<NumberField id="lfp-vips-concurrency"
					:label="t('large_file_previews', 'Threads per render')"
					:value="state.scalars.vips_concurrency.value"
					:min="state.scalars.vips_concurrency.min"
					:max="state.scalars.vips_concurrency.max"
					:help="t('large_file_previews', 'One thread was fastest for a preview, and each thread reserves memory under the cap.')"
					:disabled="saving"
					@save="emit('save', scalarChange('vips_concurrency', $event))" />
				<OccCommand :command="scalarCommand('vips_concurrency', state.scalars.vips_concurrency.value)" />
			</div>
		</div>

		<h4 class="lfp-named">
			{{ t('large_file_previews', 'Programs') }}
			<HelpPopover :text="t('large_file_previews', 'Found once and kept in the memory cache for a day, without one found on every request; found again on install, after an upgrade, with the button below, or with occ lfp:check --refresh. A path is set in system config only, with the command beneath it.')"
				:label="t('large_file_previews', 'Programs')" />
		</h4>
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

		<h4 class="lfp-named">
			{{ t('large_file_previews', 'Scratch directory') }}
			<HelpPopover :text="t('large_file_previews', 'Where a TIFF in one strip that ran out of memory is copied, uncompressed, to be rendered again, one copy at a time. It must be a writable directory on disk, not on tmpfs or ramfs, with room for the copy and 256 MiB more; the copy is gone once rendered.')"
				:label="t('large_file_previews', 'Scratch directory')" />
		</h4>
		<p data-setting="scratch_directory">
			{{ state.scratch.path }}
			<span class="lfp-hint">
				{{ state.scratch.overridden ? t('large_file_previews', '(system config)') : t('large_file_previews', '(Nextcloud\'s temporary directory)') }}
			</span>
		</p>
		<OccCommand :command="scratchCommand(state.scratch.path)" />
		<div class="lfp-fields" data-setting="tiff_restrip_max_mb">
			<div>
				<NumberField id="lfp-tiff-restrip-max"
					:label="t('large_file_previews', 'Largest copy, MiB')"
					:value="state.scalars.tiff_restrip_max_mb.value"
					:min="state.scalars.tiff_restrip_max_mb.min"
					:max="state.scalars.tiff_restrip_max_mb.max"
					:help="t('large_file_previews', 'A TIFF whose copy would be larger is not copied, and stays a failure.')"
					:disabled="saving"
					@save="emit('save', scalarChange('tiff_restrip_max_mb', $event))" />
				<OccCommand :command="scalarCommand('tiff_restrip_max_mb', state.scalars.tiff_restrip_max_mb.value)" />
			</div>
		</div>
	</div>
</template>
