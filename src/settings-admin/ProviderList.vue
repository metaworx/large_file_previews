<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * The preview providers Nextcloud tries for a type, in its order, each named
 * with its class as the tooltip. One asked before this app's is amber: it
 * may take a file the app would have taken.
 */
import type { Provider } from './types'

defineProps<{
	providers: Provider[]
}>()
</script>

<template>
	<span class="lfp-providers">
		<template v-for="(provider, index) in providers" :key="provider.class + index">
			<span v-if="index > 0" class="lfp-providers__then" aria-hidden="true" />
			<span class="lfp-provider"
				:class="{ 'lfp-provider--ours': provider.ours, 'lfp-provider--before': provider.before }"
				:title="provider.class">{{ provider.name }}</span>
		</template>
		<span v-if="providers.length === 0">-</span>
	</span>
</template>

<style scoped>
/* Then: the next provider asked. */
.lfp-providers__then::before {
	content: '\203A';
	margin-inline: var(--default-grid-baseline);
	color: var(--color-text-maxcontrast);
}

.lfp-provider--ours {
	font-weight: bold;
}

/* Asked before this app: amber, as the page's other warnings. */
.lfp-provider--before {
	padding: 0 calc(var(--default-grid-baseline) * 2);
	border-radius: var(--border-radius-pill, 999px);
	background-color: var(--color-warning);
	color: var(--color-warning-text, #000);
}
</style>
