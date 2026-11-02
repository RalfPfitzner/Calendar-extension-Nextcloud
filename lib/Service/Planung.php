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
	 * @param DateTimeInterface|null $von  mit $bis: bei Serien den ersten Termin im Zeitraum ermitteln
	 * @param DateTimeZone|null $zone Zone für „Floating“-Zeiten (ohne TZID und ohne Z), also die Ortszeit, in der
	 *   die Oberfläche anzeigt; ohne Angabe die Zone von $von, sonst die PHP-Vorgabe (in Nextcloud UTC)
	 * @return array<string,mixed>|null null, wenn das Objekt kein Termin ist
	 */
	public function lesen(string $ics, ?DateTimeInterface $von = null, ?DateTimeInterface $bis = null, ?DateTimeZone $zone = null): ?array {
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

		if ($serie && $von !== null && $bis !== null) {
			$erster = $this->ersterImZeitraum($kalender, $von, $bis, $zone ?? $von->getTimezone());
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
		$this->optionenPruefen($termin, $planung);
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

	/** @return array{0:DateTimeImmutable,1:?DateTimeImmutable}|null */
	private function ersterImZeitraum(VCalendar $kalender, DateTimeInterface $von, DateTimeInterface $bis, DateTimeZone $ortszeit): ?array {
		try {
			// Der dritte Parameter ist die Zone für Floating-Zeiten der Serie.
			$aufgeloest = (clone $kalender)->expand($von, $bis, $ortszeit);
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
