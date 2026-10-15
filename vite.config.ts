import { createAppConfig } from '@nextcloud/vite-config'
import { join, resolve } from 'path'

export default createAppConfig(
	{
		'settings-admin': resolve(join('src', 'settings-admin', 'main.ts')),
	},
	{
		createEmptyCSSEntryPoints: true,
		extractLicenseInformation: true,
		thirdPartyLicense: false,
		// Source maps for `npm run dev` only: a release ships none.
		config: ({ mode }) => ({
			build: { sourcemap: mode === 'development' },
		}),
	},
)
