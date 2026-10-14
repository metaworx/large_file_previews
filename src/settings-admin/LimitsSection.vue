<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * The limits each render runs under, each switchable, each with its value.
 * A limit switched on whose program is missing stops rendering rather than
 * let it run without; the status above says which.
 */
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NumberField from './NumberField.vue'
import OccCommand from './OccCommand.vue'
import { scalarCommand } from './occ'
import { t } from '../l10n'
import type { Changes, SettingsState } from './types'

const props = defineProps<{
	state: SettingsState
	saving: boolean
}>()

const emit = defineEmits<{
	save: [changes: Changes]
}>()

interface Limit {
	switch: string
	name: string
	help: string
	values: { key: string, label: string }[]
}

const LIMITS: Limit[] = [
	{
		switch: 'timeout_enabled',
		name: t('large_file_previews', 'Timeout'),
		help: t('large_file_previews', 'Stops a render that takes too long: SIGTERM, then SIGKILL after the grace period.'),
		values: [
			{ key: 'timeout_seconds', label: t('large_file_previews', 'Seconds') },
			{ key: 'timeout_kill_after_seconds', label: t('large_file_previews', 'Grace period, seconds') },
		],
	},
	{
		switch: 'prlimit_enabled',
		name: t('large_file_previews', 'Memory cap'),
		help: t('large_file_previews', 'Caps the address space of a render, so a runaway allocation fails inside the renderer. Address space counts mapped libraries too, so it is above the memory a render uses: a 1.7 GB PDF rendered within 96 MB. Images through libvips have a cap of their own: it decodes whole strips of an image, and its threads reserve memory each.'),
		values: [
			{ key: 'prlimit_as_mb', label: t('large_file_previews', 'MB') },
			{ key: 'vips_prlimit_as_mb', label: t('large_file_previews', 'MB, libvips') },
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

function scalar(key: string) {
	return props.state.scalars[key]
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
			<NcCheckboxRadioSwitch type="switch"
				:model-value="scalar(limit.switch).value as boolean"
				:disabled="saving"
				@update:model-value="emit('save', { scalars: { [limit.switch]: $event as boolean } })">
				{{ limit.name }}
			</NcCheckboxRadioSwitch>
			<p class="lfp-hint">
				{{ limit.help }}
			</p>
			<OccCommand :command="scalarCommand(limit.switch, scalar(limit.switch).value)" />
			<div class="lfp-row">
				<div v-for="value in limit.values" :key="value.key">
					<NumberField :id="'lfp-' + value.key"
						:label="value.label"
						:value="scalar(value.key).value as number"
						:min="scalar(value.key).min"
						:max="scalar(value.key).max"
						:disabled="saving || !scalar(limit.switch).value"
						@save="emit('save', { scalars: { [value.key]: $event } })" />
					<OccCommand :command="scalarCommand(value.key, scalar(value.key).value)" />
				</div>
			</div>
		</div>
	</div>
</template>
