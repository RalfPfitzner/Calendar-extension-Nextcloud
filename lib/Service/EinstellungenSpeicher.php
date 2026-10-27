<?php

declare(strict_types=1);

namespace OCA\CalendarPlanning\Service;

use OCA\CalendarPlanning\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Liest und schreibt die Einstellungen der App in der App-Konfiguration von Nextcloud.
 *
 * Setzen ohne Einstellungsseite:
 *   occ config:app:set calendar_planning ort_vorgabe --value "Vereinsheim"
 *   occ config:app:set calendar_planning kalender_muster --value "Planung"
 *   occ config:app:set calendar_planning link_text --value "Planung öffnen:"
 *   occ config:app:set calendar_planning schema --value "$(cat schema/vorgabe.json)"
 */
class EinstellungenSpeicher {
	/** Mitgelieferte Schemata zum Laden auf der Admin-Seite: Kennung => Datei. */
	private const PRESETS = ['msp' => 'preset-msp.json'];

	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}

	public function laden(): Einstellungen {
		return new Einstellungen(
			$this->wert(Einstellungen::SCHLUESSEL_ORT, ''),
			$this->wert(Einstellungen::SCHLUESSEL_KALENDER_MUSTER, ''),
			$this->wert(Einstellungen::SCHLUESSEL_LINK_TEXT, Einstellungen::LINK_TEXT_VORGABE),
			$this->schemaLaden(),
		);
	}

	/**
	 * Prüft und speichert die drei Vorgaben.
	 *
	 * @param array<string,mixed> $eingabe {ort_vorgabe, kalender_muster, link_text}
	 */
	public function vorgabenSpeichern(array $eingabe): Einstellungen {
		$texte = [];
		foreach ([Einstellungen::SCHLUESSEL_ORT, Einstellungen::SCHLUESSEL_KALENDER_MUSTER, Einstellungen::SCHLUESSEL_LINK_TEXT] as $schluessel) {
			$wert = $eingabe[$schluessel] ?? '';
			if (!is_string($wert)) {
				throw new PlanungException("Einstellung „{$schluessel}“ ist kein Text");
			}
			$texte[$schluessel] = $wert;
		}
		// Durch den Konstruktor laufen lassen: bereinigt und deckelt wie beim Lesen.
		$neu = new Einstellungen($texte[Einstellungen::SCHLUESSEL_ORT], $texte[Einstellungen::SCHLUESSEL_KALENDER_MUSTER], $texte[Einstellungen::SCHLUESSEL_LINK_TEXT]);
		$this->appConfig->setValueString(Application::APP_ID, Einstellungen::SCHLUESSEL_ORT, $neu->ortVorgabe);
		$this->appConfig->setValueString(Application::APP_ID, Einstellungen::SCHLUESSEL_KALENDER_MUSTER, $neu->kalenderMuster);
		$this->appConfig->setValueString(Application::APP_ID, Einstellungen::SCHLUESSEL_LINK_TEXT, rtrim($neu->linkText));
		return $this->laden();
	}

	/**
	 * Prüft und speichert das Feldschema; bei Fehlern bleibt das alte stehen.
	 *
	 * @param array<string,mixed> $eingabe
	 * @throws PlanungException bei ungültigem Schema (400)
	 */
	public function schemaSpeichern(array $eingabe): Schema {
		$schema = Schema::ausArray($eingabe);
		$this->appConfig->setValueString(Application::APP_ID, Einstellungen::SCHLUESSEL_SCHEMA, $schema->alsJson());
		return $schema;
	}

	/** @return list<array{id:string,name:string,schema:array<string,mixed>}> */
	public function presets(): array {
		$liste = [];
		foreach (self::PRESETS as $id => $datei) {
			$pfad = __DIR__ . '/../../schema/' . $datei;
			$json = @file_get_contents($pfad);
			$daten = is_string($json) ? json_decode($json, true) : null;
			if (!is_array($daten)) {
				$this->logger->warning('Kalender-Planung: Preset nicht lesbar: ' . $datei);
				continue;
			}
			$liste[] = [
				'id' => $id,
				'name' => is_string($daten['name'] ?? null) ? Einstellungen::einzeilig($daten['name'], 100) : $id,
				'schema' => Schema::ausArray($daten)->alsArray(),
			];
		}
		return $liste;
	}

	private function schemaLaden(): Schema {
		$json = $this->wert(Einstellungen::SCHLUESSEL_SCHEMA, '');
		if ($json === '') {
			return Schema::vorgabe();
		}
		try {
			return Schema::ausJson($json);
		} catch (PlanungException $e) {
			// Ein kaputter Wert (z. B. von Hand über occ gesetzt) darf die App nicht lahmlegen: Vorgabe nehmen, Hinweis ins Protokoll.
			$this->logger->warning('Kalender-Planung: gespeichertes Schema ungültig, Vorgabe wird genutzt: ' . $e->getMessage());
			return Schema::vorgabe();
		}
	}

	private function wert(string $schluessel, string $vorgabe): string {
		return $this->appConfig->getValueString(Application::APP_ID, $schluessel, $vorgabe);
	}
}
