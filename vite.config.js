import { createAppConfig } from '@nextcloud/vite-config'

// Baut js/calendar_planning-main.mjs und css/calendar_planning-main.css (siehe PageController)
// sowie js/calendar_planning-admin.mjs und css/calendar_planning-admin.css (Admin-Einstellungen, lib/Settings/Admin.php).
export default createAppConfig({
	main: 'src/main.js',
	admin: 'src/admin.js',
}, {
	extractLicenseInformation: false,
	inlineCSS: false,
})
