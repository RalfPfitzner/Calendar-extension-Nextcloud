<?php

declare(strict_types=1);

namespace OCA\MspPlanung\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use OCA\MspPlanung\Service\Planung;
use OCA\MspPlanung\Service\PlanungException;
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Reader;

class PlanungTest extends TestCase {
	private Planung $planung;

	protected function setUp(): void {
		$this->planung = new Planung();
	}

	private static function ics(string $zusatz = '', string $weitere = ''): string {
		return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//DE\r\n"
			. "BEGIN:VEVENT\r\nUID:t1\r\nDTSTAMP:20261001T000000Z\r\n"
			. "DTSTART;TZID=Europe/Berlin:20261105T140000\r\nDTEND;TZID=Europe/Berlin:20261105T170000\r\n"
			. "SUMMARY:Lötworkshop\r\nATTENDEE;CN=Gast:mailto:gast@example.org\r\nX-FREMD:bleibt\r\n{$zusatz}END:VEVENT\r\n"
			. $weitere . "END:VCALENDAR\r\n";
	}

	private function eingabe(array $werte = []): array {
		$leer = array_fill_keys(array_keys(Planung::FELDER), '');
		return $this->planung->pruefen($werte + ['titel' => 'Lötworkshop', 'ort_extern' => false, 'ort' => ''] + $leer);
	}

	public function testLesenOhnePlanungSetztOrtVorgabe(): void {
		$daten = $this->planung->lesen(self::ics());
		$this->assertSame('Lötworkshop', $daten['titel']);
		$this->assertSame(Planung::ORT_VORGABE, $daten['ort']);
		$this->assertFalse($daten['ort_extern']);
		$this->assertFalse($daten['geplant']);
		$this->assertSame('2026-11-05T14:00:00+01:00', $daten['beginn']);
		$this->assertSame('2026-11-05T17:00:00+01:00', $daten['ende']);
		$this->assertSame('', $daten['hinweise']);
	}

	public function testSchreibenUndLesenErgibtDieselbenWerte(): void {
		$planung = $this->eingabe([
			'ort_extern' => true,
			'ort' => "Grundschule Bendorf\nSchulstraße 1",
			'zustaendig' => 'Eva',
			'kontakt_email' => 'lehrer@example.org',
			'hinweise' => "Zeile 1\r\nZeile 2; mit, Komma und \\ Backslash",
			'teilnehmende' => '24',
		]);
		$neu = $this->planung->schreiben(self::ics(), $planung, 'https://cloud.example.org/apps/msp_planung/?kalender=3&termin=t1.ics');
		$daten = $this->planung->lesen($neu);

		$this->assertTrue($daten['geplant']);
		$this->assertTrue($daten['ort_extern']);
		$this->assertSame("Grundschule Bendorf\nSchulstraße 1", $daten['ort']);
		$this->assertSame('Eva', $daten['zustaendig']);
		$this->assertSame("Zeile 1\nZeile 2; mit, Komma und \\ Backslash", $daten['hinweise']);
		$this->assertSame('24', $daten['teilnehmende']);
		$this->assertStringContainsString('X-MSP-ZUSTAENDIG:Eva', $neu);
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
		$this->assertStringContainsString('X-MSP-RAUM', $mit);
		$this->assertStringNotContainsString('X-MSP-RAUM', $ohne);
	}

	public function testInternerOrtSchreibtDieVorgabe(): void {
		$neu = $this->planung->schreiben(self::ics("LOCATION:Irgendwo\r\n"), $this->eingabe(['ort' => 'wird ignoriert']), null);
		$this->assertSame(Planung::ORT_VORGABE, (string)Reader::read($neu)->VEVENT->LOCATION);
	}

	public function testLinkWirdEinmalAngehaengtUndAlterErsetzt(): void {
		$alt = 'https://alt.example.org/apps/msp_planung/?kalender=1&termin=t1.ics';
		$neu = 'https://cloud.example.org/apps/msp_planung/?kalender=3&termin=t1.ics';
		$einmal = $this->planung->schreiben(self::ics("DESCRIPTION:Mitbringen: Laptop\r\n"), $this->eingabe(), $alt);
		$zweimal = $this->planung->schreiben($einmal, $this->eingabe(), $alt);
		$this->assertSame("Mitbringen: Laptop\n\nPlanung öffnen: {$alt}", $this->planung->lesen($zweimal)['beschreibung']);

		$umgezogen = $this->planung->schreiben($zweimal, $this->eingabe(), $neu);
		$this->assertSame("Mitbringen: Laptop\n\nPlanung öffnen: {$neu}", $this->planung->lesen($umgezogen)['beschreibung']);
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
			$this->assertSame('Eva', (string)$ereignis->{'X-MSP-ZUSTAENDIG'});
		}
		// Der abweichende Ort der Ausnahme bleibt, der der Serie wird gesetzt.
		$orte = array_map(fn ($e) => (string)$e->LOCATION, $ereignisse);
		$this->assertSame([Planung::ORT_VORGABE, 'Sonderort'], $orte);
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
		}
		$this->expectException(PlanungException::class);
		$this->eingabe(['hinweise' => ['kein', 'text']]);
	}

	public function testPruefenNurEchtesTrueIstExtern(): void {
		$this->assertFalse($this->eingabe(['ort_extern' => 'true', 'ort' => 'x'])['ort_extern']);
	}
}
