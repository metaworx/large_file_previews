import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
	plugins: [vue()],
	// @nextcloud/vue reads two globals the consuming app's bundler is
	// expected to define. Free identifiers otherwise, and a ReferenceError in
	// a mounted hook.
	define: {
		appName: JSON.stringify('large_file_previews'),
		appVersion: JSON.stringify('test'),
	},
	test: {
		globals: true,
		environment: 'happy-dom',
		// @nextcloud/vue ships its components as ES modules that import their
		// stylesheets. Left external, they go through Node's own loader, which
		// has no idea what a .css file is; inlined, they go through Vite,
		// which knows. This is what lets a spec mount the real component
		// rather than a stand-in, and .eslintrc.cjs holds the specs to that.
		// @nextcloud/dialogs, which the toasts come from, imports them too.
		server: { deps: { inline: [/@nextcloud\/vue/, /@nextcloud\/dialogs/] } },
		setupFiles: ['./vitest.setup.ts'],
		include: ['src/**/*.spec.ts'],
		exclude: [
			'**/node_modules/**',
			'tests/**',
			'vendor/**',
			'nextcloud-v*/**',
		],
	},
})
