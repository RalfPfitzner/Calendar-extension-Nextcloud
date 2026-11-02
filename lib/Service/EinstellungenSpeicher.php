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
 *   occ config:app:set calendar_planning schema --lazy --value "$(cat schema/vorgabe.json)"
 *
 * Das Schema (bis 64 KiB) steht als „lazy“ in der App-Konfiguration: Nextcloud lädt es nicht bei jeder Anfrage aller
 * Apps mit, sondern erst, wenn diese App es liest. Ältere, nicht-lazy gespeicherte Werte liest IAppConfig mit
 * `lazy: true` ebenso (in Nextcloud 34 und 35 geprüft); beim nächsten Speichern setzt Nextcloud das Kennzeichen um.
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
	 * Gespeichert wird nur, was sich auch wieder laden lässt: Ein Schema, dessen JSON größer als Schema::MAX_JSON
	 * ist, würde schemaLaden() verwerfen und still durch die Vorgabe ersetzen (S-KAL-02). Darum schon hier ablehnen.
	 *
	 * @param array<string,mixed> $eingabe
	 * @throws PlanungException bei ungültigem oder zu großem Schema (400)
	 */
	public function schemaSpeichern(array $eingabe): Schema {
		$schema = Schema::ausArray($eingabe);
		$json = $schema->alsJson();
		if (strlen($json) > Schema::MAX_JSON) {
			$kib = intdiv(Schema::MAX_JSON, 1024);
			$jetzt = (int)ceil(strlen($json) / 1024);
			throw new PlanungException("Das Feldschema ist zu groß zum Speichern ({$jetzt} KiB, höchstens {$kib} KiB). "
				. 'Bitte Felder, Optionen oder lange Bezeichnungen kürzen.');
		}
		// Gegenprobe: genau so, wie es beim nächsten Laden gelesen wird.
		Schema::ausJson($json);
		$this->appConfig->setValueString(Application::APP_ID, Einstellungen::SCHLUESSEL_SCHEMA, $json, lazy: true);
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
		$json = $this->appConfig->getValueString(Application::APP_ID, Einstellungen::SCHLUESSEL_SCHEMA, '', lazy: true);
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
