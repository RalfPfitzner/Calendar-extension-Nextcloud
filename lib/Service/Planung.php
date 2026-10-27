<?php

declare(strict_types=1);

namespace OCA\CalendarPlanning\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Reader;

/**
 * Zuordnung der Planungsfelder zu Eigenschaften im Kalendertermin.
 *
 * Reine Umwandlung iCalendar <-> Array, ohne Nextcloud-Abhängigkeiten,
 * damit sie ohne Server testbar ist. Standardort und Linktext kommen aus den Einstellungen.
 */
class Planung {
	/** Präfix der eigenen Eigenschaften im VEVENT; der Rest des Namens entsteht aus dem Feldschlüssel. */
	public const PRAEFIX = 'X-CALPLAN-';

	/**
	 * Feldschlüssel => X-Eigenschaft im Termin.
	 * Die Schlüssel sind Teil des Datenformats: nie umbenennen, nur ergänzen.
	 */
	public const FELDER = [
		'zustaendig' => 'X-CALPLAN-ZUSTAENDIG',
		'zufahrt' => 'X-CALPLAN-ZUFAHRT',
		'raum' => 'X-CALPLAN-RAUM',
		'kontakt_organisation' => 'X-CALPLAN-KONTAKT-ORGANISATION',
		'kontakt_name' => 'X-CALPLAN-KONTAKT-NAME',
		'kontakt_telefon' => 'X-CALPLAN-KONTAKT-TELEFON',
		'kontakt_email' => 'X-CALPLAN-KONTAKT-EMAIL',
		'kontakt_rolle' => 'X-CALPLAN-KONTAKT-ROLLE',
		'zielgruppe' => 'X-CALPLAN-ZIELGRUPPE',
		'teilnehmende' => 'X-CALPLAN-TEILNEHMENDE',
		'anforderungen' => 'X-CALPLAN-ANFORDERUNGEN',
		'inhalte' => 'X-CALPLAN-INHALTE',
		'ausstattung' => 'X-CALPLAN-AUSSTATTUNG',
		'material' => 'X-CALPLAN-MATERIAL',
		'betreuung' => 'X-CALPLAN-BETREUUNG',
		'hinweise' => 'X-CALPLAN-HINWEISE',
	];

	/** Höchstlänge je Feld (Zeichen), damit niemand versehentlich riesige Termine erzeugt. */
	public const MAX_LAENGE = 5000;

	/**
	 * Felder, die im Formular einzeilig sind (muss zu `mehrzeilig` in src/felder.js passen).
	 * Zeilenumbrüche darin werden zu Leerzeichen, damit kein Titel mit Umbruch in der Kalender-App landet.
	 */
	public const EINZEILIG = [
		'titel', 'zustaendig', 'kontakt_organisation', 'kontakt_name', 'kontakt_telefon',
		'kontakt_email', 'kontakt_rolle', 'zielgruppe', 'teilnehmende',
	];

	public function __construct(
		private Einstellungen $einstellungen = new Einstellungen(),
	) {
	}

	/** Name der X-Eigenschaft zu einem Feldschlüssel: Präfix plus Schlüssel in Großbuchstaben, `_` wird `-`. */
	public static function eigenschaft(string $schluessel): string {
		return self::PRAEFIX . strtoupper(str_replace('_', '-', $schluessel));
	}

	/**
	 * Liest Termindaten und Planung aus einem Kalenderobjekt.
	 *
	 * @param DateTimeInterface|null $von  mit $bis: bei Serien den ersten Termin im Zeitraum ermitteln
	 * @return array<string,mixed>|null null, wenn das Objekt kein Termin ist
	 */
	public function lesen(string $ics, ?DateTimeInterface $von = null, ?DateTimeInterface $bis = null): ?array {
		$kalender = Reader::read($ics);
		if (!$kalender instanceof VCalendar) {
			return null;
		}
		$termin = $this->hauptTermin($kalender);
		if ($termin === null) {
			return null;
		}

		$ganztags = isset($termin->DTSTART) && !$termin->DTSTART->hasTime();
		$beginn = isset($termin->DTSTART) ? $termin->DTSTART->getDateTime() : null;
		$ende = $this->ende($termin);
		$serie = isset($termin->RRULE);

		if ($serie && $von !== null && $bis !== null) {
			$erster = $this->ersterImZeitraum($kalender, $von, $bis);
			if ($erster !== null) {
				[$beginn, $ende] = $erster;
			}
		}

		$vorgabe = $this->einstellungen->ortVorgabe;
		$ort = trim((string)($termin->LOCATION ?? ''));
		$daten = [
			'uid' => (string)($termin->UID ?? ''),
			'titel' => (string)($termin->SUMMARY ?? ''),
			'beginn' => $beginn?->format(DATE_ATOM),
			'ende' => $ende?->format(DATE_ATOM),
			'ganztags' => $ganztags,
			'serie' => $serie,
			'ort_extern' => $ort !== '' && $ort !== $vorgabe,
			'ort' => $ort === '' ? $vorgabe : $ort,
			'beschreibung' => (string)($termin->DESCRIPTION ?? ''),
		];
		$geplant = false;
		foreach (self::FELDER as $feld => $eigenschaft) {
			$wert = isset($termin->{$eigenschaft}) ? $termin->{$eigenschaft}->getValue() : '';
			$daten[$feld] = $wert;
			$geplant = $geplant || $wert !== '';
		}
		$daten['geplant'] = $geplant;
		return $daten;
	}

	/**
	 * Schreibt die Planung in das Kalenderobjekt und gibt das neue iCalendar zurück.
	 *
	 * Termin-Zeiten, Teilnehmende und alle fremden Eigenschaften bleiben unverändert.
	 *
	 * @param array<string,mixed> $planung geprüfte Formularwerte (siehe pruefen())
	 * @param string|null $link Adresse für „Planung öffnen“ in der Beschreibung, null = nicht ergänzen
	 */
	public function schreiben(string $ics, array $planung, ?string $link, ?DateTimeInterface $jetzt = null): string {
		$kalender = Reader::read($ics);
		if (!$kalender instanceof VCalendar) {
			throw new PlanungException('Kein Kalenderobjekt');
		}
		$termin = $this->hauptTermin($kalender);
		if ($termin === null) {
			throw new PlanungException('Kein Termin im Kalenderobjekt');
		}
		$jetzt = DateTimeImmutable::createFromInterface($jetzt ?? new DateTimeImmutable())->setTimezone(new DateTimeZone('UTC'));

		$alterTitel = (string)($termin->SUMMARY ?? '');
		$alterOrt = (string)($termin->LOCATION ?? '');
		$ort = $planung['ort_extern'] ? $planung['ort'] : $this->einstellungen->ortVorgabe;

		foreach ($kalender->select('VEVENT') as $ereignis) {
			$hauptTermin = $ereignis === $termin;
			// Ausnahmen einer Serie übernehmen Titel und Ort nur, wenn sie noch die alten Werte der Serie tragen.
			if ($hauptTermin || (string)($ereignis->SUMMARY ?? '') === $alterTitel) {
				$this->setzen($ereignis, 'SUMMARY', $planung['titel']);
			}
			if ($hauptTermin || (string)($ereignis->LOCATION ?? '') === $alterOrt) {
				$this->setzen($ereignis, 'LOCATION', $ort);
			}
			foreach (self::FELDER as $feld => $eigenschaft) {
				$this->setzen($ereignis, $eigenschaft, $planung[$feld]);
			}
			if ($link !== null) {
				$this->linkEinfuegen($ereignis, $link);
			}
			$ereignis->{'LAST-MODIFIED'} = $jetzt;
			$ereignis->DTSTAMP = $jetzt;
		}
		return $kalender->serialize();
	}

	/**
	 * Prüft und bereinigt Formularwerte.
	 *
	 * @param array<string,mixed> $eingabe
	 * @return array<string,mixed>
	 * @throws PlanungException bei ungültiger Eingabe
	 */
	public function pruefen(array $eingabe): array {
		$text = function (string $feld) use ($eingabe): string {
			$wert = $eingabe[$feld] ?? '';
			if (!is_string($wert) && !is_int($wert)) {
				throw new PlanungException("Feld „{$feld}“ ist kein Text");
			}
			$wert = str_replace("\r\n", "\n", (string)$wert);
			if (in_array($feld, self::EINZEILIG, true)) {
				// Umbrüche und Tabs zu einem Leerzeichen, sonstige Steuerzeichen raus.
				$wert = (string)preg_replace('/[\r\n\t]+/', ' ', $wert);
				$wert = (string)preg_replace('/\p{Cc}/u', '', $wert);
			} else {
				// Mehrzeilig: Umbruch und Tab bleiben, alle anderen Steuerzeichen raus.
				$wert = (string)preg_replace('/[^\P{Cc}\n\t]/u', '', $wert);
			}
			$wert = trim($wert);
			if (mb_strlen($wert) > self::MAX_LAENGE) {
				throw new PlanungException("Feld „{$feld}“ ist zu lang (höchstens " . self::MAX_LAENGE . ' Zeichen)');
			}
			return $wert;
		};

		$planung = ['titel' => $text('titel')];
		if ($planung['titel'] === '') {
			throw new PlanungException('Der Titel der Veranstaltung fehlt');
		}
		$planung['ort'] = $text('ort');
		if ($this->einstellungen->ortVorgabe === '') {
			// Ohne Standardort gibt es nur das freie Ortsfeld; leer heißt: kein Ort.
			$planung['ort_extern'] = $planung['ort'] !== '';
		} else {
			$planung['ort_extern'] = ($eingabe['ort_extern'] ?? false) === true;
			if ($planung['ort_extern'] && $planung['ort'] === '') {
				throw new PlanungException('Bei einem externen Ort fehlt die Adresse');
			}
		}
		foreach (array_keys(self::FELDER) as $feld) {
			$planung[$feld] = $text($feld);
		}
		if ($planung['kontakt_email'] !== '' && filter_var($planung['kontakt_email'], FILTER_VALIDATE_EMAIL) === false) {
			throw new PlanungException('Die E-Mail-Adresse des Kontakts ist ungültig');
		}
		return $planung;
	}

	/** Der Termin ohne RECURRENCE-ID (bei Serien die Serie selbst). */
	private function hauptTermin(VCalendar $kalender): ?VEvent {
		$erster = null;
		foreach ($kalender->select('VEVENT') as $ereignis) {
			if (!isset($ereignis->{'RECURRENCE-ID'})) {
				return $ereignis;
			}
			$erster ??= $ereignis;
		}
		// Nur Ausnahmen ohne Serie (kommt bei Einladungen vor): die erste nehmen.
		return $erster;
	}

	private function ende(VEvent $termin): ?DateTimeImmutable {
		if (isset($termin->DTEND)) {
			return $termin->DTEND->getDateTime();
		}
		if (isset($termin->DTSTART, $termin->DURATION)) {
			return $termin->DTSTART->getDateTime()->add($termin->DURATION->getDateInterval());
		}
		return null;
	}

	/** @return array{0:DateTimeImmutable,1:?DateTimeImmutable}|null */
	private function ersterImZeitraum(VCalendar $kalender, DateTimeInterface $von, DateTimeInterface $bis): ?array {
		try {
			$aufgeloest = (clone $kalender)->expand($von, $bis, $von->getTimezone());
		} catch (\Throwable) {
			return null;
		}
		$erster = null;
		foreach ($aufgeloest->select('VEVENT') as $ereignis) {
			$beginn = $ereignis->DTSTART->getDateTime();
			if ($erster === null || $beginn < $erster[0]) {
				$erster = [$beginn, $this->ende($ereignis)];
			}
		}
		if ($erster === null) {
			return null;
		}
		// expand() liefert UTC; wie bei Einzelterminen in der Zone des Zeitraums ausgeben.
		$zone = $von->getTimezone();
		return [$erster[0]->setTimezone($zone), $erster[1]?->setTimezone($zone)];
	}

	private function setzen(VEvent $ereignis, string $eigenschaft, string $wert): void {
		unset($ereignis->{$eigenschaft});
		if ($wert !== '') {
			$ereignis->add($eigenschaft, $wert);
		}
	}

	private function linkEinfuegen(VEvent $ereignis, string $link): void {
		$beschreibung = (string)($ereignis->DESCRIPTION ?? '');
		if (str_contains($beschreibung, $link)) {
			return;
		}
		$linkText = $this->einstellungen->linkText;
		// Einen veralteten Link (z. B. nach Umzug der Instanz) ersetzen statt doppeln.
		$zeilen = array_filter(
			explode("\n", $beschreibung),
			fn (string $zeile) => !str_starts_with($zeile, $linkText),
		);
		$beschreibung = rtrim(implode("\n", $zeilen));
		$ereignis->DESCRIPTION = ($beschreibung === '' ? '' : $beschreibung . "\n\n") . $linkText . $link;
	}
}
