<?php

declare(strict_types=1);

namespace OCA\CalendarPlanning\Settings;

use OCA\CalendarPlanning\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * Eigener Bereich „Kalender-Planung“ in der Verwaltung statt „Zusätzliche Einstellungen“.
 *
 * Dort teilt sich die App die Seite mit den Formularen anderer Apps; ein kaputtes Template einer davon (Impersonate 5.0.0
 * und 6.0.0 schließen ihr `<div>` nicht) nimmt die folgenden Formulare mit, und die Einstellungsseite bleibt leer.
 */
class AdminSection implements IIconSection {
	public function __construct(
		private IURLGenerator $url,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return 'Kalender-Planung';
	}

	public function getPriority(): int {
		return 80;
	}

	public function getIcon(): string {
		return $this->url->imagePath(Application::APP_ID, 'app-dark.svg');
	}
}
