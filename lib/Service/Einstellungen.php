<?php

declare(strict_types=1);

namespace OCA\CalendarPlanning\Service;

/**
 * Einstellungen der App, wie ein Admin sie gesetzt hat (siehe EinstellungenSpeicher).
 *
 * Reiner Wertehalter ohne Nextcloud-Abhängigkeit, damit Planung ohne Server testbar bleibt.
 * Die Werte sind hier bereits bereinigt: einzeilig, ohne Steuer-, unsichtbare und Richtungszeichen, in der Länge gedeckelt.
 */
class Einstellungen {
	/** Schlüssel in der App-Konfiguration (`occ config:app:set calendar_planning <schlüssel> --value …`). */
	public const SCHLUESSEL_ORT = 'ort_vorgabe';
	public const SCHLUESSEL_KALENDER_MUSTER = 'kalender_muster';
	public const SCHLUESSEL_LINK_TEXT = 'link_text';
	public const SCHLUESSEL_SCHEMA = 'schema';

	public const LINK_TEXT_VORGABE = 'Planung öffnen: ';

	/** Höchstlängen der Einstellungen (Zeichen); Admin-Eingaben gelten trotzdem als fremde Eingaben. */
	public const MAX_ORT = 500;
	public const MAX_KALENDER_MUSTER = 100;
	public const MAX_LINK_TEXT = 100;

	/** Vorgabe für den Ort; leer heißt: kein Standardort, das Formular hat ein freies Ortsfeld. */
	public readonly string $ortVorgabe;

	/** Namensfragment, nach dem die Kalenderauswahl vorbelegt wird; leer heißt: erster Kalender. */
	public readonly string $kalenderMuster;

	/** Erkennungszeichen des Links in der Beschreibung, nie leer, endet mit einem Leerzeichen. */
	public readonly string $linkText;

	/** Feldschema des Planungsformulars. */
	public readonly Schema $schema;

	public function __construct(string $ortVorgabe = '', string $kalenderMuster = '', string $linkText = self::LINK_TEXT_VORGABE, ?Schema $schema = null) {
		$this->ortVorgabe = self::einzeilig($ortVorgabe, self::MAX_ORT);
		$this->kalenderMuster = self::einzeilig($kalenderMuster, self::MAX_KALENDER_MUSTER);
		$linkText = self::einzeilig($linkText, self::MAX_LINK_TEXT);
		// Ohne Erkennungszeichen würde linkEinfuegen() jede Zeile der Beschreibung für einen alten Link halten.
		$this->linkText = $linkText === '' ? self::LINK_TEXT_VORGABE : $linkText . ' ';
		$this->schema = $schema ?? Schema::vorgabe();
	}

	/**
	 * Unsichtbare Zeichen und Richtungszeichen (S-KAL-06): Mit ihnen sehen Texte anders aus, als sie sind
	 * (umgedrehte Reihenfolge, versteckte Zeichen in scheinbar gleichen Werten).
	 * U+00AD weiches Trennzeichen, U+034F unsichtbarer Graphem-Verbinder, U+061C arabische Richtungsmarke,
	 * U+115F/U+1160/U+3164/U+FFA0 Hangul-Füllzeichen (sehen aus wie nichts oder ein Leerzeichen), U+180E mongolischer
	 * Vokaltrenner, U+200B Nullbreite-Leerzeichen, U+200E/U+200F Richtungsmarken, U+2028/U+2029 Zeilen- und
	 * Absatztrenner (Umbrüche, die kaum ein Programm als solche zeigt; einzeilig() und Planung machen daraus vorher
	 * Leerzeichen bzw. Umbrüche), U+202A–U+202E Einbettungen und Überschreibungen, U+2060–U+2064 Wortverbinder und
	 * unsichtbare Operatoren, U+2066–U+2069 Isolate, U+FEFF Byte-Order-Mark, U+E0000–U+E007F Tag-Zeichen (unsichtbar,
	 * tragen versteckten Text).
	 * Bewusst nicht: U+200C/U+200D (Nullbreite-(Nicht-)Verbinder). Sie gehören zu Emoji-Folgen und zur Schreibung
	 * z. B. im Persischen und in indischen Schriften und drehen keine Reihenfolge um. Die Tag-Zeichen kommen zwar in
	 * Flaggen-Emoji einzelner Landesteile vor (🏴 plus Tags); die Flagge fällt dann auf die schwarze Fahne zurück.
	 */
	public const UNSICHTBAR = '/[\x{00AD}\x{034F}\x{061C}\x{115F}\x{1160}\x{180E}\x{200B}\x{200E}\x{200F}\x{2028}\x{2029}\x{202A}-\x{202E}'
		. '\x{2060}-\x{2064}\x{2066}-\x{2069}\x{3164}\x{FEFF}\x{FFA0}\x{E0000}-\x{E007F}]/u';

	/** Zeilen- und Absatztrenner (U+2028, U+2029). */
	public const TRENNER = '/[\x{2028}\x{2029}]/u';

	/** Unsichtbare und Richtungszeichen entfernen (siehe UNSICHTBAR). */
	public static function ohneUnsichtbare(string $wert): string {
		return (string)preg_replace(self::UNSICHTBAR, '', $wert);
	}

	/** Umbrüche und Tabs zu Leerzeichen, sonstige Steuerzeichen sowie unsichtbare und Richtungszeichen raus, Länge deckeln. */
	public static function einzeilig(string $wert, int $maxLaenge): string {
		$wert = (string)preg_replace('/[\r\n\t\x{2028}\x{2029}]+/u', ' ', $wert);
		$wert = (string)preg_replace('/\p{Cc}/u', '', $wert);
		$wert = self::ohneUnsichtbare($wert);
		return mb_substr(trim($wert), 0, $maxLaenge);
	}

	/** Für die Oberfläche aller Konten: Standardort und Feldschema; der Linktext bleibt serverseitig. */
	public function fuerOberflaeche(): array {
		return [
			'ort_vorgabe' => $this->ortVorgabe,
			'schema' => $this->schema->alsArray(),
		];
	}

	/** Die drei Vorgaben für die Admin-Seite. */
	public function vorgaben(): array {
		return [
			'ort_vorgabe' => $this->ortVorgabe,
			'kalender_muster' => $this->kalenderMuster,
			'link_text' => rtrim($this->linkText),
		];
	}
}
