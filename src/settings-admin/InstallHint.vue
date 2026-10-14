<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * What installs a program on this server's platform: the command, copied
 * with one click, and a note where one is due, such as a repository to
 * enable, or that no package brings it.
 */
import OccCommand from './OccCommand.vue'
import { t } from '../l10n'
import type { Install } from './types'

defineProps<{
	install: Install
}>()

function noteText(note: string): string {
	switch (note) {
	case 'not-packaged':
		return t('large_file_previews', 'No package brings it on RHEL or EPEL.')
	case 'crb':
		return t('large_file_previews', 'From the CRB repository, which must be enabled.')
	case 'community':
		return t('large_file_previews', 'From the community repository.')
	default:
		return note
	}
}
</script>

<template>
	<div class="lfp-install">
		<OccCommand v-if="install.command !== null" :command="install.command" />
		<span v-if="install.note !== null" class="lfp-note lfp-note--warning">{{ noteText(install.note) }}</span>
	</div>
</template>
