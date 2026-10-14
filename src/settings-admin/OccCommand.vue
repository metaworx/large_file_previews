<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * The occ command for a setting, in a small grey line beneath it, copied
 * with one click.
 */
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { t } from '../l10n'
import { toastError, toastSaved } from '../toast'

const props = defineProps<{
	command: string
}>()

/** "content-copy" from Material Design Icons, Apache-2.0. */
const COPY_ICON = 'M19,21H8V7H19M19,5H8A2,2 0 0,0 6,7V21A2,2 0 0,0 8,23H19A2,2 0 0,0 21,21V7A2,2 0 0,0 19,5M16,1H4A2,2 0 0,0 2,3V17H4V3H16V1Z'

async function copy(): Promise<void> {
	try {
		await navigator.clipboard.writeText(props.command)
		toastSaved(t('large_file_previews', 'Command copied.'))
	} catch (e) {
		toastError(t('large_file_previews', 'The command could not be copied; select it instead.'))
	}
}
</script>

<template>
	<div class="lfp-occ">
		<code class="lfp-occ__command">{{ command }}</code>
		<NcButton class="lfp-occ__copy"
			variant="tertiary-no-background"
			size="small"
			:aria-label="t('large_file_previews', 'Copy the command')"
			:title="t('large_file_previews', 'Copy the command')"
			@click="copy">
			<template #icon>
				<NcIconSvgWrapper :path="COPY_ICON" :size="16" />
			</template>
		</NcButton>
	</div>
</template>

<style scoped>
.lfp-occ {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
}

.lfp-occ__command {
	font-family: var(--font-face-monospace, monospace);
	overflow-wrap: anywhere;
	user-select: all;
}
</style>
