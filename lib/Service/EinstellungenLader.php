<?php

declare(strict_types=1);

namespace OCA\CalendarPlanning\Service;

use OCA\CalendarPlanning\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Liest die Einstellungen der App aus der App-Konfiguration von Nextcloud.
 *
 * Setzen (bis es eine Einstellungsseite gibt):
 *   occ config:app:set calendar_planning ort_vorgabe --value "Vereinsheim"
 *   occ config:app:set calendar_planning kalender_muster --value "Planung"
 *   occ config:app:set calendar_planning link_text --value "Planung öffnen:"
 */
class EinstellungenLader {
	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	public function laden(): Einstellungen {
		return new Einstellungen(
			$this->wert(Einstellungen::SCHLUESSEL_ORT, ''),
			$this->wert(Einstellungen::SCHLUESSEL_KALENDER_MUSTER, ''),
			$this->wert(Einstellungen::SCHLUESSEL_LINK_TEXT, Einstellungen::LINK_TEXT_VORGABE),
		);
	}

	private function wert(string $schluessel, string $vorgabe): string {
		return $this->appConfig->getValueString(Application::APP_ID, $schluessel, $vorgabe);
	}
}
