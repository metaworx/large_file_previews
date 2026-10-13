<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * The admin settings page: the status, which files are taken, and the
 * limits each render runs under.
 */
import { onMounted } from 'vue'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import LimitsSection from './LimitsSection.vue'
import StatusSection from './StatusSection.vue'
import TypesSection from './TypesSection.vue'
import { useSettings } from './useSettings'
import { t } from '../l10n'

const { state, saving, failed, load, save } = useSettings()

onMounted(load)
</script>

<template>
	<div class="lfp-settings">
		<NcNoteCard v-if="failed" type="error">
			{{ t('large_file_previews', 'The settings could not be loaded.') }}
		</NcNoteCard>
		<NcLoadingIcon v-else-if="state === null" :size="32" />
		<template v-else>
			<StatusSection :state="state" />
			<TypesSection :state="state" :saving="saving" @save="save" />
			<LimitsSection :state="state" :saving="saving" @save="save" />
		</template>
	</div>
</template>
