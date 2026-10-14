<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * The admin settings page: one section, under the app's name (h2), with
 * the status, which files are processed, the previews' format, the limits
 * and the advanced settings (h3), each with its parts (h4).
 */
import { onMounted } from 'vue'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import AdvancedSection from './AdvancedSection.vue'
import LimitsSection from './LimitsSection.vue'
import PreviewsSection from './PreviewsSection.vue'
import StatusSection from './StatusSection.vue'
import TypesSection from './TypesSection.vue'
import { useSettings } from './useSettings'
import { t } from '../l10n'

const { state, saving, failed, load, save, refresh } = useSettings()

onMounted(load)
</script>

<template>
	<NcSettingsSection class="lfp-settings"
		:name="t('large_file_previews', 'Large File Previews')"
		:description="t('large_file_previews', 'Previews of files too large for Nextcloud\'s own providers, rendered from the file\'s local path: PDF and Illustrator by pdftoppm, images by libvips. Anything this app does not process goes to the next provider. Beneath each setting is the occ command that sets it.')">
		<NcNoteCard v-if="failed" type="error">
			{{ t('large_file_previews', 'The settings could not be loaded.') }}
		</NcNoteCard>
		<NcLoadingIcon v-else-if="state === null" :size="32" />
		<template v-else>
			<StatusSection :state="state" />
			<TypesSection :state="state" :saving="saving" @save="save" />
			<PreviewsSection :state="state" :saving="saving" @save="save" />
			<LimitsSection :state="state" :saving="saving" @save="save" />
			<AdvancedSection :state="state"
				:saving="saving"
				@save="save"
				@refresh="refresh" />
		</template>
	</NcSettingsSection>
</template>
