<?php

declare(strict_types=1);

namespace OCA\CalendarPlanning\Settings;

use OCA\CalendarPlanning\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

/** Admin-Einstellungen unter „Verwaltung → Zusätzliche Einstellungen“; die Oberfläche ist src/admin.js. */
class Admin implements ISettings {
	public function getForm(): TemplateResponse {
		Util::addScript(Application::APP_ID, Application::APP_ID . '-admin');
		Util::addStyle(Application::APP_ID, Application::APP_ID . '-admin');
		return new TemplateResponse(Application::APP_ID, 'admin', [], TemplateResponse::RENDER_AS_BLANK);
	}

	public function getSection(): string {
		return 'additional';
	}

	public function getPriority(): int {
		return 60;
	}
}
