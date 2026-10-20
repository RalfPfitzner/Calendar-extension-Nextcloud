<?php

declare(strict_types=1);

namespace OCA\MspPlanung\Controller;

use OCA\MspPlanung\AppInfo\Application;
use OCA\MspPlanung\Service\KalenderService;
use OCA\MspPlanung\Service\PlanungException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * JSON-Schnittstelle für die Oberfläche. Prüft nur Eingaben und reicht an
 * den KalenderService weiter.
 */
class ApiController extends Controller {
	private const PRO_SEITE = [25, 50, 100];

	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private KalenderService $service,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
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
	public function termine(int $kalender, ?string $von = null, ?string $bis = null, string $suche = '',
		int $seite = 1, int $pro_seite = 25): JSONResponse {
		return $this->ausfuehren(function (string $uid) use ($kalender, $von, $bis, $suche, $seite, $pro_seite) {
			[$start, $ende] = KalenderService::zeitraum($von, $bis);
			$proSeite = in_array($pro_seite, self::PRO_SEITE, true) ? $pro_seite : self::PRO_SEITE[0];
			return $this->service->termine($uid, $kalender, $start, $ende, mb_substr($suche, 0, 200), $seite, $proSeite);
		});
	}

	#[NoAdminRequired]
	public function termin(int $kalender, string $termin): JSONResponse {
		return $this->ausfuehren(fn (string $uid) => $this->service->termin($uid, $kalender, $termin));
	}

	/** @param array<string,mixed> $planung */
	#[NoAdminRequired]
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
			return new JSONResponse(['fehler' => $e->getMessage()], $e->getCode());
		} catch (\Throwable $e) {
			$this->logger->error('MSP-Planung: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['fehler' => 'Interner Fehler, Details im Nextcloud-Protokoll'], 500);
		}
	}
}
