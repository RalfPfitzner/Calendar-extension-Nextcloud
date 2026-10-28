<?php

declare(strict_types=1);

namespace OCA\CalendarPlanning\Controller;

use OCA\CalendarPlanning\AppInfo\Application;
use OCA\CalendarPlanning\Service\Einstellungen;
use OCA\CalendarPlanning\Service\EinstellungenSpeicher;
use OCA\CalendarPlanning\Service\PlanungException;
use OCA\CalendarPlanning\Service\Schema;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IRequest;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use Psr\Log\LoggerInterface;

/**
 * Einstellungen für Admins: Vorgaben und Feldschema.
 *
 * Absichtlich ohne #[NoAdminRequired]: Nextcloud lässt nur Admins durch (sonst 403) und verlangt das CSRF-Token.
 * Das Schema bestimmt, welche Eigenschaften die App in Termine schreibt; es wird in Schema::ausArray hart geprüft.
 * Jede gespeicherte Änderung geht als CriticalActionPerformedEvent an das Audit-Protokoll von Nextcloud (App
 * admin_audit, falls aktiv; dort mit Konto und Zeit), ohne die Inhalte der Einstellungen.
 */
class AdminController extends Controller {
	public function __construct(
		IRequest $request,
		private EinstellungenSpeicher $speicher,
		private Einstellungen $einstellungen,
		private IEventDispatcher $ereignisse,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/** Aktuelles Schema, Vorgabe und mitgelieferte Vorlagen. */
	public function schema(): JSONResponse {
		return $this->ausfuehren(fn () => [
			'schema' => $this->einstellungen->schema->alsArray(),
			'vorgabe' => Schema::vorgabe()->alsArray(),
			'presets' => $this->speicher->presets(),
		]);
	}

	/** @param array<string,mixed> $schema */
	#[UserRateLimit(limit: 30, period: 60)]
	public function schemaSpeichern(array $schema): JSONResponse {
		return $this->ausfuehren(function () use ($schema) {
			$neu = $this->speicher->schemaSpeichern($schema);
			$this->protokollieren('Kalender-Planung: Feldschema geändert (%s Abschnitte, %s Felder)', [
				'abschnitte' => count($neu->abschnitte),
				'felder' => count($neu->felder),
			]);
			return ['schema' => $neu->alsArray()];
		});
	}

	public function einstellungen(): JSONResponse {
		return $this->ausfuehren(fn () => $this->einstellungen->vorgaben());
	}

	#[UserRateLimit(limit: 30, period: 60)]
	public function einstellungenSpeichern(string $ort_vorgabe = '', string $kalender_muster = '', string $link_text = ''): JSONResponse {
		return $this->ausfuehren(function () use ($ort_vorgabe, $kalender_muster, $link_text) {
			$neu = $this->speicher->vorgabenSpeichern([
				Einstellungen::SCHLUESSEL_ORT => $ort_vorgabe,
				Einstellungen::SCHLUESSEL_KALENDER_MUSTER => $kalender_muster,
				Einstellungen::SCHLUESSEL_LINK_TEXT => $link_text,
			]);
			$this->protokollieren('Kalender-Planung: Vorgaben geändert (Standardort, Kalender-Vorbelegung, Linktext)', []);
			return $neu->vorgaben();
		});
	}

	/** @param array<string,int> $parameter nur Zahlen, keine Inhalte der Einstellungen */
	private function protokollieren(string $text, array $parameter): void {
		$this->ereignisse->dispatchTyped(new CriticalActionPerformedEvent($text, $parameter));
	}

	private function ausfuehren(callable $aufruf): JSONResponse {
		try {
			return new JSONResponse($aufruf());
		} catch (PlanungException $e) {
			$status = $e->getCode() === Http::STATUS_INTERNAL_SERVER_ERROR ? Http::STATUS_INTERNAL_SERVER_ERROR : Http::STATUS_BAD_REQUEST;
			if ($status === Http::STATUS_INTERNAL_SERVER_ERROR) {
				$this->logger->error('Kalender-Planung: ' . $e->getMessage());
			}
			return new JSONResponse(['fehler' => $e->getMessage()], $status);
		} catch (\Throwable $e) {
			$this->logger->error('Kalender-Planung: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['fehler' => 'Interner Fehler, Details im Nextcloud-Protokoll'], 500);
		}
	}
}
