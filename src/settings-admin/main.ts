/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * The admin settings page's entry point.
 */
import { createApp } from 'vue'
import { setTexts } from '@metaworx/nextcloud-vue'
import '@metaworx/nextcloud-vue/style.css'
import { texts } from '../texts'
import App from './App.vue'
import './settings-admin.css'

setTexts(texts)
createApp(App).mount('#lfp-admin-settings')
