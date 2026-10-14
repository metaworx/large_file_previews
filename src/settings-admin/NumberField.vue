<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * A number with its own Save button, enabled once the number differs from
 * what is saved. Where `clearable`, an empty field saves as null: the value
 * is not set at this level. The field shows only what is set here, never a
 * value from another level.
 */
import { computed, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { t } from '../l10n'

const props = withDefaults(defineProps<{
	id: string
	label: string
	value: number | null
	min?: number | null
	max?: number | null
	clearable?: boolean
	narrow?: boolean
	disabled?: boolean
}>(), {
	min: null,
	max: null,
	clearable: false,
	narrow: false,
	disabled: false,
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
	<div class="lfp-number" :class="{ 'lfp-number--narrow': narrow }">
		<NcTextField :id="id"
			v-model="draft"
			class="lfp-number__field"
			type="number"
			:label="label"
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
	</div>
</template>

<style scoped>
.lfp-number {
	display: inline-flex;
	align-items: flex-end;
	gap: calc(var(--default-grid-baseline) * 2);
}

/* Wide enough for its label, which the field would otherwise cut. */
.lfp-number__field {
	flex: 0 0 auto;
	width: 16rem;
}

.lfp-number--narrow .lfp-number__field {
	width: 8rem;
}

.lfp-number__save {
	flex: 0 0 auto;
	min-width: max-content;
}
</style>
