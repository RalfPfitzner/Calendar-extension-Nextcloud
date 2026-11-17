<?php

declare(strict_types=1);

namespace OCA\CalendarPlanning\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Parameter;
use Sabre\VObject\Property\ICalendar\DateTime;
use Sabre\VObject\Reader;
use Sabre\VObject\Recur\EventIterator;
use Sabre\VObject\Recur\NoInstancesException;

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

	/**
	 * Eigenschaft mit der zuletzt in die Beschreibung geschriebenen Link-Zeile (Linktext plus Adresse). Nur eine Zeile
	 * mit genau diesem Inhalt gilt später als eigener Link; der Schlüssel `link` ist dafür in Schema::RESERVIERT.
	 */
	public const EIGENSCHAFT_LINK = self::PRAEFIX . 'LINK';

	/** Adresse dieser App auf das Formular eines Termins (siehe KalenderService::link), als ganzer Text. */
	private const APP_ADRESSE = '#^https?://\S+/apps/calendar_planning/\?kalender=\d+&termin=\S+\z#';

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
	 * Mit Zeitraum ($von und $bis) für die Liste: Der Termin muss im Zeitraum liegen oder hineinreichen (wie der
	 * time-range-Filter von CalDAV), sonst kommt null. Bei Serien steht als Beginn und Ende ein Termin der Serie im
	 * Zeitraum, nie der erste der Serie überhaupt: der nächste ab $jetzt, wenn es ihn im Zeitraum gibt (U-KAL-16),
	 * sonst der erste im Zeitraum.
	 *
	 * @param DateTimeInterface|null $von  Beginn des Zeitraums (mit $bis)
	 * @param DateTimeInterface|null $bis  Ende des Zeitraums, ausschließlich
	 * @param DateTimeZone|null $zone Zone für „Floating“-Zeiten (ohne TZID und ohne Z), also die Ortszeit, in der
	 *   die Oberfläche anzeigt; ohne Angabe die Zone von $von, sonst die PHP-Vorgabe (in Nextcloud UTC)
	 * @param DateTimeInterface|null $jetzt bei Serien im Zeitraum: ab hier den nächsten Termin zeigen
	 * @return array<string,mixed>|null null, wenn das Objekt kein Termin ist oder nicht im Zeitraum liegt
	 */
	public function lesen(string $ics, ?DateTimeInterface $von = null, ?DateTimeInterface $bis = null, ?DateTimeZone $zone = null,
		?DateTimeInterface $jetzt = null): ?array {
		$kalender = Reader::read($ics);
		if (!$kalender instanceof VCalendar) {
			return null;
		}
		$termin = $this->hauptTermin($kalender);
		if ($termin === null) {
			return null;
		}

		$ganztags = isset($termin->DTSTART) && !$termin->DTSTART->hasTime();
		$zone ??= $von?->getTimezone();
		// Floating-Zeiten gelten als Ortszeit (wie in der Kalender-App), nicht als UTC (Q-KAL-05). Zeiten mit TZID
		// oder Z bleiben, wie sie sind; Ganztags-Termine bleiben UTC-Mitternacht, die Oberfläche liest daraus das Datum.
		$ortszeit = $ganztags ? null : $zone;
		$beginn = isset($termin->DTSTART) ? $termin->DTSTART->getDateTime($ortszeit) : null;
		$ende = $this->ende($termin, $ortszeit);
		$serie = isset($termin->RRULE);

		if ($von !== null && $bis !== null) {
			$zone ??= $von->getTimezone();
			if ($serie) {
				$imZeitraum = $this->serienTermin($kalender, $termin, $von, $bis, $zone, $jetzt);
				if ($imZeitraum === null) {
					return null;
				}
				[$beginn, $ende] = $imZeitraum;
			} elseif (!$this->einzelnImZeitraum($kalender, $von, $bis, $zone)) {
				return null;
			}
		}

		$vorgabe = $this->einstellungen->ortVorgabe;
		$ort = trim((string)($termin->LOCATION ?? ''));
		$einladung = isset($termin->ATTENDEE);
		$uhrzeitZone = $zone ?? new DateTimeZone('UTC');
		$daten = [
			'uid' => (string)($termin->UID ?? ''),
			'titel' => (string)($termin->SUMMARY ?? ''),
			'beginn' => $beginn?->format(DATE_ATOM),
			'ende' => $ende?->format(DATE_ATOM),
			'ganztags' => $ganztags,
			'serie' => $serie,
			// Uhrzeit im Formular (Wunsch vom 16.11.2026, docs/KONZEPT.md §2): Beginn und Ende als HH:MM in der Ortszeit.
			'uhrzeit_beginn' => $ganztags || $beginn === null ? '' : $beginn->setTimezone($uhrzeitZone)->format('H:i'),
			'uhrzeit_ende' => $ganztags || $ende === null ? '' : $ende->setTimezone($uhrzeitZone)->format('H:i'),
			'zeit_aenderbar' => $this->zeitAenderbar($kalender, $termin),
			'einladung' => $einladung,
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
	 * Teilnehmende und alle fremden Eigenschaften bleiben unverändert. Die Uhrzeit von Beginn und Ende ändert sich nur,
	 * wenn sie in $planung anders steht als im Termin (siehe zeitPruefen); das Datum bleibt immer.
	 *
	 * @param array<string,mixed> $planung geprüfte Formularwerte (siehe pruefen())
	 * @param string|null $link Adresse für „Planung öffnen“ in der Beschreibung, null = nicht ergänzen
	 * @param DateTimeZone|null $zone Ortszeit der Oberfläche, in der die Uhrzeiten gelten; ohne Angabe UTC
	 */
	public function schreiben(string $ics, array $planung, ?string $link, ?DateTimeInterface $jetzt = null,
		?DateTimeZone $zone = null): string {
		$kalender = Reader::read($ics);
		if (!$kalender instanceof VCalendar) {
			throw new PlanungException('Kein Kalenderobjekt');
		}
		$termin = $this->hauptTermin($kalender);
		if ($termin === null) {
			throw new PlanungException('Kein Termin im Kalenderobjekt');
		}
		$this->optionenPruefen($termin, $planung);
		// Erst alles prüfen, dann ändern: Eine ungültige Uhrzeit darf nichts halb geschrieben zurücklassen.
		$zeiten = $this->zeitPruefen($kalender, $termin, $planung, $zone ?? new DateTimeZone('UTC'));
		if ($zeiten !== null) {
			$this->zeitSetzen($termin, $zeiten[0], $zeiten[1], $zone ?? new DateTimeZone('UTC'));
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
	 * Prüft und bereinigt Formularwerte gegen das Schema: Typ, Pflicht, Länge.
	 *
	 * Ob ein Auswahlwert eine der Optionen ist, prüft erst schreiben(), weil nur dort der gespeicherte Wert bekannt ist:
	 * Ein Wert, der schon so im Termin steht, bleibt gültig, auch wenn der Admin die Option inzwischen entfernt hat
	 * (Q-KAL-09).
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
		foreach (self::UHRZEITEN as $schluessel => $label) {
			$planung[$schluessel] = $this->uhrzeit($eingabe, $schluessel, $label);
		}
		foreach ($this->einstellungen->schema->felder as $schluessel => $feld) {
			$planung[$schluessel] = $this->feldPruefen($eingabe, $schluessel, $feld);
		}
		return $planung;
	}

	/** Uhrzeit-Felder des Formulars mit ihrer Bezeichnung (Schlüssel in Schema::RESERVIERT). */
	public const UHRZEITEN = ['uhrzeit_beginn' => 'Uhrzeit Beginn', 'uhrzeit_ende' => 'Uhrzeit Ende'];

	/**
	 * Uhrzeit als HH:MM, '' für leer, null wenn die Eingabe sie nicht enthält (dann bleibt die Zeit des Termins).
	 * Sekunden (HH:MM:SS, manche Browser) werden abgeschnitten.
	 *
	 * @param array<string,mixed> $eingabe
	 */
	private function uhrzeit(array $eingabe, string $schluessel, string $label): ?string {
		$wert = $eingabe[$schluessel] ?? null;
		if ($wert === null) {
			return null;
		}
		if (!is_string($wert)) {
			throw new PlanungException("„{$label}“ ist kein Text");
		}
		$wert = trim($wert);
		if ($wert === '') {
			return '';
		}
		if (preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?\z/', $wert) !== 1) {
			throw new PlanungException("„{$label}“ muss eine Uhrzeit im Format HH:MM sein");
		}
		return substr($wert, 0, 5);
	}

	/**
	 * Ob die Uhrzeit im Formular geändert werden darf: nur bei einem einzelnen Termin mit Uhrzeit, also nicht bei
	 * Serien (die Zeit gälte für alle Termine), Ausnahmen einer Serie und ganztägigen Terminen, und nicht bei Terminen
	 * mit Teilnehmenden. Die App schreibt am CalDAV-Server vorbei, Eingeladene bekämen von einer neuen Uhrzeit nichts
	 * mit; das übernimmt die Kalender-App.
	 */
	private function zeitAenderbar(VCalendar $kalender, VEvent $termin): bool {
		return count($kalender->select('VEVENT')) === 1
			&& isset($termin->DTSTART) && $termin->DTSTART->hasTime()
			&& !isset($termin->RRULE) && !isset($termin->RDATE) && !isset($termin->{'RECURRENCE-ID'})
			&& !isset($termin->ATTENDEE);
	}

	/**
	 * Neuer Beginn und neues Ende aus den Uhrzeiten der Planung, null wenn sich nichts ändert. Das Datum bleibt das des
	 * Termins in $zone (bei mehrtägigen Terminen auch das des Endes).
	 *
	 * @param array<string,mixed> $planung
	 * @return array{0:DateTimeImmutable,1:?DateTimeImmutable}|null
	 * @throws PlanungException (Code 400) bei Uhrzeiten, die sich hier nicht setzen lassen
	 */
	private function zeitPruefen(VCalendar $kalender, VEvent $termin, array $planung, DateTimeZone $zone): ?array {
		$neuBeginn = $planung['uhrzeit_beginn'] ?? null;
		$neuEnde = $planung['uhrzeit_ende'] ?? null;
		if (!is_string($neuBeginn) && !is_string($neuEnde)) {
			return null;
		}
		$ganztags = !isset($termin->DTSTART) || !$termin->DTSTART->hasTime();
		$beginn = $ganztags ? null : $termin->DTSTART->getDateTime($zone)->setTimezone($zone);
		$ende = $ganztags ? null : $this->ende($termin, $zone)?->setTimezone($zone);
		$neuBeginn = is_string($neuBeginn) ? $neuBeginn : (string)$beginn?->format('H:i');
		$neuEnde = is_string($neuEnde) ? $neuEnde : (string)$ende?->format('H:i');
		if ($neuBeginn === (string)$beginn?->format('H:i') && $neuEnde === (string)$ende?->format('H:i')) {
			return null;
		}
		if ($beginn === null || !$this->zeitAenderbar($kalender, $termin)) {
			throw new PlanungException('Die Uhrzeit dieses Termins lässt sich nur in der Kalender-App ändern');
		}
		[$labelBeginn, $labelEnde] = array_values(self::UHRZEITEN);
		if ($neuBeginn === '') {
			throw new PlanungException("„{$labelBeginn}“ fehlt");
		}
		$neuerBeginn = self::amTag($beginn, $neuBeginn, $zone, $labelBeginn);
		if ($neuEnde === '') {
			if ($ende !== null) {
				throw new PlanungException("„{$labelEnde}“ fehlt");
			}
			return [$neuerBeginn, null];
		}
		// Ohne bisheriges Ende endet der Termin am Tag des Beginns.
		$neuesEnde = self::amTag($ende ?? $beginn, $neuEnde, $zone, $labelEnde);
		if ($neuesEnde <= $neuerBeginn) {
			throw new PlanungException("„{$labelEnde}“ muss nach dem Beginn liegen");
		}
		return [$neuerBeginn, $neuesEnde];
	}

	/** $uhrzeit (HH:MM) am Tag von $tag in $zone; 400, wenn es die Uhrzeit dort nicht gibt (Sommerzeit-Lücke). */
	private static function amTag(DateTimeImmutable $tag, string $uhrzeit, DateTimeZone $zone, string $label): DateTimeImmutable {
		$zeit = new DateTimeImmutable($tag->setTimezone($zone)->format('Y-m-d') . ' ' . $uhrzeit, $zone);
		if ($zeit->format('H:i') !== $uhrzeit) {
			throw new PlanungException("„{$label}“: {$uhrzeit} Uhr gibt es an diesem Tag wegen der Zeitumstellung nicht");
		}
		return $zeit;
	}

	/**
	 * Schreibt neuen Beginn und neues Ende in der Form, in der der Termin sie hatte: mit derselben TZID, in UTC oder
	 * ohne Zone (Floating, gilt in $zone). DURATION wird zu DTEND. SEQUENCE zählt hoch (RFC 5545 §3.8.7.4).
	 */
	private function zeitSetzen(VEvent $termin, DateTimeImmutable $beginn, ?DateTimeImmutable $ende, DateTimeZone $zone): void {
		$vorlage = $termin->DTSTART;
		$endeVorlage = isset($termin->DTEND) ? $termin->DTEND : $vorlage;
		self::zeitSchreiben($vorlage, $beginn, $vorlage, $zone);
		if ($ende !== null) {
			$form = clone $endeVorlage;
			unset($termin->DURATION);
			if (!isset($termin->DTEND)) {
				$termin->add('DTEND', $ende);
			}
			self::zeitSchreiben($termin->DTEND, $ende, $form, $zone);
		}
		$termin->SEQUENCE = (int)(string)($termin->SEQUENCE ?? '0') + 1;
	}

	/** Setzt $zeit in $eigenschaft so, wie $vorlage geschrieben war (TZID, UTC oder Floating). */
	private static function zeitSchreiben(DateTime $eigenschaft, DateTimeImmutable $zeit, DateTime $vorlage, DateTimeZone $zone): void {
		if ($vorlage->isFloating()) {
			$wanduhr = new DateTimeImmutable($zeit->setTimezone($zone)->format('Y-m-d H:i:s'), new DateTimeZone('UTC'));
			$eigenschaft->setDateTime($wanduhr, true);
			return;
		}
		$parameter = $vorlage->offsetGet('TZID');
		$tzid = $parameter instanceof Parameter ? (string)$parameter->getValue() : null;
		$eigenschaft->setDateTime($zeit->setTimezone($tzid === null ? new DateTimeZone('UTC') : $vorlage->getDateTime()->getTimezone()));
		if ($tzid !== null) {
			// Den Namen der Zone so lassen, wie er war (z. B. „W. Europe Standard Time“ aus Outlook mit eigener VTIMEZONE).
			$eigenschaft['TZID'] = $tzid;
		}
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
				if (preg_match('/^-?\d{1,15}([.,]\d{1,6})?\z/', $wert) !== 1) {
					throw new PlanungException("„{$label}“ muss eine Zahl sein");
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
	 * Auswahlwerte müssen eine der Optionen sein oder unverändert so im Termin stehen (Q-KAL-09): Entfernt der Admin
	 * eine Option, lässt sich ein Termin mit dem alten Wert weiter speichern, ein neu gewählter Wert muss aber in der
	 * Liste stehen.
	 *
	 * @param array<string,mixed> $planung
	 * @throws PlanungException (Code 400), wenn ein Wert weder Option noch der gespeicherte Wert ist
	 */
	private function optionenPruefen(VEvent $termin, array $planung): void {
		foreach ($this->einstellungen->schema->felder as $schluessel => $feld) {
			$wert = $planung[$schluessel] ?? '';
			if ($feld['typ'] !== 'select' || !is_string($wert) || $wert === '' || in_array($wert, $feld['optionen'], true)) {
				continue;
			}
			$eigenschaft = self::eigenschaft($schluessel);
			$roh = isset($termin->{$eigenschaft}) ? (string)$termin->{$eigenschaft}->getValue() : '';
			// Den gespeicherten Wert so bereinigen wie die Eingabe (Steuerzeichen, Unsichtbares, Leerraum am Rand),
			// sonst passt ein Altwert wie „Kurs “ nie zur bereinigten Eingabe „Kurs“. Die Länge zählt hier nicht.
			$gespeichert = $this->text([$schluessel => $roh], $schluessel, $feld['label'], true, PHP_INT_MAX);
			if ($wert !== $gespeichert) {
				throw new PlanungException("„{$feld['label']}“: „" . mb_substr($wert, 0, 40) . '“ ist keine der Optionen');
			}
		}
	}

	/**
	 * Textwert aus der Eingabe: ohne Steuer-, unsichtbare und Richtungszeichen, einzeilig falls verlangt, getrimmt,
	 * Länge geprüft.
	 *
	 * @param array<string,mixed> $eingabe
	 */
	private function text(array $eingabe, string $schluessel, string $label, bool $einzeilig, int $maxLaenge): string {
		$wert = $eingabe[$schluessel] ?? '';
		if (!is_string($wert) && !is_int($wert) && !is_float($wert)) {
			throw new PlanungException("„{$label}“ ist kein Text");
		}
		$wert = str_replace("\r\n", "\n", (string)$wert);
		// Zeilen- und Absatztrenner (U+2028/U+2029) sind Umbrüche, die kaum ein Programm zeigt: wie \n behandeln.
		$wert = (string)preg_replace(Einstellungen::TRENNER, "\n", $wert);
		if ($einzeilig) {
			// Umbrüche und Tabs zu einem Leerzeichen, sonstige Steuerzeichen raus.
			$wert = (string)preg_replace('/[\r\n\t]+/', ' ', $wert);
			$wert = (string)preg_replace('/\p{Cc}/u', '', $wert);
		} else {
			// Mehrzeilig: Umbruch und Tab bleiben, alle anderen Steuerzeichen raus.
			$wert = (string)preg_replace('/[^\P{Cc}\n\t]/u', '', $wert);
		}
		$wert = Einstellungen::ohneUnsichtbare($wert);
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

	private function ende(VEvent $termin, ?DateTimeZone $ortszeit = null): ?DateTimeImmutable {
		if (isset($termin->DTEND)) {
			return $termin->DTEND->getDateTime($ortszeit);
		}
		if (isset($termin->DTSTART, $termin->DURATION)) {
			return $termin->DTSTART->getDateTime($ortszeit)->add($termin->DURATION->getDateInterval());
		}
		return null;
	}

	/**
	 * Einzeltermin (ohne RRULE) im Zeitraum: Prüfung wie der time-range-Filter von CalDAV (Sabre `isInTimeRange`), der
	 * die Liste bis 1.0 im Backend gefiltert hat. Liegt nur eine Ausnahme ohne Serie im Objekt, zählt jede.
	 */
	private function einzelnImZeitraum(VCalendar $kalender, DateTimeInterface $von, DateTimeInterface $bis, DateTimeZone $zone): bool {
		// isInTimeRange liest Floating-Zeiten in der Zone von $von.
		$von = DateTimeImmutable::createFromInterface($von)->setTimezone($zone);
		$bis = DateTimeImmutable::createFromInterface($bis)->setTimezone($zone);
		foreach ($kalender->select('VEVENT') as $ereignis) {
			if (isset($ereignis->DTSTART) && $ereignis->isInTimeRange($von, $bis)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Termin einer Serie für die Liste (U-KAL-16): der nächste ab $jetzt, wenn er im Zeitraum beginnt, sonst der erste
	 * im Zeitraum; null, wenn kein Termin der Serie im Zeitraum liegt.
	 *
	 * @return array{0:DateTimeImmutable,1:?DateTimeImmutable}|null
	 */
	private function serienTermin(VCalendar $kalender, VEvent $serie, DateTimeInterface $von, DateTimeInterface $bis,
		DateTimeZone $ortszeit, ?DateTimeInterface $jetzt): ?array {
		if ($jetzt !== null && $jetzt > $von && $jetzt < $bis) {
			$naechster = $this->ersterAb($kalender, $serie, $jetzt, $bis, $ortszeit, $von->getTimezone());
			if ($naechster !== null) {
				return $naechster;
			}
		}
		return $this->ersterAb($kalender, $serie, $von, $bis, $ortszeit, $von->getTimezone());
	}

	/**
	 * Erster Termin der Serie, der nach $ab endet und vor $bis beginnt (Semantik wie `VCalendar::expand`, das bis 1.0
	 * die ganze Serie im Zeitraum aufgelöst hat, P-KAL-01). Der EventIterator springt zu $ab (`fastForward`); bei
	 * einfachen Regeln liegt der Beginn der Serie vorher schon kurz vor $ab (serienEreignisse), so dass auch endlose
	 * tägliche Serien nicht Tag für Tag von ihrem Anfang an durchlaufen werden (P-KAL-06).
	 *
	 * Fehler im iCalendar (z. B. ungültige RRULE) gehen an den Aufrufer: Die Liste überspringt den Termin mit Eintrag
	 * im Protokoll, wie bisher der Filter im Backend.
	 *
	 * @return array{0:DateTimeImmutable,1:?DateTimeImmutable}|null Beginn und Ende in $ausgabe
	 */
	private function ersterAb(VCalendar $kalender, VEvent $serie, DateTimeInterface $ab, DateTimeInterface $bis,
		DateTimeZone $ortszeit, DateTimeZone $ausgabe): ?array {
		$ganztags = !$serie->DTSTART->hasTime();
		if ($ganztags) {
			// Ganztägige Termine sind ein Datum ohne Zone, das in der Ortszeit gilt. Gerechnet wird in UTC (ohne
			// Sommerzeit-Sprünge), dafür gelten $ab und $bis mit ihrer Wanduhrzeit in der Ortszeit. Das gibt dieselben
			// Termine wie expand() in der Ortszeit, und der Beginn ist wie bisher UTC-Mitternacht des Tages.
			$rechenzone = new DateTimeZone('UTC');
			$ab = self::wanduhr($ab, $ortszeit);
			$bis = self::wanduhr($bis, $ortszeit);
		} else {
			$rechenzone = $ortszeit;
		}
		$ereignisse = $this->serienEreignisse($kalender, $serie, $ab, $rechenzone, $ganztags ? $ortszeit : null);
		try {
			$iterator = new EventIterator($ereignisse, null, $rechenzone);
		} catch (NoInstancesException) {
			return null;
		}
		$iterator->fastForward($ab);
		if (!$iterator->valid() || $iterator->getDtStart() >= $bis) {
			return null;
		}
		$beginn = DateTimeImmutable::createFromInterface($iterator->getDtStart())->setTimezone(new DateTimeZone('UTC'));
		// Ende wie bei einem einzelnen Termin aus DTEND oder DURATION, ohne beides keins. Maßgeblich ist die Ausnahme
		// (RECURRENCE-ID), wenn der Termin eine ist, sonst die ursprüngliche Serie, nicht die vorgerückte Kopie.
		$objekt = $iterator->getEventObject();
		$quelle = in_array($objekt, array_slice($ereignisse, 1), true) ? $objekt : $serie;
		if (isset($quelle->DTEND)) {
			$ende = DateTimeImmutable::createFromInterface($iterator->getDtEnd());
		} elseif (isset($quelle->DURATION)) {
			$ende = $beginn->add($quelle->DURATION->getDateInterval());
		} else {
			$ende = null;
		}
		return [$beginn->setTimezone($ausgabe), $ende?->setTimezone($ausgabe)];
	}

	/** Dieselbe Wanduhrzeit wie $zeit in $zone, als UTC. */
	private static function wanduhr(DateTimeInterface $zeit, DateTimeZone $zone): DateTimeImmutable {
		$ort = DateTimeImmutable::createFromInterface($zeit)->setTimezone($zone);
		return new DateTimeImmutable($ort->format('Y-m-d\\TH:i:s.u'), new DateTimeZone('UTC'));
	}

	/**
	 * Die Ereignisse der Serie (Serie und Ausnahmen mit derselben UID) für den EventIterator.
	 *
	 * Bei einfachen Regeln rückt eine Kopie der Serie ihren Beginn um ganze Perioden bis kurz vor $ab vor: FREQ=DAILY
	 * oder WEEKLY, ohne COUNT und ohne BYxxx außer BYDAY, ohne RDATE und EXRULE. Dort ist jeder um ganze Perioden
	 * verschobene Beginn selbst ein Termin der Serie, und die Termine ab dem neuen Beginn sind genau die der
	 * ursprünglichen Serie. Die Uhrzeit bleibt (Wanduhr in der Zone des Beginns), die Dauer in Sekunden auch; EXDATE,
	 * Ausnahmen (RECURRENCE-ID) und UNTIL sind feste Zeitpunkte und bleiben, wie sie sind. Ohne Vorrücken zählt der
	 * EventIterator jeden Termin ab dem Anfang der Serie und bricht nach 3 500 Terminen ab
	 * (MaxInstancesExceededException), bei einer täglichen Serie also nach knapp zehn Jahren. Solche Serien, die nicht
	 * vorgerückt werden können, fehlen in der Liste und landen im Protokoll (docs/KONZEPT.md).
	 *
	 * Bei ganztägigen Serien ($datumIn = Ortszeit) bekommt der EventIterator EXDATE und RECURRENCE-ID als Datum: Manche
	 * Programme (Outlook) schreiben sie als DATE-TIME mit TZID, z. B. Mitternacht in Berlin; in UTC gerechnet wäre das
	 * der Vortag, und Ausnahme bzw. Auslassung träfen keinen Termin der Serie.
	 *
	 * @return list<VEvent>
	 */
	private function serienEreignisse(VCalendar $kalender, VEvent $serie, DateTimeInterface $ab, DateTimeZone $rechenzone,
		?DateTimeZone $datumIn = null): array {
		$uid = (string)($serie->UID ?? '');
		$ereignisse = [];
		foreach ($kalender->select('VEVENT') as $ereignis) {
			if ($ereignis !== $serie && isset($ereignis->{'RECURRENCE-ID'}) && (string)($ereignis->UID ?? '') === $uid) {
				$ereignisse[] = $datumIn === null ? $ereignis : self::alsDatum($ereignis, $datumIn);
			}
		}
		$erstes = $this->vorgerueckt($serie, $ab, $rechenzone) ?? $serie;
		array_unshift($ereignisse, $datumIn === null ? $erstes : self::alsDatum($erstes, $datumIn));
		return $ereignisse;
	}

	/**
	 * Das Ereignis mit EXDATE und RECURRENCE-ID als Datum in $zone (siehe serienEreignisse); eine Kopie, wenn etwas
	 * umzuschreiben ist, sonst das Ereignis selbst.
	 */
	private static function alsDatum(VEvent $ereignis, DateTimeZone $zone): VEvent {
		$umschreiben = false;
		foreach (['EXDATE', 'RECURRENCE-ID'] as $name) {
			foreach ($ereignis->select($name) as $wert) {
				$umschreiben = $umschreiben || ($wert instanceof DateTime && $wert->hasTime());
			}
		}
		if (!$umschreiben) {
			return $ereignis;
		}
		$kopie = clone $ereignis;
		foreach (['EXDATE', 'RECURRENCE-ID'] as $name) {
			$tage = [];
			foreach ($kopie->select($name) as $wert) {
				if ($wert instanceof DateTime) {
					foreach ($wert->getDateTimes($zone) as $zeit) {
						$tage[] = $zeit->setTimezone($zone)->format('Ymd');
					}
				}
			}
			if ($tage === []) {
				continue;
			}
			unset($kopie->{$name});
			$tage = array_values(array_unique($tage));
			// RECURRENCE-ID ist ein einzelner Wert, EXDATE darf mehrere tragen.
			$kopie->add($name, $name === 'EXDATE' ? $tage : $tage[0], ['VALUE' => 'DATE']);
		}
		return $kopie;
	}

	/** Kopie der Serie mit vorgerücktem Beginn (siehe serienEreignisse), null, wenn das nicht geht oder nichts bringt. */
	private function vorgerueckt(VEvent $serie, DateTimeInterface $ab, DateTimeZone $rechenzone): ?VEvent {
		if (count($serie->select('RRULE')) !== 1 || isset($serie->RDATE) || isset($serie->EXRULE)) {
			return null;
		}
		$teile = $serie->RRULE->getParts();
		if (array_diff(array_keys($teile), ['FREQ', 'INTERVAL', 'UNTIL', 'WKST', 'BYDAY']) !== []) {
			return null;
		}
		$intervall = (int)($teile['INTERVAL'] ?? 1);
		$frequenz = strtoupper((string)($teile['FREQ'] ?? ''));
		if ($intervall < 1 || !in_array($frequenz, ['DAILY', 'WEEKLY'], true)) {
			return null;
		}
		$beginn = DateTimeImmutable::createFromInterface($serie->DTSTART->getDateTime($rechenzone));
		// Täglich ohne BYDAY: jeder Tag im Abstand INTERVAL. Mit BYDAY (täglich oder wöchentlich) und wöchentlich:
		// ganze Wochen, damit der Wochentag des Beginns und der Wochenrhythmus bleiben. Steht der Wochentag des Beginns
		// nicht in BYDAY, zählt der EventIterator den (vorgerückten) Beginn trotzdem als Termin; das schadet nicht, weil
		// er mindestens zwei Tage vor $ab endet und fastForward ihn überspringt.
		$periode = $frequenz === 'DAILY' && !isset($teile['BYDAY']) ? $intervall : 7 * $intervall;
		if (isset($teile['BYDAY'])
			&& array_diff(array_map('strtoupper', (array)$teile['BYDAY']), ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU']) !== []) {
			return null;
		}
		$dauer = $this->dauer($serie, $beginn, $rechenzone);
		// Der neue Beginn endet mindestens zwei Tage vor $ab (Puffer für Sommerzeit und Rundung), so dass fastForward
		// ihn überspringt.
		$tage = intdiv(max(0, $ab->getTimestamp() - $beginn->getTimestamp() - $dauer), 86400) - 2;
		$sprung = intdiv(max(0, $tage), $periode) * $periode;
		// Fällt die Uhrzeit am Zieltag in die Lücke der Sommerzeit-Umstellung, eine Periode früher versuchen.
		// Eigenheit von Sabre bei Serien um 02:xx: Der EventIterator rechnet von Termin zu Termin weiter und bleibt nach
		// dem ersten Umstellungstag dauerhaft bei 03:xx, expand() zeigt dann 03:xx. Die vorgerückte Kopie beginnt nach
		// der Lücke wieder um 02:xx, wie es RFC 5545 verlangt (die Wanduhrzeit der Serie gilt an jedem Tag); hier weicht
		// die Liste also bewusst von expand() ab.
		$neu = null;
		foreach ([$sprung, $sprung - $periode] as $versuch) {
			$kandidat = $beginn->modify("+{$versuch} days");
			if ($versuch > 0 && $kandidat->format('H:i:s') === $beginn->format('H:i:s')) {
				$neu = $kandidat;
				break;
			}
		}
		if ($neu === null) {
			return null;
		}
		$kopie = clone $serie;
		$floating = $serie->DTSTART->hasTime() && $serie->DTSTART->isFloating();
		$kopie->DTSTART->setDateTime($neu, $floating);
		if (isset($kopie->DTEND) || isset($kopie->DURATION)) {
			// Gleiche Dauer in Sekunden wie die ursprüngliche Serie (der EventIterator rechnet mit dieser Dauer).
			unset($kopie->DURATION, $kopie->DTEND);
			$ende = $neu->setTimestamp($neu->getTimestamp() + $dauer);
			$kopie->add('DTEND', $ende);
			if (!$serie->DTSTART->hasTime()) {
				$kopie->DTEND['VALUE'] = 'DATE';
				$kopie->DTEND->setDateTime($ende);
			} else {
				$kopie->DTEND->setDateTime($ende, $floating);
			}
		}
		return $kopie;
	}

	/** Dauer eines Termins der Serie in Sekunden, so wie der EventIterator sie rechnet. */
	private function dauer(VEvent $serie, DateTimeImmutable $beginn, DateTimeZone $rechenzone): int {
		if (isset($serie->DTEND)) {
			return $serie->DTEND->getDateTime($rechenzone)->getTimestamp() - $beginn->getTimestamp();
		}
		if (isset($serie->DURATION)) {
			return $beginn->add($serie->DURATION->getDateInterval())->getTimestamp() - $beginn->getTimestamp();
		}
		return $serie->DTSTART->hasTime() ? 0 : 86400;
	}

	private function setzen(VEvent $ereignis, string $eigenschaft, string $wert): void {
		unset($ereignis->{$eigenschaft});
		if ($wert !== '') {
			$ereignis->add($eigenschaft, $wert);
		}
	}

	/**
	 * Setzt die Link-Zeile „<Linktext><Adresse>“ in die Beschreibung (Q-KAL-03).
	 *
	 * Als eigene Zeile gilt nur eine, die genau so lautet wie die zuletzt geschriebene (EIGENSCHAFT_LINK) oder wie die
	 * neue. Termine ohne gemerkte Zeile (von Versionen bis 1.0.1 geschrieben) erkennt die App an „<aktueller oder früherer Vorgabe-
	 * Linktext><Adresse dieser App>“, ohne weiteren Text davor oder danach. Alles andere gehört dem Nutzer und bleibt,
	 * auch Zeilen mit fremdem Text vor einer Planungs-Adresse. Die eigene Zeile wird an ihrer Stelle ersetzt; die
	 * Zeilenenden der Beschreibung (`\r\n` oder `\n`) bleiben, und steht die Zeile schon so da, bleibt die
	 * Beschreibung unangetastet.
	 */
	private function linkEinfuegen(VEvent $ereignis, string $link): void {
		$neu = $this->einstellungen->linkText . $link;
		$gemerkt = isset($ereignis->{self::EIGENSCHAFT_LINK}) ? (string)$ereignis->{self::EIGENSCHAFT_LINK}->getValue() : '';
		$beschreibung = (string)($ereignis->DESCRIPTION ?? '');
		// Abwechselnd Zeile und Zeilenende: [Zeile 0, Ende 0, Zeile 1, …, letzte Zeile].
		$teile = preg_split('/(\r\n|\n|\r)/', $beschreibung, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$beschreibung];
		$eigene = [];
		for ($i = 0; $i < count($teile); $i += 2) {
			if ($this->istEigeneLinkZeile($teile[$i], $neu, $gemerkt)) {
				$eigene[] = $i;
			}
		}
		if ($eigene === []) {
			$text = rtrim($beschreibung);
			$ereignis->DESCRIPTION = ($text === '' ? '' : $text . "\n\n") . $neu;
		} elseif (count($eigene) > 1 || $teile[$eigene[0]] !== $neu) {
			$teile[$eigene[0]] = $neu;
			// Weitere eigene Zeilen (doppelt eingefügt) samt ihrem vorangehenden Zeilenende entfernen.
			foreach (array_slice($eigene, 1) as $i) {
				$teile[$i] = null;
				$teile[$i - 1] = null;
			}
			$ereignis->DESCRIPTION = implode('', array_filter($teile, fn (?string $teil) => $teil !== null));
		}
		$this->setzen($ereignis, self::EIGENSCHAFT_LINK, $neu);
	}

	private function istEigeneLinkZeile(string $zeile, string $neu, string $gemerkt): bool {
		$zeile = rtrim($zeile, " \t");
		if ($zeile === $neu || ($gemerkt !== '' && $zeile === $gemerkt)) {
			return true;
		}
		if ($gemerkt !== '') {
			return false;
		}
		foreach (array_unique([$this->einstellungen->linkText, Einstellungen::LINK_TEXT_VORGABE]) as $linkText) {
			if (str_starts_with($zeile, $linkText) && preg_match(self::APP_ADRESSE, substr($zeile, strlen($linkText))) === 1) {
				return true;
			}
		}
		return false;
	}
}
