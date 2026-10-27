<?php

declare(strict_types=1);

namespace OCA\CalendarPlanning\Service;

/**
 * Einstellungen der App, wie ein Admin sie gesetzt hat (siehe EinstellungenLader).
 *
 * Reiner Wertehalter ohne Nextcloud-Abhängigkeit, damit Planung ohne Server testbar bleibt.
 * Die Werte sind hier bereits bereinigt: einzeilig, ohne Steuerzeichen, in der Länge gedeckelt.
 */
class Einstellungen {
	/** Schlüssel in der App-Konfiguration (`occ config:app:set calendar_planning <schlüssel> --value …`). */
	public const SCHLUESSEL_ORT = 'ort_vorgabe';
	public const SCHLUESSEL_KALENDER_MUSTER = 'kalender_muster';
	public const SCHLUESSEL_LINK_TEXT = 'link_text';

	public const LINK_TEXT_VORGABE = 'Planung öffnen: ';

	/** Höchstlängen der Einstellungen (Zeichen); Admin-Eingaben gelten trotzdem als fremde Eingaben. */
	public const MAX_ORT = 500;
	public const MAX_KALENDER_MUSTER = 100;
	public const MAX_LINK_TEXT = 100;

	/** Vorgabe für den Ort; leer heißt: kein Standardort, das Formular hat ein freies Ortsfeld. */
	public readonly string $ortVorgabe;

	/** Namensfragment, nach dem die Kalenderauswahl vorbelegt wird; leer heißt: erster Kalender. */
	public readonly string $kalenderMuster;

	/** Erkennungszeichen des Links in der Beschreibung, nie leer. */
	public readonly string $linkText;

	public function __construct(string $ortVorgabe = '', string $kalenderMuster = '', string $linkText = self::LINK_TEXT_VORGABE) {
		$this->ortVorgabe = self::einzeilig($ortVorgabe, self::MAX_ORT);
		$this->kalenderMuster = self::einzeilig($kalenderMuster, self::MAX_KALENDER_MUSTER);
		$linkText = self::einzeilig($linkText, self::MAX_LINK_TEXT);
		// Ohne Erkennungszeichen würde linkEinfuegen() jede Zeile der Beschreibung für einen alten Link halten.
		$this->linkText = $linkText === '' ? self::LINK_TEXT_VORGABE : $linkText . ' ';
	}

	/** Umbrüche und Tabs zu Leerzeichen, sonstige Steuerzeichen raus, Länge deckeln. */
	public static function einzeilig(string $wert, int $maxLaenge): string {
		$wert = (string)preg_replace('/[\r\n\t]+/', ' ', $wert);
		$wert = (string)preg_replace('/\p{Cc}/u', '', $wert);
		return mb_substr(trim($wert), 0, $maxLaenge);
	}

	/** Für die Oberfläche; der Linktext bleibt serverseitig. */
	public function fuerOberflaeche(): array {
		return [
			'ort_vorgabe' => $this->ortVorgabe,
		];
	}
}
