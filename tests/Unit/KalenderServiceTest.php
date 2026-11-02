<?php

declare(strict_types=1);

namespace OCA\CalendarPlanning\Tests\Unit;

use OCA\CalendarPlanning\Service\Einstellungen;
use OCA\CalendarPlanning\Service\KalenderService;
use OCA\CalendarPlanning\Service\Planung;
use OCA\CalendarPlanning\Service\PlanungException;
use OCA\DAV\CalDAV\CalDavBackend;
use OCA\DAV\CalDAV\CalendarImpl;
use OCP\Calendar\IManager;
use OCP\Config\IUserConfig;
use OCP\IURLGenerator;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/** Liste (Laden, Zeitraum, Suche, Sortierung), Kalenderauswahl und Sperre beim Speichern, mit Attrappen von Backend und Sperrdienst. */
class KalenderServiceTest extends TestCase {
	private const GEHEIM = 'Geheim eva.muster@example.org';

	/** @var list<array{0:mixed,1:string}> */
	public array $protokoll = [];

	private CalDavBackend&MockObject $backend;
	private ILockingProvider&MockObject $sperren;
	private IManager&MockObject $manager;

	private static function ics(string $uid, string $titel): string {
		return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//DE\r\nBEGIN:VEVENT\r\nUID:{$uid}\r\nDTSTAMP:20261001T000000Z\r\n"
			. "DTSTART;TZID=Europe/Berlin:20261105T140000\r\nDTEND;TZID=Europe/Berlin:20261105T170000\r\n"
			. "SUMMARY:{$titel}\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
	}

	/** Termin mit Zeilen für DTSTART/DTEND und weiteren Eigenschaften. */
	private static function termin(string $uid, string $zeiten, string $weitere = ''): string {
		return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//DE\r\nBEGIN:VEVENT\r\nUID:{$uid}\r\nDTSTAMP:20261001T000000Z\r\n"
			. $zeiten . $weitere . "END:VEVENT\r\nEND:VCALENDAR\r\n";
	}

	private function kalenderAttrappe(string $id, string $uri, string $name, bool $geteilt = false): CalendarImpl&MockObject {
		$kalender = $this->createMock(CalendarImpl::class);
		$kalender->method('getKey')->willReturn($id);
		$kalender->method('getUri')->willReturn($uri);
		$kalender->method('getDisplayName')->willReturn($name);
		$kalender->method('isDeleted')->willReturn(false);
		$kalender->method('isShared')->willReturn($geteilt);
		$kalender->method('isWritable')->willReturn(!$geteilt);
		return $kalender;
	}

	/** @param list<CalendarImpl>|null $kalender */
	private function service(?array $kalender = null, ?Einstellungen $einstellungen = null): KalenderService {
		$this->manager = $this->createMock(IManager::class);
		$this->manager->method('getCalendarsForPrincipal')->willReturn($kalender ?? [$this->kalenderAttrappe('3', 'planung', 'Planung')]);
		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturn('https://cloud.example/apps/calendar_planning/');
		$this->backend = $this->createMock(CalDavBackend::class);
		$this->sperren = $this->createMock(ILockingProvider::class);
		$test = $this;
		$logger = new class($test) extends AbstractLogger {
			public function __construct(private KalenderServiceTest $test) {
			}

			public function log($level, \Stringable|string $message, array $context = []): void {
				$this->test->protokoll[] = [$level, (string)$message . json_encode($context)];
			}
		};
		$einstellungen ??= new Einstellungen('Vereinsheim');
		return new KalenderService($this->manager, $this->backend, $url, $this->createMock(IUserConfig::class),
			new Planung($einstellungen), $einstellungen, $this->sperren, $logger);
	}

	/**
	 * Backend liefert diese Objekte (URI => iCalendar) aus calendarQuery; jedes wird genau einmal geholt.
	 *
	 * @param array<string,string> $objekte
	 */
	private function objekte(array $objekte, int $klasse = 0): void {
		$geholt = [];
		$this->backend->method('calendarQuery')->willReturnCallback(function () use ($objekte, &$geholt) {
			$geholt = [];
			return array_keys($objekte);
		});
		$this->backend->expects($this->never())->method('getMultipleCalendarObjects');
		$this->backend->method('getCalendarObject')->willReturnCallback(function ($kalender, $uri) use ($objekte, $klasse, &$geholt) {
			$this->assertArrayNotHasKey($uri, $geholt, "{$uri} doppelt geladen");
			$geholt[$uri] = true;
			return ['uri' => $uri, 'calendardata' => $objekte[$uri], 'etag' => '"e"', 'classification' => $klasse];
		});
	}

	/** @return list<string> Objekt-URIs der Liste in ihrer Reihenfolge */
	private static function uris(array $liste): array {
		return array_column($liste['termine'], 'objekt');
	}

	public function testDefekterTerminLaesstDieListeNichtScheitern(): void {
		$service = $this->service();
		$this->objekte([
			'gut.ics' => self::ics('gut', 'Lötworkshop'),
			'kaputt.ics' => "BEGIN:VCALENDAR\r\nSUMMARY:" . self::GEHEIM . "\r\nBEGIN:VEVENT\r\n",
		]);
		[$von, $bis] = KalenderService::zeitraum('2026-11-01', '2026-11-30');
		$liste = $service->termine('admin', 3, $von, $bis, '', 1, 25);

		$this->assertSame(1, $liste['gesamt']);
		$this->assertSame('Lötworkshop', $liste['termine'][0]['titel']);
		$this->assertCount(1, $this->protokoll);
		[$stufe, $text] = $this->protokoll[0];
		$this->assertSame('warning', $stufe);
		$this->assertStringContainsString('Termin in der Liste übersprungen', $text);
		$this->assertStringContainsString('Termin #' . substr(hash('sha256', 'kaputt.ics'), 0, 12), $text);
		$this->assertStringNotContainsString('Geheim', $text);
		$this->assertStringNotContainsString('example.org', $text);
		$this->assertStringNotContainsString('kaputt.ics', $text);
	}

	public function testBelegteSperreBittetUmErneutenVersuch(): void {
		$service = $this->service();
		$this->sperren->method('acquireLock')->willThrowException(new LockedException('calendar_planning/x'));
		$this->sperren->expects($this->never())->method('releaseLock');
		$this->backend->expects($this->never())->method('updateCalendarObject');
		try {
			$service->speichern('admin', 3, 'a.ics', '"e1"', ['titel' => 'Lötworkshop']);
			$this->fail('belegte Sperre nicht gemeldet');
		} catch (PlanungException $e) {
			$this->assertSame(409, $e->getCode());
			$this->assertSame(KalenderService::GERADE_GESPEICHERT, $e->getMessage());
			$this->assertStringNotContainsString('neu laden', $e->getMessage());
		}
	}

	public function testFehlerBeiDerFreigabeUeberschreibtDasErgebnisNicht(): void {
		$service = $this->service();
		$ics = self::ics('a', 'Lötworkshop');
		$this->backend->method('getCalendarObject')->willReturnCallback(function () use (&$ics) {
			return ['uri' => 'a.ics', 'calendardata' => $ics, 'etag' => '"' . md5($ics) . '"', 'classification' => 0];
		});
		$this->backend->expects($this->once())->method('updateCalendarObject')->willReturnCallback(function ($k, $u, $neu) use (&$ics) {
			$ics = $neu;
			return '"' . md5($neu) . '"';
		});
		$this->sperren->expects($this->once())->method('releaseLock')->willThrowException(new \RuntimeException(self::GEHEIM, 42));

		$termin = $service->speichern('admin', 3, 'a.ics', '"' . md5($ics) . '"', ['titel' => 'Lötworkshop neu']);

		$this->assertSame('Lötworkshop neu', $termin['titel']);
		$this->assertCount(1, $this->protokoll);
		$this->assertStringContainsString('Sperre nicht freigegeben, RuntimeException (Code 42,', $this->protokoll[0][1]);
		$this->assertStringNotContainsString('Geheim', $this->protokoll[0][1]);
	}

	public function testFehlerBeiDerFreigabeUeberschreibtDenKonfliktNicht(): void {
		$service = $this->service();
		$this->backend->method('getCalendarObject')->willReturn(['uri' => 'a.ics', 'calendardata' => self::ics('a', 'x'), 'etag' => '"neu"', 'classification' => 0]);
		$this->backend->expects($this->never())->method('updateCalendarObject');
		$this->sperren->expects($this->once())->method('releaseLock')->willThrowException(new \Error('kaputt'));
		try {
			$service->speichern('admin', 3, 'a.ics', '"alt"', ['titel' => 'Lötworkshop']);
			$this->fail('Konflikt nicht gemeldet');
		} catch (PlanungException $e) {
			$this->assertSame(409, $e->getCode());
			$this->assertStringContainsString('inzwischen geändert', $e->getMessage());
		}
		$this->assertCount(1, $this->protokoll);
	}

	public function testListeFragtDasBackendNurPerSqlUndPrueftDenZeitraumSelbst(): void {
		// P-KAL-02: Mit Anfang und Ende zugleich würde das Backend jedes Objekt parsen und Serien auflösen
		// (requirePostFilter); nur mit dem Anfang filtert es allein per SQL. Den Rest prüft die App.
		$service = $this->service();
		[$von, $bis] = KalenderService::zeitraum('2026-11-01', '2026-11-30');
		$this->backend->expects($this->once())->method('calendarQuery')->with(3, $this->callback(function (array $filter) use ($von) {
			$zeitraum = $filter['comp-filters'][0]['time-range'];
			return $zeitraum['start'] == $von && $zeitraum['end'] === null && $filter['comp-filters'][0]['name'] === 'VEVENT';
		}))->willReturn(['drin.ics', 'vorher.ics', 'danach.ics', 'serie-vorbei.ics']);
		$objekte = [
			'drin.ics' => self::ics('drin', 'Im Zeitraum'),
			// endet genau mit dem Beginn des Zeitraums: nicht drin (DTEND ist ausschließlich)
			'vorher.ics' => self::termin('vorher', "DTSTART;TZID=Europe/Berlin:20261031T220000\r\nDTEND;TZID=Europe/Berlin:20261101T000000\r\n"),
			'danach.ics' => self::termin('danach', "DTSTART;TZID=Europe/Berlin:20261201T000000\r\nDTEND;TZID=Europe/Berlin:20261201T010000\r\n"),
			'serie-vorbei.ics' => self::termin('serie', "DTSTART;TZID=Europe/Berlin:20260105T100000\r\nDTEND;TZID=Europe/Berlin:20260105T110000\r\n",
				"RRULE:FREQ=WEEKLY;UNTIL=20261030T000000Z\r\n"),
		];
		$geholt = [];
		$this->backend->method('getCalendarObject')->willReturnCallback(function ($kalender, $uri) use ($objekte, &$geholt) {
			$geholt[] = $uri;
			return ['uri' => $uri, 'calendardata' => $objekte[$uri], 'etag' => '"e"', 'classification' => 0];
		});
		$this->backend->expects($this->never())->method('getMultipleCalendarObjects');

		$liste = $service->termine('admin', 3, $von, $bis, '', 1, 25);

		$this->assertSame(['drin.ics'], self::uris($liste));
		$this->assertSame(1, $liste['gesamt']);
		$this->assertSame(['drin.ics', 'vorher.ics', 'danach.ics', 'serie-vorbei.ics'], $geholt, 'jedes Objekt genau einmal');
		$this->assertSame([], $this->protokoll);
	}

	public function testSortierungNachZeitpunktAlsZahlMitStabilerZweitsortierung(): void {
		// Q-KAL-04: „…T13:30:00+00:00“ (UTC) ist später als „…T14:00:00+01:00“ (13:00 UTC), als Text aber kleiner.
		// Ganztägig zählt ab Mitternacht in Berlin, also vor 00:30 Uhr Berliner Zeit am selben Tag.
		$service = $this->service();
		$this->objekte([
			'utc.ics' => self::termin('utc', "DTSTART:20261105T133000Z\r\nDTEND:20261105T140000Z\r\n", "SUMMARY:B UTC\r\n"),
			'berlin.ics' => self::termin('berlin', "DTSTART;TZID=Europe/Berlin:20261105T140000\r\nDTEND;TZID=Europe/Berlin:20261105T150000\r\n", "SUMMARY:A Berlin\r\n"),
			'nacht.ics' => self::termin('nacht', "DTSTART;TZID=Europe/Berlin:20261105T003000\r\nDTEND;TZID=Europe/Berlin:20261105T010000\r\n", "SUMMARY:Nacht\r\n"),
			'ganztags.ics' => self::termin('ganztags', "DTSTART;VALUE=DATE:20261105\r\nDTEND;VALUE=DATE:20261106\r\n", "SUMMARY:Ganztags\r\n"),
			'ny.ics' => self::termin('ny', "DTSTART;TZID=America/New_York:20261105T075000\r\nDTEND;TZID=America/New_York:20261105T085000\r\n", "SUMMARY:New York\r\n"),
			// gleiche Zeit wie berlin.ics: Titel entscheidet (ohne Groß-/Kleinschreibung), bei gleichem Titel die URI
			'b2.ics' => self::termin('b2', "DTSTART;TZID=Europe/Berlin:20261105T140000\r\nDTEND;TZID=Europe/Berlin:20261105T150000\r\n", "SUMMARY:B gleich\r\n"),
			'b1.ics' => self::termin('b1', "DTSTART:20261105T130000Z\r\nDTEND:20261105T140000Z\r\n", "SUMMARY:b Gleich\r\n"),
		]);
		[$von, $bis] = KalenderService::zeitraum('2026-11-01', '2026-11-30');

		$liste = $service->termine('admin', 3, $von, $bis, '', 1, 25);

		// ny.ics: 07:50 New York = 12:50 UTC, vor 13:00 UTC
		$this->assertSame(['ganztags.ics', 'nacht.ics', 'ny.ics', 'berlin.ics', 'b1.ics', 'b2.ics', 'utc.ics'], self::uris($liste));
		// Seiten schneiden erst nach dem Sortieren
		$this->assertSame(['b1.ics', 'b2.ics'], self::uris($service->termine('admin', 3, $von, $bis, '', 3, 2)));
	}

	public function testSucheNurInSichtbarenFeldern(): void {
		// Q-KAL-06, U-KAL-11: Titel, Ort (auch die Vorgabe), Planungsfelder, Datum als TT.MM.JJJJ; nicht Beschreibung
		// mit Link, nicht UID, nicht das ISO-Datum. Groß-/Kleinschreibung und Umlaute egal.
		$link = 'Planung öffnen: https://cloud.example/apps/calendar_planning/?kalender=3&termin=a.ics';
		$ics =str_replace("SUMMARY:", "DESCRIPTION:Geheimtext\\n\\n{$link}\r\nX-CALPLAN-ZUSTAENDIG:Jörg Müller\r\nX-CALPLAN-LINK:{$link}\r\nSUMMARY:", self::ics('uid-geheimnis-4711', 'Lötworkshop Straße'));
		$service = $this->service();
		$this->objekte(['a.ics' => $ics]);
		[$von, $bis] = KalenderService::zeitraum('2026-11-01', '2026-11-30');
		$treffer = fn (string $suche) => $service->termine('admin', 3, $von, $bis, $suche, 1, 25)['gesamt'];

		foreach (['LÖTWORKSHOP', 'loetworkshop', 'strasse', 'STRASSE', 'Straße', 'vereinsheim', 'jörg', 'Joerg MÜLLER', '05.11.2026', '05.11.', "Lo\u{0308}t", '  Löt  '] as $suche) {
			$this->assertSame(1, $treffer($suche), "„{$suche}“ sollte treffen");
		}
		foreach (['Geheimtext', 'Planung öffnen', 'calendar_planning', 'cloud.example', 'uid-geheimnis', '4711', '2026-11-05', 'T14:00', '+01:00', 'Lot'] as $suche) {
			$this->assertSame(0, $treffer($suche), "„{$suche}“ darf nicht treffen");
		}
	}

	public function testSerieZeigtDenNaechstenTerminImZeitraum(): void {
		// U-KAL-16: Standard-Zeitraum ab 30 Tagen vor heute; die Serie zeigt den nächsten Termin ab jetzt, nicht den
		// ersten im Zeitraum und nicht den ersten der Serie überhaupt.
		$service = $this->service();
		$this->objekte(['serie.ics' => self::termin('serie', "DTSTART;TZID=Europe/Berlin:20260105T100000\r\nDTEND;TZID=Europe/Berlin:20260105T110000\r\n",
			"RRULE:FREQ=WEEKLY\r\nSUMMARY:Offene Werkstatt\r\n")]);
		[$von, $bis] = KalenderService::zeitraum('2026-10-03', '2027-11-02');
		$jetzt = new \DateTimeImmutable('2026-11-02 12:00', new \DateTimeZone('Europe/Berlin'));

		$termin = $service->termine('admin', 3, $von, $bis, '', 1, 25, $jetzt)['termine'][0];

		$this->assertSame('2026-11-09T10:00:00+01:00', $termin['beginn']);
		$this->assertSame('2026-11-09T11:00:00+01:00', $termin['ende']);
		$this->assertTrue($termin['serie']);
	}

	public function testKalenderauswahlOhneSystemkalender(): void {
		// U-KAL-21: Geburtstage aus den Kontakten und Kalender anderer Apps sind keine Planungskalender.
		$service = $this->service([
			$this->kalenderAttrappe('2', 'contact_birthdays', 'Contact birthdays'),
			$this->kalenderAttrappe('3', 'planung', 'Planung'),
			$this->kalenderAttrappe('4', 'app-generated--deck--board-1', 'Deck: Board'),
			$this->kalenderAttrappe('5', 'personal_shared_by_ralf', 'Persönlich (ralf)', true),
			$this->kalenderAttrappe('6', 'contact_birthdays_shared_by_ralf', 'Geburtstage (ralf)', true),
		]);

		$liste = $service->kalender('admin');

		$this->assertSame(['Persönlich (ralf)', 'Planung'], array_column($liste, 'name'));
		$this->expectException(PlanungException::class);
		$this->expectExceptionCode(404);
		$service->termine('admin', 2, ...[...KalenderService::zeitraum('2026-11-01', '2026-11-30'), '', 1, 25]);
	}

	public function testKalenderlisteEinmalJeAnfrage(): void {
		// P-KAL-04: Speichern prüft die Rechte und liest danach den Termin; die Kalenderliste wird dafür einmal gebaut.
		$service = $this->service();
		$this->manager->expects($this->once())->method('getCalendarsForPrincipal');
		$ics = self::ics('a', 'Lötworkshop');
		$this->backend->method('getCalendarObject')->willReturnCallback(function () use (&$ics) {
			return ['uri' => 'a.ics', 'calendardata' => $ics, 'etag' => '"' . md5($ics) . '"', 'classification' => 0];
		});
		$this->backend->method('updateCalendarObject')->willReturnCallback(function ($k, $u, $neu) use (&$ics) {
			$ics = $neu;
			return '"' . md5($neu) . '"';
		});

		$service->speichern('admin', 3, 'a.ics', '"' . md5($ics) . '"', ['titel' => 'Lötworkshop neu']);
		$service->kalender('admin');
	}
}
