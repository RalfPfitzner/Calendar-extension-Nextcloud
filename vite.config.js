import { createAppConfig } from '@nextcloud/vite-config'

// Baut js/msp_planung-main.mjs und css/msp_planung-main.css (siehe PageController).
export default createAppConfig({
	main: 'src/main.js',
}, {
	extractLicenseInformation: false,
	inlineCSS: false,
})
