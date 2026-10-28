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
 * Reine Umwandlung iCalendar <-> Array, ohne Nextcloud-Abhängigkeiten, damit sie ohne Server testbar ist.
 * Welche Felder es gibt, sagt das Schema in den Einstellungen; Standardort und Linktext ebenso.
 */
class Planung {
	/** Präfix der eigenen Eigenschaften im VEVENT; der Rest des Namens entsteht aus dem Feldschlüssel. */
	public const PRAEFIX = 'X-CALPLAN-';

	/** Höchstlänge je Feld (Zeichen), damit niemand versehentlich riesige Termine erzeugt. Das Schema darf weniger erlauben. */
	public const MAX_LAENGE = Schema::MAX_LAENGE;

	/** Zeile mit einem Link dieser App auf das Formular eines Termins (siehe KalenderService::link), egal mit welchem Linktext. */
	private const LINK_MUSTER = '#^.*https?://\\S*/apps/calendar_planning/\\?kalender=\\d+&termin=\\S+\\s*$#';

	/** Wert, mit dem ein angekreuztes Ja/Nein-Feld im Termin steht. */
	public const JA = '1';

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
		foreach ($this->einstellungen->schema->felder as $schluessel => $feld) {
			$eigenschaft = self::eigenschaft($schluessel);
			$wert = isset($termin->{$eigenschaft}) ? (string)$termin->{$eigenschaft}->getValue() : '';
			$daten[$schluessel] = $feld['typ'] === 'checkbox' ? $wert !== '' : $wert;
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
			foreach ($this->einstellungen->schema->felder as $schluessel => $feld) {
				$wert = $planung[$schluessel] ?? '';
				if ($feld['typ'] === 'checkbox') {
					$wert = $wert === true ? self::JA : '';
				}
				$this->setzen($ereignis, self::eigenschaft($schluessel), (string)$wert);
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
	 * Prüft und bereinigt Formularwerte gegen das Schema: Typ, Pflicht, Länge, Optionen.
	 *
	 * @param array<string,mixed> $eingabe
	 * @return array<string,mixed> Werte je Schlüssel; Ja/Nein-Felder als bool, alles andere als Text
	 * @throws PlanungException bei ungültiger Eingabe (Code 400, Meldung nennt das Feld)
	 */
	public function pruefen(array $eingabe): array {
		$planung = ['titel' => $this->text($eingabe, 'titel', 'Titel der Veranstaltung', true, self::MAX_LAENGE)];
		if ($planung['titel'] === '') {
			throw new PlanungException('Der Titel der Veranstaltung fehlt');
		}
		$planung['ort'] = $this->text($eingabe, 'ort', 'Ort', false, self::MAX_LAENGE);
		if ($this->einstellungen->ortVorgabe === '') {
			// Ohne Standardort gibt es nur das freie Ortsfeld; leer heißt: kein Ort.
			$planung['ort_extern'] = $planung['ort'] !== '';
		} else {
			$planung['ort_extern'] = ($eingabe['ort_extern'] ?? false) === true;
			if ($planung['ort_extern'] && $planung['ort'] === '') {
				throw new PlanungException('Bei einem externen Ort fehlt die Adresse');
			}
		}
		foreach ($this->einstellungen->schema->felder as $schluessel => $feld) {
			$planung[$schluessel] = $this->feldPruefen($eingabe, $schluessel, $feld);
		}
		return $planung;
	}

	/** @param array{key:string,label:string,typ:string,pflicht:bool,maxlen:int,optionen:list<string>,inListe:bool} $feld */
	private function feldPruefen(array $eingabe, string $schluessel, array $feld): string|bool {
		$label = $feld['label'];
		if ($feld['typ'] === 'checkbox') {
			$wert = $eingabe[$schluessel] ?? false;
			$ja = $wert === true || $wert === self::JA || $wert === 'true';
			if ($feld['pflicht'] && !$ja) {
				throw new PlanungException("„{$label}“ muss angekreuzt sein");
			}
			return $ja;
		}
		$wert = $this->text($eingabe, $schluessel, $label, in_array($feld['typ'], Schema::EINZEILIG, true), $feld['maxlen']);
		if ($wert === '') {
			if ($feld['pflicht']) {
				throw new PlanungException("„{$label}“ fehlt");
			}
			return '';
		}
		switch ($feld['typ']) {
			case 'email':
				if (filter_var($wert, FILTER_VALIDATE_EMAIL) === false) {
					throw new PlanungException("„{$label}“: die E-Mail-Adresse ist ungültig");
				}
				break;
			case 'number':
				if (preg_match('/^-?\d{1,15}([.,]\d{1,6})?$/', $wert) !== 1) {
					throw new PlanungException("„{$label}“ muss eine Zahl sein");
				}
				break;
			case 'select':
				if (!in_array($wert, $feld['optionen'], true)) {
					throw new PlanungException("„{$label}“: „" . mb_substr($wert, 0, 40) . '“ ist keine der Optionen');
				}
				break;
			case 'date':
				$datum = DateTimeImmutable::createFromFormat('!Y-m-d', $wert);
				if ($datum === false || $datum->format('Y-m-d') !== $wert) {
					throw new PlanungException("„{$label}“ muss ein Datum im Format JJJJ-MM-TT sein");
				}
				break;
		}
		return $wert;
	}

	/**
	 * Textwert aus der Eingabe: ohne Steuerzeichen, einzeilig falls verlangt, getrimmt, Länge geprüft.
	 *
	 * @param array<string,mixed> $eingabe
	 */
	private function text(array $eingabe, string $schluessel, string $label, bool $einzeilig, int $maxLaenge): string {
		$wert = $eingabe[$schluessel] ?? '';
		if (!is_string($wert) && !is_int($wert) && !is_float($wert)) {
			throw new PlanungException("„{$label}“ ist kein Text");
		}
		$wert = str_replace("\r\n", "\n", (string)$wert);
		if ($einzeilig) {
			// Umbrüche und Tabs zu einem Leerzeichen, sonstige Steuerzeichen raus.
			$wert = (string)preg_replace('/[\r\n\t]+/', ' ', $wert);
			$wert = (string)preg_replace('/\p{Cc}/u', '', $wert);
		} else {
			// Mehrzeilig: Umbruch und Tab bleiben, alle anderen Steuerzeichen raus.
			$wert = (string)preg_replace('/[^\P{Cc}\n\t]/u', '', $wert);
		}
		$wert = trim($wert);
		if (mb_strlen($wert) > $maxLaenge) {
			throw new PlanungException("„{$label}“ ist zu lang (höchstens {$maxLaenge} Zeichen)");
		}
		return $wert;
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
		// Einen veralteten Link (z. B. nach Umzug der Instanz oder mit altem Linktext) ersetzen statt doppeln:
		// Zeilen mit dem aktuellen Linktext oder mit einem Link dieser App auf das Formular.
		$zeilen = array_filter(
			explode("\n", $beschreibung),
			fn (string $zeile) => !str_starts_with($zeile, $linkText) && preg_match(self::LINK_MUSTER, $zeile) !== 1,
		);
		$beschreibung = rtrim(implode("\n", $zeilen));
		$ereignis->DESCRIPTION = ($beschreibung === '' ? '' : $beschreibung . "\n\n") . $linkText . $link;
	}
}
