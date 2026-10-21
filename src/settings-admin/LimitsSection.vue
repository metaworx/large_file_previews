<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * The limits each render runs under, each switchable, each with its value,
 * the sandbox, auto, on or off, and how long a file that failed is left
 * alone. A limit switched on whose program is missing stops rendering
 * rather than let it run without, as the sandbox does when on; the status
 * above says which. What each does is behind its ⓘ.
 */
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import HelpPopover from './HelpPopover.vue'
import NumberField from './NumberField.vue'
import OccCommand from './OccCommand.vue'
import { scalarCommand, storedScalarCommand } from './occ'
import { defaultPlaceholder, storedNumber } from './scalarField'
import { t } from '../l10n'
import { scalarChange } from './types'
import type { Changes, ScalarKeyOf, SettingsState } from './types'

defineProps<{
	state: SettingsState
	saving: boolean
}>()

const emit = defineEmits<{
	save: [changes: Changes]
}>()

interface Limit {
	switch: ScalarKeyOf<boolean>
	name: string
	help: string
	values: { key: ScalarKeyOf<number>, label: string }[]
}

const LIMITS: Limit[] = [
	{
		switch: 'timeout_enabled',
		name: t('large_file_previews', 'Timeout'),
		help: t('large_file_previews', 'Stops a render that takes too long: SIGTERM, then SIGKILL after the grace period. Every program one render runs shares the time.'),
		values: [
			{ key: 'timeout_seconds', label: t('large_file_previews', 'Seconds') },
			{ key: 'timeout_kill_after_seconds', label: t('large_file_previews', 'Grace period, seconds') },
		],
	},
	{
		switch: 'prlimit_enabled',
		name: t('large_file_previews', 'Address-space and file-size caps'),
		help: t('large_file_previews', 'Caps the address space of a render, so a runaway allocation fails inside the renderer. Address space counts mapped libraries too, so it is above the memory a render uses: a 1.7 GB PDF rendered within 96 MiB. Images through libvips have a cap of their own: it decodes whole strips of an image, and its threads reserve memory each. The file-size cap is the largest file a render may write: its preview, its messages, what libvips spills to disc.'),
		values: [
			{ key: 'prlimit_as_mb', label: t('large_file_previews', 'Address space, MiB') },
			{ key: 'vips_prlimit_as_mb', label: t('large_file_previews', 'libvips, MiB') },
			{ key: 'prlimit_fsize_mb', label: t('large_file_previews', 'Largest file, MiB') },
		],
	},
	{
		switch: 'choom_enabled',
		name: t('large_file_previews', 'Out-of-memory score'),
		help: t('large_file_previews', 'Raises the score the kernel kills by when memory runs out, so a render dies before a web server worker.'),
		values: [
			{ key: 'choom_adjust', label: t('large_file_previews', 'Adjustment, 0 to 1000') },
		],
	},
]

/** The sandbox's three choices, each with what it does. */
const SANDBOX_LABELS: Record<string, string> = {
	auto: t('large_file_previews', 'Where it can start (auto)'),
	on: t('large_file_previews', 'Always, or render nothing (on)'),
	off: t('large_file_previews', 'Never (off)'),
}

/** The radio group's name, which ties its radios together. */
const SANDBOX_GROUP = 'lfp-sandbox'

function sandboxLabel(choice: string): string {
	return SANDBOX_LABELS[choice] ?? choice
}
</script>

<template>
	<div class="lfp-limits">
		<h3>{{ t('large_file_previews', 'Limits') }}</h3>
		<p class="lfp-hint">
			{{ t('large_file_previews', 'What each render runs under. The defaults suit most servers.') }}
		</p>
		<div v-for="limit in LIMITS"
			:key="limit.switch"
			class="lfp-limit"
			:data-limit="limit.switch">
			<div class="lfp-named">
				<NcCheckboxRadioSwitch type="switch"
					:model-value="state.scalars[limit.switch].value"
					:disabled="saving"
					@update:model-value="emit('save', scalarChange(limit.switch, $event as boolean))">
					{{ limit.name }}
				</NcCheckboxRadioSwitch>
				<HelpPopover :text="limit.help" :label="limit.name" />
			</div>
			<OccCommand :command="scalarCommand(limit.switch, state.scalars[limit.switch].value)" />
			<div class="lfp-fields">
				<div v-for="value in limit.values" :key="value.key">
					<NumberField :id="'lfp-' + value.key"
						:label="value.label"
						:value="storedNumber(state.scalars[value.key])"
						:placeholder="defaultPlaceholder(state.scalars[value.key])"
						:min="state.scalars[value.key].min"
						:max="state.scalars[value.key].max"
						clearable
						label-outside
						:disabled="saving || !state.scalars[limit.switch].value"
						@save="emit('save', scalarChange(value.key, $event))" />
					<OccCommand :command="storedScalarCommand(value.key, state.scalars[value.key])" />
				</div>
			</div>
		</div>

		<div class="lfp-limit" data-setting="sandbox">
			<h4 class="lfp-named">
				{{ t('large_file_previews', 'Sandbox (bubblewrap)') }}
				<HelpPopover :text="t('large_file_previews', 'Runs each render in a bubblewrap sandbox: no network, and of the files only the system\'s programs and the render\'s own folder, not the data directory or config.php. Needs bwrap and user namespaces, which a container such as Nextcloud All-in-One usually refuses. Auto uses it where the process rendering can start one, and renders without it where not; on renders nothing where it cannot start. The status above says which applies.')"
					:label="t('large_file_previews', 'Sandbox (bubblewrap)')" />
			</h4>
			<div class="lfp-choices">
				<NcCheckboxRadioSwitch v-for="choice in state.scalars.sandbox.choices ?? []"
					:key="choice"
					type="radio"
					:name="SANDBOX_GROUP"
					:value="choice"
					:model-value="state.scalars.sandbox.value"
					:disabled="saving"
					@update:model-value="emit('save', scalarChange('sandbox', $event as string))">
					{{ sandboxLabel(choice) }}
				</NcCheckboxRadioSwitch>
				<OccCommand :command="scalarCommand('sandbox', state.scalars.sandbox.value)" />
			</div>
		</div>

		<div class="lfp-limit" data-setting="failure_retry_minutes">
			<h4 class="lfp-named">
				{{ t('large_file_previews', 'Files that failed') }}
				<HelpPopover :text="t('large_file_previews', 'A file that failed to render is left to Nextcloud\'s other providers for this many minutes, rather than tried again on every page that shows it; 0 tries it every time. A change to the file, or to a setting a render uses, tries it at once. Kept in the memory cache, so without one every request tries it.')"
					:label="t('large_file_previews', 'Files that failed')" />
			</h4>
			<NumberField id="lfp-failure_retry_minutes"
				:label="t('large_file_previews', 'Minutes')"
				:value="storedNumber(state.scalars.failure_retry_minutes)"
				:placeholder="defaultPlaceholder(state.scalars.failure_retry_minutes)"
				:min="state.scalars.failure_retry_minutes.min"
				:max="state.scalars.failure_retry_minutes.max"
				clearable
				label-outside
				:disabled="saving"
				@save="emit('save', scalarChange('failure_retry_minutes', $event))" />
			<OccCommand :command="storedScalarCommand('failure_retry_minutes', state.scalars.failure_retry_minutes)" />
		</div>
	</div>
</template>
