import { createAppConfig } from '@nextcloud/vite-config'

// Baut js/calendar_planning-main.mjs und css/calendar_planning-main.css (siehe PageController).
export default createAppConfig({
	main: 'src/main.js',
}, {
	extractLicenseInformation: false,
	inlineCSS: false,
})
