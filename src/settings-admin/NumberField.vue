<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * A number with its own Save button, enabled once the number differs from
 * what is saved. Where `clearable`, an empty field saves as null: the value
 * is not set at this level. The field shows only what is set here, never a
 * value from another level; what applies where it is empty may be its
 * placeholder. Its help, where it has one, is an ⓘ after the button.
 *
 * A label inside the field covers the placeholder until the field has the
 * focus; `labelOutside` puts it above the field instead, where the
 * placeholder shows.
 */
import { computed, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import HelpPopover from './HelpPopover.vue'
import { t } from '../l10n'

const props = withDefaults(defineProps<{
	id: string
	label: string
	value: number | null
	min?: number | null
	max?: number | null
	clearable?: boolean
	/** The label above the field rather than in it, so that the placeholder shows. */
	labelOutside?: boolean
	disabled?: boolean
	/** What applies where the field is empty: the value of the level below. */
	placeholder?: string | null
	/** What the field means, behind an ⓘ after its Save button. */
	help?: string | null
}>(), {
	min: null,
	max: null,
	clearable: false,
	labelOutside: false,
	disabled: false,
	placeholder: null,
	help: null,
})

const emit = defineEmits<{
	save: [value: number | null]
}>()

const draft = ref(props.value === null ? '' : String(props.value))

watch(() => props.value, (value) => {
	draft.value = value === null ? '' : String(value)
})

/** The draft as a number, null where empty, undefined where not a number. */
const parsed = computed<number | null | undefined>(() => {
	const text = draft.value.trim()
	if (text === '') {
		return props.clearable ? null : undefined
	}
	return /^-?\d+$/.test(text) ? Number(text) : undefined
})

const dirty = computed(() => parsed.value !== undefined && parsed.value !== props.value)

function save(): void {
	if (dirty.value && parsed.value !== undefined) {
		emit('save', parsed.value)
	}
}
</script>

<template>
	<div class="lfp-number" :class="{ 'lfp-number--outside': labelOutside }">
		<label v-if="labelOutside" :for="id" class="lfp-number__label">{{ label }}</label>
		<div class="lfp-number__row">
			<NcTextField :id="id"
				v-model="draft"
				class="lfp-number__field"
				type="number"
				:label="label"
				:label-outside="labelOutside"
				:placeholder="placeholder ?? undefined"
				:min="min ?? undefined"
				:max="max ?? undefined"
				:disabled="disabled"
				:error="parsed === undefined"
				@keyup.enter="save" />
			<NcButton class="lfp-number__save"
				:variant="dirty ? 'primary' : 'secondary'"
				:disabled="disabled || !dirty"
				:aria-label="t('large_file_previews', 'Save {label}', { label })"
				@click="save">
				{{ t('large_file_previews', 'Save') }}
			</NcButton>
			<HelpPopover v-if="help" :text="help" :label="label" />
		</div>
	</div>
</template>

<style scoped>
/* A block that shrinks to its row, not a column flexbox, which Chrome sizes
   to the row's narrowest, wrapping it everywhere. */
.lfp-number {
	display: inline-block;
	max-width: 100%;
}

.lfp-number__row {
	display: flex;
	align-items: flex-end;
	gap: calc(var(--default-grid-baseline) * 2);
}

.lfp-number__label {
	display: block;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
}

/* Wide enough for its label, which the field would otherwise cut, and
   narrower only where the line is: its Save button and help stay beside it.
   Two classes, to outweigh the width Nextcloud gives every input field. */
.lfp-number .lfp-number__field {
	flex: 0 1 auto;
	width: 16rem;
	min-width: 6rem;
}

.lfp-number__save {
	flex: 0 0 auto;
	min-width: max-content;
}
</style>
