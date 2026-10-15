<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * One rule's row, a MIME type's or an extension's: whether its files are
 * processed, its minimum and maximum, and a reset or a removal. A switch
 * saves on click, a size on its own button; a row whose type has no usable
 * renderer is shown, and cannot be changed.
 *
 * A MIME type is enabled or disabled, and resets to what it ships with; an
 * extension is enabled, excluded, or as its MIME type, and is removed.
 *
 * A size field shows what is set at this level, and where nothing is, what
 * it inherits as its placeholder: "50 (inherited)". The column header names
 * the field, so its label stays off it, where it would cover the placeholder.
 */
import { computed } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NumberField from './NumberField.vue'
import OccCommand from './OccCommand.vue'
import { ruleCommand } from './occ'
import { rendererProblem } from './renderer'
import { t } from '../l10n'
import type { Inherited, Renderer, Rule, RuleChange } from './types'

const props = withDefaults(defineProps<{
	kind: 'mime' | 'ext'
	name: string
	rule: Rule
	renderer: Renderer
	saving: boolean
	/** A MIME type's shipped rule, which a reset returns it to. */
	shipped?: Rule | null
	/**
	 * What is stored at this level, which the command sets: a MIME type's
	 * own fields; an extension's rule is what is stored already.
	 */
	stored?: Rule | null
	/** An extension's MIME type. */
	mime?: string | null
	/** An extension selected anyway by its MIME type, with no size of its own. */
	noEffect?: boolean
	/** What each size field falls back to where nothing is set at this level. */
	inherits?: Inherited | null
}>(), {
	shipped: null,
	stored: null,
	mime: null,
	noEffect: false,
	inherits: null,
})

const emit = defineEmits<{
	save: [change: RuleChange | null]
}>()

const FIELDS: (keyof Rule)[] = ['enabled', 'min_size_mb', 'max_size_mb']

/** Whether a field differs from what the type ships with. */
function differs(rule: Rule, shipped: Rule): boolean {
	return FIELDS.some((field) => rule[field] !== shipped[field])
}

function switchText(): string {
	if (props.kind === 'mime') {
		return props.rule.enabled === true ? t('large_file_previews', 'enabled') : t('large_file_previews', 'disabled')
	}
	if (props.rule.enabled === undefined) {
		return t('large_file_previews', 'as MIME type')
	}
	return props.rule.enabled ? t('large_file_previews', 'enabled') : t('large_file_previews', 'excluded')
}

/** What is set at this level: a MIME type's stored fields, an extension's rule. */
const own = computed<Rule>(() => props.stored ?? props.rule)

/** What a size field left empty inherits, as its placeholder. */
function inherited(field: keyof Inherited): string | null {
	if (props.inherits === null) {
		return null
	}
	const value = props.inherits[field]
	return t('large_file_previews', '{value} (inherited)', {
		value: value === -1 ? t('large_file_previews', 'no limit') : String(value),
	})
}

function fieldId(field: string): string {
	return 'lfp-' + field + '-' + (props.kind === 'ext' ? 'ext-' : '') + props.name
}
</script>

<template>
	<tr :class="{ 'lfp-unavailable': !renderer.available }">
		<td class="lfp-rule-name">
			<template v-if="kind === 'ext'">
				.{{ name }}
				<span class="lfp-hint">{{ mime ?? '' }}</span>
			</template>
			<template v-else>
				{{ name }}
			</template>
			<span v-if="rendererProblem(renderer) !== null" class="lfp-note lfp-note--error">{{ rendererProblem(renderer)?.text }}</span>
			<span v-else-if="noEffect" class="lfp-note lfp-note--warning lfp-no-effect">{{ t('large_file_previews', 'No effect: its MIME type already selects it') }}</span>
			<OccCommand :command="ruleCommand(kind, name, own)" />
		</td>
		<td>
			<NcCheckboxRadioSwitch type="switch"
				:model-value="rule.enabled === true"
				:disabled="saving || !renderer.available"
				@update:model-value="emit('save', { enabled: $event as boolean })">
				{{ switchText() }}
			</NcCheckboxRadioSwitch>
		</td>
		<td>
			<NumberField :id="fieldId('min')"
				:label="t('large_file_previews', 'Minimum')"
				:value="own.min_size_mb ?? null"
				:placeholder="inherited('min_size_mb')"
				:min="0"
				clearable
				narrow
				label-outside
				:disabled="saving || !renderer.available"
				@save="emit('save', { min_size_mb: $event })" />
		</td>
		<td>
			<NumberField :id="fieldId('max')"
				:label="t('large_file_previews', 'Maximum')"
				:value="own.max_size_mb ?? null"
				:placeholder="inherited('max_size_mb')"
				:min="-1"
				clearable
				narrow
				label-outside
				:disabled="saving || !renderer.available"
				@save="emit('save', { max_size_mb: $event })" />
		</td>
		<td>
			<NcButton v-if="kind === 'ext'"
				class="lfp-remove"
				variant="tertiary"
				:disabled="saving"
				@click="emit('save', null)">
				{{ t('large_file_previews', 'Remove') }}
			</NcButton>
			<NcButton v-else-if="shipped !== null && differs(rule, shipped)"
				class="lfp-reset"
				variant="tertiary"
				:disabled="saving || !renderer.available"
				@click="emit('save', null)">
				{{ t('large_file_previews', 'Reset') }}
			</NcButton>
		</td>
	</tr>
</template>
