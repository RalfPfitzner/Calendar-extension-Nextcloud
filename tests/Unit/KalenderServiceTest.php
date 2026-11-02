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

/** Liste mit defektem Termin und Sperre beim Speichern, mit Attrappen von Backend und Sperrdienst. */
class KalenderServiceTest extends TestCase {
	private const GEHEIM = 'Geheim eva.muster@example.org';

	/** @var list<array{0:mixed,1:string}> */
	public array $protokoll = [];

	private CalDavBackend&MockObject $backend;
	private ILockingProvider&MockObject $sperren;

	private static function ics(string $uid, string $titel): string {
		return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//DE\r\nBEGIN:VEVENT\r\nUID:{$uid}\r\nDTSTAMP:20261001T000000Z\r\n"
			. "DTSTART;TZID=Europe/Berlin:20261105T140000\r\nDTEND;TZID=Europe/Berlin:20261105T170000\r\n"
			. "SUMMARY:{$titel}\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
	}

	private function service(): KalenderService {
		$kalender = $this->createMock(CalendarImpl::class);
		$kalender->method('getKey')->willReturn('3');
		$kalender->method('getUri')->willReturn('planung');
		$kalender->method('getDisplayName')->willReturn('Planung');
		$kalender->method('isDeleted')->willReturn(false);
		$kalender->method('isShared')->willReturn(false);
		$kalender->method('isWritable')->willReturn(true);
		$manager = $this->createMock(IManager::class);
		$manager->method('getCalendarsForPrincipal')->willReturn([$kalender]);
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
		$einstellungen = new Einstellungen('Vereinsheim');
		return new KalenderService($manager, $this->backend, $url, $this->createMock(IUserConfig::class),
			new Planung($einstellungen), $einstellungen, $this->sperren, $logger);
	}

	public function testDefekterTerminLaesstDieListeNichtScheitern(): void {
		$service = $this->service();
		$this->backend->method('calendarQuery')->willReturn(['gut.ics', 'kaputt.ics']);
		$this->backend->method('getMultipleCalendarObjects')->willReturn([
			['uri' => 'gut.ics', 'calendardata' => self::ics('gut', 'Lötworkshop'), 'classification' => 0],
			['uri' => 'kaputt.ics', 'calendardata' => "BEGIN:VCALENDAR\r\nSUMMARY:" . self::GEHEIM . "\r\nBEGIN:VEVENT\r\n", 'classification' => 0],
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
}
