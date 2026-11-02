<?php

declare(strict_types=1);

namespace OCA\CalendarPlanning\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use OCA\CalendarPlanning\Service\Einstellungen;
use OCA\CalendarPlanning\Service\Planung;
use OCA\CalendarPlanning\Service\PlanungException;
use OCA\CalendarPlanning\Service\Schema;
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Reader;

class PlanungTest extends TestCase {
	private const ORT_VORGABE = 'Vereinsheim';

	private Planung $planung;

	protected function setUp(): void {
		$this->planung = new Planung(new Einstellungen(self::ORT_VORGABE, 'Planung'));
	}

	private static function ics(string $zusatz = '', string $weitere = ''): string {
		return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//DE\r\n"
			. "BEGIN:VEVENT\r\nUID:t1\r\nDTSTAMP:20261001T000000Z\r\n"
			. "DTSTART;TZID=Europe/Berlin:20261105T140000\r\nDTEND;TZID=Europe/Berlin:20261105T170000\r\n"
			. "SUMMARY:Lötworkshop\r\nATTENDEE;CN=Gast:mailto:gast@example.org\r\nX-FREMD:bleibt\r\n{$zusatz}END:VEVENT\r\n"
			. $weitere . "END:VCALENDAR\r\n";
	}

	private function eingabe(array $werte = [], ?Planung $planung = null): array {
		return ($planung ?? $this->planung)->pruefen($werte + ['titel' => 'Lötworkshop', 'ort_extern' => false, 'ort' => '']);
	}

	/** Planung mit einem Schema aus genau diesen Feldern. */
	private static function mitFeldern(array ...$felder): Planung {
		$schema = Schema::ausArray(['abschnitte' => [['titel' => 'Test', 'felder' => $felder]]]);
		return new Planung(new Einstellungen(self::ORT_VORGABE, '', Einstellungen::LINK_TEXT_VORGABE, $schema));
	}

	public function testEigenschaftsnamenFolgenDemSchluessel(): void {
		$this->assertSame('X-CALPLAN-KONTAKT-ORGANISATION', Planung::eigenschaft('kontakt_organisation'));
		$this->assertSame('X-CALPLAN-A1', Planung::eigenschaft('a1'));
	}

	public function testLesenOhnePlanungSetztOrtVorgabe(): void {
		$daten = $this->planung->lesen(self::ics());
		$this->assertSame('Lötworkshop', $daten['titel']);
		$this->assertSame(self::ORT_VORGABE, $daten['ort']);
		$this->assertFalse($daten['ort_extern']);
		$this->assertFalse($daten['geplant']);
		$this->assertSame('2026-11-05T14:00:00+01:00', $daten['beginn']);
		$this->assertSame('2026-11-05T17:00:00+01:00', $daten['ende']);
		$this->assertSame('', $daten['hinweise']);
		$this->assertCount(16, array_intersect_key($daten, Schema::vorgabe()->felder));
	}

	public function testSchreibenUndLesenErgibtDieselbenWerte(): void {
		$planung = $this->eingabe([
			'ort_extern' => true,
			'ort' => "Grundschule Musterstadt\nSchulstraße 1",
			'zustaendig' => 'Eva',
			'kontakt_email' => 'lehrer@example.org',
			'hinweise' => "Zeile 1\r\nZeile 2; mit, Komma und \\ Backslash",
			'teilnehmende' => '24',
		]);
		$neu = $this->planung->schreiben(self::ics(), $planung, 'https://cloud.example.org/apps/calendar_planning/?kalender=3&termin=t1.ics');
		$daten = $this->planung->lesen($neu);

		$this->assertTrue($daten['geplant']);
		$this->assertTrue($daten['ort_extern']);
		$this->assertSame("Grundschule Musterstadt\nSchulstraße 1", $daten['ort']);
		$this->assertSame('Eva', $daten['zustaendig']);
		$this->assertSame("Zeile 1\nZeile 2; mit, Komma und \\ Backslash", $daten['hinweise']);
		$this->assertSame('24', $daten['teilnehmende']);
		$this->assertStringContainsString('X-CALPLAN-ZUSTAENDIG:Eva', $neu);
		$this->assertStringNotContainsString('X-MSP-', $neu);
	}

	public function testFremdeEigenschaftenUndZeitenBleibenErhalten(): void {
		$neu = $this->planung->schreiben(self::ics(), $this->eingabe(['zustaendig' => 'Ralf']), null);
		$termin = Reader::read($neu)->VEVENT;
		$this->assertSame('bleibt', (string)$termin->{'X-FREMD'});
		$this->assertSame('mailto:gast@example.org', (string)$termin->ATTENDEE);
		$this->assertSame('20261105T140000', (string)$termin->DTSTART);
		$this->assertSame('Europe/Berlin', (string)$termin->DTSTART['TZID']);
	}

	public function testLeereFelderEntfernenDieEigenschaft(): void {
		$mit = $this->planung->schreiben(self::ics(), $this->eingabe(['raum' => 'Werkstatt']), null);
		$ohne = $this->planung->schreiben($mit, $this->eingabe(['raum' => '']), null);
		$this->assertStringContainsString('X-CALPLAN-RAUM', $mit);
		$this->assertStringNotContainsString('X-CALPLAN-RAUM', $ohne);
	}

	public function testFelderAusserhalbDesSchemasBleibenUnberuehrt(): void {
		// Ein Feld, das das aktuelle Schema nicht kennt (z. B. nach einer Schema-Änderung), wird weder gelesen noch gelöscht.
		$ics = self::ics("X-CALPLAN-ALTESFELD:bleibt\r\n");
		$daten = $this->planung->lesen($ics);
		$this->assertArrayNotHasKey('altesfeld', $daten);
		$neu = $this->planung->schreiben($ics, $this->eingabe(['raum' => 'x']), null);
		$this->assertStringContainsString('X-CALPLAN-ALTESFELD:bleibt', $neu);
	}

	public function testInternerOrtSchreibtDieVorgabe(): void {
		$neu = $this->planung->schreiben(self::ics("LOCATION:Irgendwo\r\n"), $this->eingabe(['ort' => 'wird ignoriert']), null);
		$this->assertSame(self::ORT_VORGABE, (string)Reader::read($neu)->VEVENT->LOCATION);
	}

	public function testOhneStandardortIstDasOrtsfeldFrei(): void {
		$planung = new Planung(new Einstellungen());
		// Lesen: kein Ort heißt leer, jeder Ort gilt als eingetragen.
		$this->assertSame('', $planung->lesen(self::ics())['ort']);
		$this->assertFalse($planung->lesen(self::ics())['ort_extern']);
		$this->assertTrue($planung->lesen(self::ics("LOCATION:Rathaus\r\n"))['ort_extern']);

		// Prüfen: ort_extern ergibt sich aus dem Ortsfeld, ein leerer Ort ist erlaubt.
		$leer = $this->eingabe(['ort_extern' => true, 'ort' => ''], $planung);
		$this->assertFalse($leer['ort_extern']);
		$voll = $this->eingabe(['ort_extern' => false, 'ort' => 'Rathaus'], $planung);
		$this->assertTrue($voll['ort_extern']);

		// Schreiben: leer entfernt LOCATION, ein Ort steht wörtlich drin.
		$ohne = $planung->schreiben(self::ics("LOCATION:Irgendwo\r\n"), $leer, null);
		$this->assertStringNotContainsString('LOCATION', $ohne);
		$mit = $planung->schreiben(self::ics(), $voll, null);
		$this->assertSame('Rathaus', (string)Reader::read($mit)->VEVENT->LOCATION);
	}

	public function testLinkWirdEinmalAngehaengtUndAlterErsetzt(): void {
		$alt = 'https://alt.example.org/apps/calendar_planning/?kalender=1&termin=t1.ics';
		$neu = 'https://cloud.example.org/apps/calendar_planning/?kalender=3&termin=t1.ics';
		$einmal = $this->planung->schreiben(self::ics("DESCRIPTION:Mitbringen: Laptop\r\n"), $this->eingabe(), $alt);
		$zweimal = $this->planung->schreiben($einmal, $this->eingabe(), $alt);
		$this->assertSame("Mitbringen: Laptop\n\nPlanung öffnen: {$alt}", $this->planung->lesen($zweimal)['beschreibung']);

		$umgezogen = $this->planung->schreiben($zweimal, $this->eingabe(), $neu);
		$this->assertSame("Mitbringen: Laptop\n\nPlanung öffnen: {$neu}", $this->planung->lesen($umgezogen)['beschreibung']);
	}

	public function testGeaenderterLinktextErsetztDenAltenLink(): void {
		$link = 'https://cloud.example.org/index.php/apps/calendar_planning/?kalender=3&termin=t1.ics';
		$vorher = $this->planung->schreiben(self::ics("DESCRIPTION:Mitbringen: Laptop\r\n"), $this->eingabe(), $link);
		$planung = new Planung(new Einstellungen(self::ORT_VORGABE, '', 'Zur Planung:'));
		$nachher = $planung->schreiben($vorher, $this->eingabe([], $planung), $link);
		$this->assertSame("Mitbringen: Laptop\n\nZur Planung: {$link}", $planung->lesen($nachher)['beschreibung']);
		// Fremde Links in der Beschreibung bleiben stehen.
		$fremd = $planung->schreiben(self::ics("DESCRIPTION:Infos: https://example.org/apps/andere/?kalender=1&termin=x\r\n"), $this->eingabe([], $planung), $link);
		$this->assertStringStartsWith('Infos: https://example.org/apps/andere/', $planung->lesen($fremd)['beschreibung']);
	}

	public function testLinktextAusEinstellungen(): void {
		$planung = new Planung(new Einstellungen(self::ORT_VORGABE, '', "Zur\tPlanung:"));
		$neu = $planung->schreiben(self::ics(), $this->eingabe([], $planung), 'https://cloud.example.org/p');
		$this->assertSame('Zur Planung: https://cloud.example.org/p', $planung->lesen($neu)['beschreibung']);
	}

	public function testEinstellungenWerdenBereinigt(): void {
		$e = new Einstellungen("  Vereins\r\nheim\x00 ", str_repeat('x', 200), '');
		$this->assertSame('Vereins heim', $e->ortVorgabe);
		$this->assertSame(100, mb_strlen($e->kalenderMuster));
		// Ohne Erkennungszeichen würde jede Zeile der Beschreibung als alter Link gelten; deshalb die Vorgabe.
		$this->assertSame(Einstellungen::LINK_TEXT_VORGABE, $e->linkText);
		$this->assertSame('Vereins heim', $e->fuerOberflaeche()['ort_vorgabe']);
		$this->assertSame(Schema::vorgabe()->alsArray(), $e->fuerOberflaeche()['schema']);
		$this->assertSame(['ort_vorgabe' => 'Vereins heim', 'kalender_muster' => str_repeat('x', 100), 'link_text' => 'Planung öffnen:'], $e->vorgaben());
	}

	public function testSerieErsterTerminImZeitraumUndAusnahmen(): void {
		$ausnahme = "BEGIN:VEVENT\r\nUID:t1\r\nDTSTAMP:20261001T000000Z\r\nRECURRENCE-ID;TZID=Europe/Berlin:20261112T140000\r\n"
			. "DTSTART;TZID=Europe/Berlin:20261112T150000\r\nDTEND;TZID=Europe/Berlin:20261112T180000\r\n"
			. "SUMMARY:Lötworkshop\r\nLOCATION:Sonderort\r\nEND:VEVENT\r\n";
		$ics = self::ics("RRULE:FREQ=WEEKLY;COUNT=5\r\n", $ausnahme);
		$zone = new DateTimeZone('Europe/Berlin');
		$daten = $this->planung->lesen($ics, new DateTimeImmutable('2026-11-10', $zone), new DateTimeImmutable('2026-12-31', $zone));
		$this->assertTrue($daten['serie']);
		$this->assertSame('2026-11-12T15:00:00+01:00', $daten['beginn']);

		$neu = $this->planung->schreiben($ics, $this->eingabe(['titel' => 'Lötkurs', 'zustaendig' => 'Eva']), null);
		$ereignisse = Reader::read($neu)->select('VEVENT');
		$this->assertCount(2, $ereignisse);
		foreach ($ereignisse as $ereignis) {
			$this->assertSame('Lötkurs', (string)$ereignis->SUMMARY);
			$this->assertSame('Eva', (string)$ereignis->{'X-CALPLAN-ZUSTAENDIG'});
		}
		// Der abweichende Ort der Ausnahme bleibt, der der Serie wird gesetzt.
		$orte = array_map(fn ($e) => (string)$e->LOCATION, $ereignisse);
		$this->assertSame([self::ORT_VORGABE, 'Sonderort'], $orte);
	}

	public function testGanztaegigerTermin(): void {
		$ics = str_replace(
			["DTSTART;TZID=Europe/Berlin:20261105T140000", "DTEND;TZID=Europe/Berlin:20261105T170000"],
			["DTSTART;VALUE=DATE:20261105", "DTEND;VALUE=DATE:20261106"],
			self::ics(),
		);
		$daten = $this->planung->lesen($ics);
		$this->assertTrue($daten['ganztags']);
		$this->assertStringStartsWith('2026-11-05', $daten['beginn']);
	}

	public function testKeinTermin(): void {
		$aufgabe = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//DE\r\nBEGIN:VTODO\r\nUID:a\r\nDTSTAMP:20261001T000000Z\r\nSUMMARY:Aufgabe\r\nEND:VTODO\r\nEND:VCALENDAR\r\n";
		$this->assertNull($this->planung->lesen($aufgabe));
	}

	public function testPruefenTitelPflicht(): void {
		$this->expectException(PlanungException::class);
		$this->expectExceptionMessage('Titel');
		$this->eingabe(['titel' => '   ']);
	}

	public function testPruefenExternerOrtBrauchtAdresse(): void {
		$this->expectException(PlanungException::class);
		$this->eingabe(['ort_extern' => true, 'ort' => '']);
	}

	public function testPruefenEmail(): void {
		$this->expectException(PlanungException::class);
		$this->expectExceptionMessage('E-Mail');
		$this->eingabe(['kontakt_email' => 'keine-mail']);
	}

	public function testPruefenLaengeUndTyp(): void {
		try {
			$this->eingabe(['hinweise' => str_repeat('x', Planung::MAX_LAENGE + 1)]);
			$this->fail('zu lang nicht erkannt');
		} catch (PlanungException $e) {
			$this->assertStringContainsString('zu lang', $e->getMessage());
			$this->assertStringContainsString('Hinweise', $e->getMessage(), 'die Meldung nennt das Feld');
		}
		// Das Schema darf weniger erlauben als die Obergrenze.
		try {
			$this->eingabe(['teilnehmende' => str_repeat('9', 101)]);
			$this->fail('maxlen des Schemas nicht beachtet');
		} catch (PlanungException $e) {
			$this->assertStringContainsString('höchstens 100', $e->getMessage());
		}
		$this->expectException(PlanungException::class);
		$this->eingabe(['hinweise' => ['kein', 'text']]);
	}

	public function testPruefenNurEchtesTrueIstExtern(): void {
		$this->assertFalse($this->eingabe(['ort_extern' => 'true', 'ort' => 'x'])['ort_extern']);
	}

	public function testPruefenSteuerzeichen(): void {
		$planung = $this->eingabe([
			'titel' => "Löt\r\nworkshop\tGrundschule\x00\x1b",
			'kontakt_name' => "Eva\nMuster",
			'hinweise' => "Zeile 1\r\nZeile 2\tTab\x00\x07Ende",
		]);
		// Einzeilig: Umbruch und Tab werden Leerzeichen, andere Steuerzeichen verschwinden.
		$this->assertSame('Löt workshop Grundschule', $planung['titel']);
		$this->assertSame('Eva Muster', $planung['kontakt_name']);
		// Mehrzeilig: Umbruch und Tab bleiben, andere Steuerzeichen verschwinden.
		$this->assertSame("Zeile 1\nZeile 2\tTabEnde", $planung['hinweise']);
	}

	public function testZahlenfeld(): void {
		$planung = self::mitFeldern(['key' => 'teilnehmende', 'label' => 'Teilnehmende', 'typ' => 'number']);
		$this->assertSame('20', $this->eingabe(['teilnehmende' => ' 20 '], $planung)['teilnehmende']);
		$this->assertSame('12,5', $this->eingabe(['teilnehmende' => '12,5'], $planung)['teilnehmende']);
		$this->assertSame('7', $this->eingabe(['teilnehmende' => 7], $planung)['teilnehmende']);
		$this->assertSame('', $this->eingabe(['teilnehmende' => ''], $planung)['teilnehmende']);
		try {
			$this->eingabe(['teilnehmende' => 'ca. 20'], $planung);
			$this->fail('„ca. 20“ nicht abgelehnt');
		} catch (PlanungException $e) {
			$this->assertSame(400, $e->getCode());
			$this->assertStringContainsString('Teilnehmende', $e->getMessage());
			$this->assertStringContainsString('Zahl', $e->getMessage());
		}
	}

	public function testAuswahlfeld(): void {
		$planung = self::mitFeldern(['key' => 'art', 'label' => 'Art', 'typ' => 'select', 'optionen' => ['Kurs', 'Offen']]);
		$this->assertSame('Kurs', $this->eingabe(['art' => 'Kurs'], $planung)['art']);
		$this->assertSame('', $this->eingabe([], $planung)['art']);
		$this->assertStringContainsString('X-CALPLAN-ART:Kurs', $planung->schreiben(self::ics(), $this->eingabe(['art' => 'Kurs'], $planung), null));
		$this->expectException(PlanungException::class);
		$this->expectExceptionMessage('„Art“: „kurs“ ist keine der Optionen');
		$planung->schreiben(self::ics(), $this->eingabe(['art' => 'kurs'], $planung), null);
	}

	/** Q-KAL-09: Ein gespeicherter Wert, der keine Option mehr ist, verhindert das Speichern nicht. */
	public function testAuswahlAltwertBleibtSpeicherbar(): void {
		$planung = self::mitFeldern(['key' => 'art', 'label' => 'Art', 'typ' => 'select', 'optionen' => ['Offen', 'Vortrag']]);
		$ics = self::ics("X-CALPLAN-ART:Kurs\r\n");
		$this->assertSame('Kurs', $planung->lesen($ics)['art']);

		// Unverändert gespeichert: angenommen, der Wert bleibt.
		$neu = $planung->schreiben($ics, $this->eingabe(['art' => 'Kurs', 'titel' => 'Neuer Titel'], $planung), null);
		$this->assertStringContainsString('X-CALPLAN-ART:Kurs', $neu);
		$this->assertStringContainsString('SUMMARY:Neuer Titel', $neu);

		// Wechsel auf eine Option und Leeren gehen ebenfalls.
		$this->assertStringContainsString('X-CALPLAN-ART:Vortrag', $planung->schreiben($ics, $this->eingabe(['art' => 'Vortrag'], $planung), null));
		$this->assertStringNotContainsString('X-CALPLAN-ART', $planung->schreiben($ics, $this->eingabe(['art' => ''], $planung), null));

		// Ein anderer Wert, der keine Option ist, wird abgelehnt, auch wenn ein Altwert im Termin steht.
		foreach (['Workshop', 'kurs', 'Kursx', 'Kur'] as $wert) {
			try {
				$planung->schreiben($ics, $planung->pruefen(['titel' => 'T', 'art' => $wert]), null);
				$this->fail("„{$wert}“ nicht abgelehnt");
			} catch (PlanungException $e) {
				$this->assertSame(400, $e->getCode());
				$this->assertStringContainsString('„Art“: „' . $wert . '“ ist keine der Optionen', $e->getMessage());
			}
		}
		// „Kurs “ wird beim Prüfen zu „Kurs“ bereinigt und ist damit der unveränderte Altwert.
		$this->assertStringContainsString('X-CALPLAN-ART:Kurs', $planung->schreiben($ics, $planung->pruefen(['titel' => 'T', 'art' => 'Kurs ']), null));
		// Ohne gespeicherten Wert gilt nur die Liste.
		$this->expectException(PlanungException::class);
		$planung->schreiben(self::ics(), $this->eingabe(['art' => 'Kurs'], $planung), null);
	}

	/** Der gespeicherte Altwert wird vor dem Vergleich bereinigt wie die Eingabe (Leerraum, Unsichtbares, Steuerzeichen). */
	public function testAuswahlAltwertWirdVorDemVergleichBereinigt(): void {
		$planung = self::mitFeldern(['key' => 'art', 'label' => 'Art', 'typ' => 'select', 'optionen' => ['Offen']]);
		foreach (["X-CALPLAN-ART:Kurs \r\n", "X-CALPLAN-ART: Kurs\u{200B}\r\n", "X-CALPLAN-ART:Ku\u{0007}rs\r\n"] as $zeile) {
			$ics = self::ics($zeile);
			// Das Formular schickt den Altwert zurück, wie es ihn bekommen hat; pruefen() bereinigt ihn.
			$gelesen = $planung->lesen($ics)['art'];
			$neu = $planung->schreiben($ics, $planung->pruefen(['titel' => 'T', 'art' => $gelesen]), null);
			$this->assertStringContainsString("X-CALPLAN-ART:Kurs\r\n", $neu, $zeile);
		}
		// Ein anderer Wert bleibt abgelehnt.
		$this->expectExceptionMessage('„Art“: „Kursus“ ist keine der Optionen');
		$planung->schreiben(self::ics("X-CALPLAN-ART:Kurs \r\n"), $planung->pruefen(['titel' => 'T', 'art' => 'Kursus']), null);
	}

	public function testJaNeinFeld(): void {
		$planung = self::mitFeldern(['key' => 'einweisung', 'label' => 'Einweisung', 'typ' => 'checkbox']);
		$ja = $this->eingabe(['einweisung' => true], $planung);
		$this->assertTrue($ja['einweisung']);
		$this->assertFalse($this->eingabe(['einweisung' => 'ja'], $planung)['einweisung'], 'nur true, "1" oder "true" zählen');
		$this->assertTrue($this->eingabe(['einweisung' => '1'], $planung)['einweisung']);

		$ics = $planung->schreiben(self::ics(), $ja, null);
		$this->assertStringContainsString('X-CALPLAN-EINWEISUNG:1', $ics);
		$this->assertTrue($planung->lesen($ics)['einweisung']);
		$this->assertTrue($planung->lesen($ics)['geplant']);
		$nein = $planung->schreiben($ics, $this->eingabe(['einweisung' => false], $planung), null);
		$this->assertStringNotContainsString('X-CALPLAN-EINWEISUNG', $nein);
		$this->assertFalse($planung->lesen($nein)['einweisung']);

		$pflicht = self::mitFeldern(['key' => 'einweisung', 'label' => 'Einweisung', 'typ' => 'checkbox', 'pflicht' => true]);
		$this->expectException(PlanungException::class);
		$this->expectExceptionMessage('angekreuzt');
		$this->eingabe(['einweisung' => false], $pflicht);
	}

	public function testDatumsfeldUndPflicht(): void {
		$planung = self::mitFeldern(['key' => 'frist', 'label' => 'Anmeldefrist', 'typ' => 'date', 'pflicht' => true]);
		$this->assertSame('2026-11-01', $this->eingabe(['frist' => '2026-11-01'], $planung)['frist']);
		try {
			$this->eingabe(['frist' => '2026-13-01'], $planung);
			$this->fail('ungültiges Datum nicht erkannt');
		} catch (PlanungException $e) {
			$this->assertStringContainsString('Anmeldefrist', $e->getMessage());
		}
		$this->expectException(PlanungException::class);
		$this->expectExceptionMessage('„Anmeldefrist“ fehlt');
		$this->eingabe(['frist' => ''], $planung);
	}

	public function testUnbekannteEingabefelderWerdenIgnoriert(): void {
		$planung = $this->eingabe(['fremd' => 'x', 'END:VEVENT' => 'y']);
		$this->assertArrayNotHasKey('fremd', $planung);
		$this->assertArrayNotHasKey('END:VEVENT', $planung);
	}

	// Q-KAL-03: nur die eigene Link-Zeile wird ersetzt.

	public function testLinkEinfuegenLaesstFremdeZeilenMitGleichemPraefix(): void {
		$planung = new Planung(new Einstellungen('', '', 'Info:'));
		$link = 'https://cloud.example/apps/calendar_planning/?kalender=1&termin=a.ics';
		$ics = self::ics("DESCRIPTION:Info: Schlüssel beim Hausmeister\\nInfo:Treffpunkt am Tor\r\n");
		$neu = $planung->schreiben($ics, $this->eingabe([], $planung), $link);
		$zeilen = explode("\n", $planung->lesen($neu)['beschreibung']);
		$this->assertContains('Info: Schlüssel beim Hausmeister', $zeilen);
		$this->assertContains('Info:Treffpunkt am Tor', $zeilen);
		$this->assertCount(1, array_filter($zeilen, fn ($z) => $z === "Info: {$link}"));
		// Ein zweites Speichern ändert nichts mehr.
		$this->assertSame($planung->lesen($neu)['beschreibung'], $planung->lesen($planung->schreiben($neu, $this->eingabe([], $planung), $link))['beschreibung']);
	}

	public function testAlterLinkMitAltemLinktextWirdErsetzt(): void {
		$planung = new Planung(new Einstellungen('', '', 'Info:'));
		$link = 'https://cloud.example/apps/calendar_planning/?kalender=1&termin=a.ics';
		$ics = self::ics("DESCRIPTION:Mitbringen: Laptop\\n\\nPlanung öffnen: https://alt.example/apps/calendar_planning/?kalender=1&termin=a.ics\r\n");
		$beschreibung = $planung->lesen($planung->schreiben($ics, $this->eingabe([], $planung), $link))['beschreibung'];
		$this->assertStringNotContainsString('alt.example', $beschreibung);
		$this->assertSame(1, substr_count($beschreibung, $link));
		$this->assertSame("Mitbringen: Laptop\n\nInfo: {$link}", $beschreibung);
	}

	public function testNutzertextVorFremderPlanungsadresseBleibt(): void {
		$link = 'https://cloud.example/apps/calendar_planning/?kalender=1&termin=a.ics';
		$fremd = 'Siehe auch: https://cloud.example/apps/calendar_planning/?kalender=1&termin=b.ics';
		$ics = self::ics("DESCRIPTION:Mitbringen: Laptop\\n{$fremd}\r\n");
		$beschreibung = $this->planung->lesen($this->planung->schreiben($ics, $this->eingabe(), $link))['beschreibung'];
		$this->assertSame("Mitbringen: Laptop\n{$fremd}\n\nPlanung öffnen: {$link}", $beschreibung);
		// Auch eine fremde Zeile mit Linktext und Planungs-Adresse, aber Text dahinter, gehört dem Nutzer.
		$mitZusatz = "Planung öffnen: https://alt.example/apps/calendar_planning/?kalender=1&termin=a.ics (alt)";
		$ics = self::ics("DESCRIPTION:{$mitZusatz}\r\n");
		$beschreibung = $this->planung->lesen($this->planung->schreiben($ics, $this->eingabe(), $link))['beschreibung'];
		$this->assertSame("{$mitZusatz}\n\nPlanung öffnen: {$link}", $beschreibung);
	}

	public function testGemerkteLinkZeileWirdErsetztAuchMitFruehererAdresse(): void {
		$alt = 'https://alt.example/apps/calendar_planning/?kalender=1&termin=a.ics';
		$neu = 'https://neu.example/apps/calendar_planning/?kalender=1&termin=a.ics';
		$planung = new Planung(new Einstellungen('', '', 'Zum Formular:'));
		$einmal = $planung->schreiben(self::ics("DESCRIPTION:Mitbringen: Laptop\r\n"), $this->eingabe([], $planung), $alt);
		$this->assertSame("Zum Formular: {$alt}", (string)Reader::read($einmal)->VEVENT->{Planung::EIGENSCHAFT_LINK});
		// Linktext und Adresse ändern sich: die gemerkte Zeile wird ersetzt, an derselben Stelle.
		$andere = new Planung(new Einstellungen('', '', 'Info:'));
		$nachher = $andere->schreiben($einmal, $this->eingabe([], $andere), $neu);
		$this->assertSame("Mitbringen: Laptop\n\nInfo: {$neu}", $andere->lesen($nachher)['beschreibung']);
		$this->assertSame("Info: {$neu}", (string)Reader::read($nachher)->VEVENT->{Planung::EIGENSCHAFT_LINK});
		// Mit gemerkter Zeile gilt eine andere Zeile mit Vorgabe-Linktext und App-Adresse nicht als eigene.
		$kopie = "Planung öffnen: {$alt}";
		$ics = self::ics("DESCRIPTION:Info: {$neu}\\n{$kopie}\r\nX-CALPLAN-LINK:Info: {$neu}\r\n");
		$this->assertSame("Info: {$neu}\n{$kopie}", $andere->lesen($andere->schreiben($ics, $this->eingabe([], $andere), $neu))['beschreibung']);
	}

	public function testDoppelteEigeneLinkZeilenWerdenEine(): void {
		$link = 'https://cloud.example/apps/calendar_planning/?kalender=1&termin=a.ics';
		$alt = 'https://alt.example/apps/calendar_planning/?kalender=1&termin=a.ics';
		$ics = self::ics("DESCRIPTION:Planung öffnen: {$alt}\\nMitte\\nPlanung öffnen: {$link}\r\n");
		$beschreibung = $this->planung->lesen($this->planung->schreiben($ics, $this->eingabe(), $link))['beschreibung'];
		$this->assertSame("Planung öffnen: {$link}\nMitte", $beschreibung);
	}

	public function testBeschreibungMitCrLfBleibtUnveraendert(): void {
		// Die Link-Zeile steht schon da, nicht am Ende, mit \r\n-Zeilenenden (wie manche Programme schreiben).
		// Früher fiel der exakte Vergleich an „…\r“ durch, die Zeile wanderte ans Ende.
		$link = 'https://cloud.example/apps/calendar_planning/?kalender=1&termin=a.ics';
		$ics = self::ics("DESCRIPTION:Planung öffnen: {$link}\r\\n\r\\nMitbringen: Laptop\r\n");
		$vorher = (string)Reader::read($ics)->VEVENT->DESCRIPTION;
		$this->assertSame("Planung öffnen: {$link}\r\n\r\nMitbringen: Laptop", $vorher);
		$nachher = $this->planung->schreiben($ics, $this->eingabe(), $link);
		// sabre/vobject schreibt Umbrüche als \n; Reihenfolge und Inhalt bleiben.
		$this->assertSame("Planung öffnen: {$link}\n\nMitbringen: Laptop", $this->planung->lesen($nachher)['beschreibung']);

	}

	// Q-KAL-05: Floating-Zeiten gelten als Ortszeit.

	public function testFloatingZeitGiltAlsOrtszeit(): void {
		$ics = str_replace(
			["DTSTART;TZID=Europe/Berlin:20261105T140000", "DTEND;TZID=Europe/Berlin:20261105T170000"],
			["DTSTART:20261105T140000", "DTEND:20261105T170000"],
			self::ics(),
		);
		$daten = $this->planung->lesen($ics, null, null, new DateTimeZone('Europe/Berlin'));
		$this->assertSame('2026-11-05T14:00:00+01:00', $daten['beginn']);
		$this->assertSame('2026-11-05T17:00:00+01:00', $daten['ende']);
		// Sommerzeit: dieselbe Wanduhrzeit, anderer Abstand zu UTC.
		$sommer = str_replace('202611', '202607', $ics);
		$this->assertSame('2026-07-05T14:00:00+02:00', $this->planung->lesen($sommer, null, null, new DateTimeZone('Europe/Berlin'))['beginn']);
		// Mit Zeitraum gilt dessen Zone, auch ohne eigene Angabe.
		$zone = new DateTimeZone('Europe/Berlin');
		$imZeitraum = $this->planung->lesen($ics, new DateTimeImmutable('2026-11-01', $zone), new DateTimeImmutable('2026-12-01', $zone));
		$this->assertSame('2026-11-05T14:00:00+01:00', $imZeitraum['beginn']);
	}

	public function testFloatingDauerUndSerie(): void {
		$ics = str_replace(
			["DTSTART;TZID=Europe/Berlin:20261105T140000", "DTEND;TZID=Europe/Berlin:20261105T170000"],
			["DTSTART:20261105T140000", "DURATION:PT2H\r\nRRULE:FREQ=WEEKLY;COUNT=5"],
			self::ics(),
		);
		$zone = new DateTimeZone('Europe/Berlin');
		$daten = $this->planung->lesen($ics, new DateTimeImmutable('2026-11-10', $zone), new DateTimeImmutable('2026-12-31', $zone), $zone);
		$this->assertSame('2026-11-12T14:00:00+01:00', $daten['beginn']);
		$this->assertSame('2026-11-12T16:00:00+01:00', $daten['ende']);
	}

	public function testUtcUndTzidBleibenUnveraendertGanztagsAuch(): void {
		$zone = new DateTimeZone('Europe/Berlin');
		$utc = str_replace(
			["DTSTART;TZID=Europe/Berlin:20261105T140000", "DTEND;TZID=Europe/Berlin:20261105T170000"],
			["DTSTART:20261105T130000Z", "DTEND:20261105T160000Z"],
			self::ics(),
		);
		$this->assertSame('2026-11-05T13:00:00+00:00', $this->planung->lesen($utc, null, null, $zone)['beginn']);
		$this->assertSame('2026-11-05T14:00:00+01:00', $this->planung->lesen(self::ics(), null, null, new DateTimeZone('America/New_York'))['beginn']);
		$ganztags = str_replace(
			["DTSTART;TZID=Europe/Berlin:20261105T140000", "DTEND;TZID=Europe/Berlin:20261105T170000"],
			["DTSTART;VALUE=DATE:20261105", "DTEND;VALUE=DATE:20261106"],
			self::ics(),
		);
		$daten = $this->planung->lesen($ganztags, null, null, $zone);
		$this->assertSame('2026-11-05T00:00:00+00:00', $daten['beginn']);
		$this->assertSame('2026-11-06T00:00:00+00:00', $daten['ende']);
	}

	// S-KAL-06: unsichtbare und Richtungszeichen.

	public function testUnsichtbareUndRichtungszeichenFliegenRaus(): void {
		$rlo = "\u{202E}";
		$planung = $this->eingabe([
			'titel' => "Löt{$rlo}workshop\u{200B}\u{2066}Grund\u{2069}schule\u{FEFF}",
			'kontakt_name' => "\u{200E}Eva\u{200F} Muster\u{061C}\u{00AD}",
			'hinweise' => "Zeile\u{202A} 1\nZeile\u{2060} 2\u{202C}",
			'kontakt_email' => "eva\u{200B}@example.org",
		]);
		$this->assertSame('LötworkshopGrundschule', $planung['titel']);
		$this->assertSame('Eva Muster', $planung['kontakt_name']);
		$this->assertSame("Zeile 1\nZeile 2", $planung['hinweise']);
		$this->assertSame('eva@example.org', $planung['kontakt_email']);
		// Emoji-Folgen und Schriften mit Verbindern bleiben ganz.
		$this->assertSame("Team \u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}", $this->eingabe(['titel' => "Team \u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}"])['titel']);
		// Nur unsichtbare Zeichen gelten als leer.
		$this->expectException(PlanungException::class);
		$this->eingabe(['titel' => "\u{200B}\u{202E}\u{FEFF}"]);
	}

	public function testWeitereUnsichtbareZeichenUndTrenner(): void {
		// Tag-Zeichen (versteckter Text), Graphem-Verbinder, Hangul-Füllzeichen.
		$tags = "\u{E0001}\u{E0067}\u{E0065}\u{E0068}\u{E007F}";
		$planung = $this->eingabe([
			'titel' => "Löt{$tags}workshop\u{034F}",
			'kontakt_name' => "\u{115F}Eva\u{1160} \u{3164}Muster\u{FFA0}",
			// Zeilen- und Absatztrenner: einzeilig zu Leerzeichen, mehrzeilig zu Umbrüchen.
			'kontakt_organisation' => "Verein\u{2028}Nord",
			'hinweise' => "Zeile 1\u{2028}Zeile 2\u{2029}Zeile 3",
		]);
		$this->assertSame('Lötworkshop', $planung['titel']);
		$this->assertSame('Eva Muster', $planung['kontakt_name']);
		$this->assertSame('Verein Nord', $planung['kontakt_organisation']);
		$this->assertSame("Zeile 1\nZeile 2\nZeile 3", $planung['hinweise']);
		$e = new Einstellungen("Vereins\u{2029}heim\u{E0041}", '', "Info\u{3164}:");
		$this->assertSame('Vereins heim', $e->ortVorgabe);
		$this->assertSame('Info: ', $e->linkText);
		foreach (["\u{E0000}", "\u{E007F}", "\u{034F}", "\u{115F}", "\u{1160}", "\u{3164}", "\u{FFA0}", "\u{2028}", "\u{2029}"] as $zeichen) {
			$this->assertSame('ab', Einstellungen::ohneUnsichtbare("a{$zeichen}b"), bin2hex($zeichen));
		}
	}

	public function testEinstellungenOhneUnsichtbareZeichen(): void {
		$e = new Einstellungen("Vereins\u{202E}heim\u{200B}", "Pla\u{2067}nung", "Info\u{FEFF}:");
		$this->assertSame('Vereinsheim', $e->ortVorgabe);
		$this->assertSame('Planung', $e->kalenderMuster);
		$this->assertSame('Info: ', $e->linkText);
	}
}
