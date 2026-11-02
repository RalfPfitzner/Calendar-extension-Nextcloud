<?php

declare(strict_types=1);

namespace OCA\CalendarPlanning\Service;

/**
 * Protokollzeilen zu Fehlern, ohne Inhalte (S-KAL-01). Gemeinsam für Controller und Dienste.
 *
 * Nie die Meldung oder die Ausnahme selbst: Fehler aus dem Parser oder der Datenbank zitieren Termintext
 * und Kontaktdaten, und Nextcloud schreibt bei `['exception' => $e]` den Stacktrace samt Argumenten
 * (also auch das iCalendar und die Formularwerte) ins Protokoll. Darum nur Klassen, Codes und die Stelle,
 * den Termin als kurzen Hash: Wer den Termin kennt, kann ihn zuordnen, alle anderen lesen nichts daraus.
 */
final class Protokoll {
	/**
	 * @param string $was Anfang der Zeile nach „Kalender-Planung: “, z. B. „interner Fehler“
	 * @param string|null $termin Objekt-URI, erscheint nur als Hash
	 */
	public static function text(\Throwable $e, ?int $kalender = null, ?string $termin = null, string $was = 'interner Fehler'): string {
		$kette = [];
		for ($fehler = $e; $fehler !== null && count($kette) < 5; $fehler = $fehler->getPrevious()) {
			$kette[] = $fehler::class . ' (Code ' . (int)$fehler->getCode() . ', ' . basename($fehler->getFile()) . ':' . $fehler->getLine() . ')';
		}
		$text = 'Kalender-Planung: ' . $was . ' ' . implode(' <- ', $kette);
		if ($kalender !== null) {
			$text .= ', Kalender ' . $kalender;
		}
		if ($termin !== null) {
			$text .= ', Termin #' . substr(hash('sha256', $termin), 0, 12);
		}
		return $text . '. Inhalte werden absichtlich nicht protokolliert.';
	}
}
