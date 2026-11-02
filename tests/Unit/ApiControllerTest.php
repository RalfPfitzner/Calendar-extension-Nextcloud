<?php

declare(strict_types=1);

namespace OCA\CalendarPlanning\Tests\Unit;

use OCA\CalendarPlanning\Controller\ApiController;
use OCA\CalendarPlanning\Service\Einstellungen;
use OCA\CalendarPlanning\Service\KalenderService;
use OCA\CalendarPlanning\Service\PlanungException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/** S-KAL-01: Unerwartete Fehler landen ohne Termininhalt und ohne Ausnahme-Objekt im Protokoll. */
class ApiControllerTest extends TestCase {
	private const GEHEIM = 'Geheimer Termin mit kim.muster@example.org, Tel. 0123 456789';

	/** @var list<array{0:mixed,1:string,2:array<mixed>}> */
	private array $protokoll = [];

	private function controller(KalenderService $service): ApiController {
		$nutzer = $this->createMock(IUser::class);
		$nutzer->method('getUID')->willReturn('admin');
		$sitzung = $this->createMock(IUserSession::class);
		$sitzung->method('getUser')->willReturn($nutzer);
		$test = $this;
		$logger = new class($test) extends AbstractLogger {
			public function __construct(private ApiControllerTest $test) {
			}

			public function log($level, \Stringable|string $message, array $context = []): void {
				$this->test->protokollieren($level, (string)$message, $context);
			}
		};
		return new ApiController($this->createMock(IRequest::class), $sitzung, $service, new Einstellungen(), $logger);
	}

	/** @param array<mixed> $kontext */
	public function protokollieren(mixed $stufe, string $text, array $kontext): void {
		$this->protokoll[] = [$stufe, $text, $kontext];
	}

	public function testInternerFehlerOhneInhaltImProtokoll(): void {
		$service = $this->createMock(KalenderService::class);
		$innen = new \InvalidArgumentException('DESCRIPTION:' . self::GEHEIM, 7);
		$service->method('speichern')->willThrowException(new \RuntimeException('Fehler bei ' . self::GEHEIM, 0, $innen));

		$antwort = $this->controller($service)->speichern(3, 'geheim-termin.ics', '"etag"', ['titel' => self::GEHEIM]);

		$this->assertSame(500, $antwort->getStatus());
		$this->assertSame(['fehler' => 'Interner Fehler, Details im Nextcloud-Protokoll'], $antwort->getData());
		$this->assertCount(1, $this->protokoll);
		[$stufe, $text, $kontext] = $this->protokoll[0];
		$this->assertSame('error', $stufe);
		$this->assertArrayNotHasKey('exception', $kontext);
		$alles = $text . json_encode($kontext);
		foreach (['Geheim', 'example.org', '456789', 'DESCRIPTION', 'geheim-termin'] as $verboten) {
			$this->assertStringNotContainsStringIgnoringCase($verboten, $alles);
		}
		// Was zur Fehlersuche bleibt: Klassen, Codes, Stelle, Kalender und ein Hash des Termins.
		$this->assertStringContainsString('RuntimeException (Code 0, ApiControllerTest.php:', $text);
		$this->assertStringContainsString('InvalidArgumentException (Code 7,', $text);
		$this->assertStringContainsString('Kalender 3', $text);
		$this->assertStringContainsString('Termin #' . substr(hash('sha256', 'geheim-termin.ics'), 0, 12), $text);
	}

	public function testListeOhneTerminbezug(): void {
		$service = $this->createMock(KalenderService::class);
		$service->method('termine')->willThrowException(new \Error(self::GEHEIM));
		$antwort = $this->controller($service)->termine(5, '2026-11-01', '2026-11-30');
		$this->assertSame(500, $antwort->getStatus());
		$this->assertStringNotContainsString('Geheim', $this->protokoll[0][1]);
		$this->assertStringContainsString('Kalender 5', $this->protokoll[0][1]);
		$this->assertStringNotContainsString('Termin #', $this->protokoll[0][1]);
	}

	public function testFehlerFuerNutzerNichtImProtokoll(): void {
		$service = $this->createMock(KalenderService::class);
		$service->method('termin')->willThrowException(new PlanungException('Termin nicht gefunden', 404));
		$antwort = $this->controller($service)->termin(3, 'x.ics');
		$this->assertSame(404, $antwort->getStatus());
		$this->assertSame(['fehler' => 'Termin nicht gefunden'], $antwort->getData());
		$this->assertSame([], $this->protokoll);
	}
}
