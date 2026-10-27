<?php

declare(strict_types=1);

namespace OCA\CalendarPlanning\Controller;

use OCA\CalendarPlanning\AppInfo\Application;
use OCA\CalendarPlanning\Service\Einstellungen;
use OCA\CalendarPlanning\Service\KalenderService;
use OCA\CalendarPlanning\Service\PlanungException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * JSON-Schnittstelle für die Oberfläche. Prüft nur Eingaben und reicht an
 * den KalenderService weiter.
 *
 * Die Rate-Limits je Konto (Nextcloud antwortet 429) schützen die Instanz vor Last:
 * `termine` lädt bis zu drei Jahre Termine und löst Serien auf, `speichern` schreibt ins Backend.
 * Die Oberfläche braucht pro Klick höchstens zwei Aufrufe, die Grenzen sind also weit weg vom Alltag.
 */
class ApiController extends Controller {
	private const PRO_SEITE = [25, 50, 100];

	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private KalenderService $service,
		private Einstellungen $einstellungen,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/** Die Einstellungen, die die Oberfläche braucht (Standardort). */
	#[NoAdminRequired]
	public function einstellungen(): JSONResponse {
		return $this->ausfuehren(fn (string $uid) => $this->einstellungen->fuerOberflaeche());
	}

	#[NoAdminRequired]
	public function kalender(): JSONResponse {
		return $this->ausfuehren(fn (string $uid) => ['kalender' => $this->service->kalender($uid)]);
	}

	#[NoAdminRequired]
	public function kalenderWaehlen(int $kalender): JSONResponse {
		return $this->ausfuehren(function (string $uid) use ($kalender) {
			$this->service->kalenderWaehlen($uid, $kalender);
			return ['ok' => true];
		});
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	public function termine(int $kalender, ?string $von = null, ?string $bis = null, string $suche = '',
		int $seite = 1, int $pro_seite = 25): JSONResponse {
		return $this->ausfuehren(function (string $uid) use ($kalender, $von, $bis, $suche, $seite, $pro_seite) {
			[$start, $ende] = KalenderService::zeitraum($von, $bis);
			$proSeite = in_array($pro_seite, self::PRO_SEITE, true) ? $pro_seite : self::PRO_SEITE[0];
			return $this->service->termine($uid, $kalender, $start, $ende, mb_substr($suche, 0, 200), $seite, $proSeite);
		});
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	public function termin(int $kalender, string $termin): JSONResponse {
		return $this->ausfuehren(fn (string $uid) => $this->service->termin($uid, $kalender, $termin));
	}

	/** @param array<string,mixed> $planung */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function speichern(int $kalender, string $termin, string $etag, array $planung): JSONResponse {
		return $this->ausfuehren(fn (string $uid) => $this->service->speichern($uid, $kalender, $termin, $etag, $planung));
	}

	private function ausfuehren(callable $aufruf): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['fehler' => 'Nicht angemeldet'], 401);
		}
		try {
			return new JSONResponse($aufruf($user->getUID()));
		} catch (PlanungException $e) {
			// Nur die Statuscodes, die die Dienste bewusst setzen; alles andere wird 400.
			$status = in_array($e->getCode(), [Http::STATUS_BAD_REQUEST, Http::STATUS_FORBIDDEN, Http::STATUS_NOT_FOUND, Http::STATUS_CONFLICT], true)
				? $e->getCode() : Http::STATUS_BAD_REQUEST;
			return new JSONResponse(['fehler' => $e->getMessage()], $status);
		} catch (\Throwable $e) {
			$this->logger->error('Kalender-Planung: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['fehler' => 'Interner Fehler, Details im Nextcloud-Protokoll'], 500);
		}
	}
}
