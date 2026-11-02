<?php

declare(strict_types=1);

namespace OCA\CalendarPlanning\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\CalendarPlanning\AppInfo\Application;
use OCA\DAV\CalDAV\CalDavBackend;
use OCP\Calendar\ICalendar;
use OCP\Calendar\ICalendarIsShared;
use OCP\Calendar\ICalendarIsWritable;
use OCP\Calendar\IManager;
use OCP\Config\IUserConfig;
use OCP\DB\Exception as DbException;
use OCP\IURLGenerator;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Lesen und Schreiben der Termine über das Kalender-Backend von Nextcloud.
 *
 * Welche Kalender jemand sieht und ob er schreiben darf, kommt aus der
 * öffentlichen Kalender-Schnittstelle (OCP); nur das eigentliche Lesen und
 * Schreiben der Kalenderobjekte geht direkt an das CalDAV-Backend, weil OCP
 * kein Ändern bestehender Termine anbietet.
 */
class KalenderService {
	/** Klassifizierung „öffentlich“ im CalDAV-Backend. */
	private const KLASSE_OEFFENTLICH = 0;

	/**
	 * Zone der Oberfläche: Tagesgrenzen der Liste, Anzeige und „Floating“-Zeiten (ohne TZID) gelten in dieser Zone
	 * (src/datum.js zeigt ebenfalls in Europe/Berlin an). Siehe docs/KONZEPT.md, „Offen“.
	 */
	public const ZONE = 'Europe/Berlin';

	/** Präfix der Sperre je Termin beim Speichern; dahinter ein Hash, die Schlüssel der Sperren sind höchstens 64 Zeichen lang. */
	private const SPERRE = 'calendar_planning/';

	/** Meldung, wenn eine andere Anfrage den Termin gerade speichert (Sperre belegt): kurz warten genügt. */
	public const GERADE_GESPEICHERT = 'Der Termin wird gerade gespeichert. Bitte gleich noch einmal versuchen.';

	public function __construct(
		private IManager $kalenderManager,
		private CalDavBackend $backend,
		private IURLGenerator $urlGenerator,
		private IUserConfig $userConfig,
		private Planung $planung,
		private Einstellungen $einstellungen,
		private ILockingProvider $sperren,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Alle Kalender des Nutzers (eigene und geteilte), gewählter zuerst markiert.
	 *
	 * @return list<array{id:int,name:string,farbe:?string,schreibbar:bool,gewaehlt:bool}>
	 */
	public function kalender(string $uid): array {
		$liste = [];
		foreach ($this->alleKalender($uid) as $kalender) {
			$liste[] = [
				'id' => (int)$kalender->getKey(),
				'name' => $kalender->getDisplayName() ?? $kalender->getUri(),
				'farbe' => $kalender->getDisplayColor(),
				'schreibbar' => $kalender instanceof ICalendarIsWritable && $kalender->isWritable(),
				'gewaehlt' => false,
			];
		}
		usort($liste, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
		$gewaehlt = $this->gewaehlterKalender($uid, $liste);
		foreach ($liste as &$eintrag) {
			$eintrag['gewaehlt'] = $eintrag['id'] === $gewaehlt;
		}
		return $liste;
	}

	public function kalenderWaehlen(string $uid, int $kalenderId): void {
		$this->kalenderFuer($uid, $kalenderId);
		$this->userConfig->setValueString($uid, Application::APP_ID, 'kalender', (string)$kalenderId);
	}

	/**
	 * Termine eines Kalenders im Zeitraum, seitenweise.
	 *
	 * @return array{termine:list<array<string,mixed>>,seite:int,seiten:int,pro_seite:int,gesamt:int,schreibbar:bool}
	 */
	public function termine(string $uid, int $kalenderId, DateTimeImmutable $von, DateTimeImmutable $bis,
		string $suche, int $seite, int $proSeite): array {
		$kalender = $this->kalenderFuer($uid, $kalenderId);
		$filter = [
			'name' => 'VCALENDAR',
			'is-not-defined' => false,
			'time-range' => false,
			'prop-filters' => [],
			'comp-filters' => [[
				'name' => 'VEVENT',
				'is-not-defined' => false,
				'time-range' => ['start' => $von, 'end' => $bis],
				'prop-filters' => [],
				'comp-filters' => [],
			]],
		];
		$uris = $this->backend->calendarQuery($kalenderId, $filter);
		$objekte = $this->backend->getMultipleCalendarObjects($kalenderId, $uris);

		$suche = mb_strtolower(trim($suche));
		$termine = [];
		foreach ($objekte as $objekt) {
			try {
				$eintrag = $this->eintrag($kalender, $kalenderId, $objekt, $von, $bis, $suche);
			} catch (\Throwable $e) {
				// Ein defekter Termin (z. B. ungültiges iCalendar aus einem anderen Programm) darf die Liste nicht
				// scheitern lassen: überspringen, im Protokoll nur Klasse, Code und Hash des Termins.
				$uri = is_string($objekt['uri'] ?? null) ? $objekt['uri'] : null;
				$this->logger->warning(Protokoll::text($e, $kalenderId, $uri, 'Termin in der Liste übersprungen,'), ['app' => Application::APP_ID]);
				continue;
			}
			if ($eintrag !== null) {
				$termine[] = $eintrag;
			}
		}
		usort($termine, fn ($a, $b) => [$a['beginn'] ?? '', $a['titel']] <=> [$b['beginn'] ?? '', $b['titel']]);

		$gesamt = count($termine);
		$seiten = max(1, (int)ceil($gesamt / $proSeite));
		$seite = min(max(1, $seite), $seiten);
		return [
			'termine' => array_slice($termine, ($seite - 1) * $proSeite, $proSeite),
			'seite' => $seite,
			'seiten' => $seiten,
			'pro_seite' => $proSeite,
			'gesamt' => $gesamt,
			'schreibbar' => $kalender instanceof ICalendarIsWritable && $kalender->isWritable(),
		];
	}

	/**
	 * Listeneintrag zu einem Kalenderobjekt, null wenn unsichtbar, kein Termin oder nicht zur Suche passend.
	 *
	 * @param array<string,mixed> $objekt
	 * @return array<string,mixed>|null
	 */
	private function eintrag(ICalendar $kalender, int $kalenderId, array $objekt, DateTimeImmutable $von, DateTimeImmutable $bis, string $suche): ?array {
		if (!$this->sichtbar($kalender, $objekt)) {
			return null;
		}
		$daten = $this->planung->lesen($objekt['calendardata'], $von, $bis, new DateTimeZone(self::ZONE));
		if ($daten === null) {
			return null;
		}
		if ($suche !== '' && !str_contains(mb_strtolower(implode("\n", array_filter($daten, 'is_string'))), $suche)) {
			return null;
		}
		return $this->kurz($kalenderId, $objekt['uri'], $daten);
	}

	/** @return array<string,mixed> */
	public function termin(string $uid, int $kalenderId, string $objektUri): array {
		$kalender = $this->kalenderFuer($uid, $kalenderId);
		$objekt = $this->objekt($kalender, $kalenderId, $objektUri);
		$daten = $this->planung->lesen($objekt['calendardata'], null, null, new DateTimeZone(self::ZONE));
		if ($daten === null) {
			throw new PlanungException('Der Eintrag ist kein Termin', 404);
		}
		return $daten + [
			'kalender' => $kalenderId,
			'objekt' => $objektUri,
			'etag' => $objekt['etag'],
			'schreibbar' => $kalender instanceof ICalendarIsWritable && $kalender->isWritable(),
		];
	}

	/**
	 * @param array<string,mixed> $eingabe
	 * @return array<string,mixed> der gespeicherte Termin
	 */
	public function speichern(string $uid, int $kalenderId, string $objektUri, string $etag, array $eingabe): array {
		$kalender = $this->kalenderFuer($uid, $kalenderId);
		if (!$kalender instanceof ICalendarIsWritable || !$kalender->isWritable()) {
			throw new PlanungException('In diesen Kalender darfst du nicht schreiben', 403);
		}
		$planung = $this->planung->pruefen($eingabe);
		$this->gesperrt($kalenderId, $objektUri, function () use ($kalender, $kalenderId, $objektUri, $etag, $planung) {
			// Lesen, ETag vergleichen und Schreiben unter derselben Sperre (S-KAL-03, Q-KAL-10): Zwei Speicherungen mit
			// demselben ETag können sich nicht mehr überholen; die zweite sieht den neuen ETag und bekommt 409.
			$objekt = $this->objekt($kalender, $kalenderId, $objektUri);
			if ($objekt['etag'] !== $etag) {
				throw new PlanungException('Der Termin wurde inzwischen geändert. Bitte neu laden und die Eingaben erneut eintragen.', 409);
			}
			$neu = $this->planung->schreiben($objekt['calendardata'], $planung, $this->link($kalenderId, $objektUri));
			$this->backend->updateCalendarObject($kalenderId, $objektUri, $neu);
		});
		return $this->termin($uid, $kalenderId, $objektUri);
	}

	/**
	 * Führt $schritt unter einer exklusiven Sperre für genau diesen Termin aus.
	 *
	 * Die Sperre kommt aus dem Sperrdienst von Nextcloud (Datenbank oder Memcache, je nach `memcache.locking`) und
	 * wartet nicht: Hält eine andere Anfrage sie gerade, ist der Termin mitten im Speichern, also 409 mit der Bitte,
	 * es gleich noch einmal zu versuchen. Mit `filelocking.enabled = false` sperrt Nextcloud nichts (der Sperrdienst
	 * ist dann ein Platzhalter); es bleibt der ETag-Vergleich. CalDavBackend selbst bietet kein bedingtes Ändern;
	 * Schreibzugriffe anderer Programme über CalDAV nimmt die Sperre nicht mit (dort prüft Sabre If-Match ebenso
	 * ohne Sperre).
	 */
	private function gesperrt(int $kalenderId, string $objektUri, callable $schritt): void {
		// md5 nur als kurzer Schlüssel (wie Nextcloud bei Dateisperren), nicht zum Schutz von irgendetwas.
		$schluessel = self::SPERRE . md5($kalenderId . '/' . $objektUri);
		try {
			self::beiSperreWiederholen(fn () => $this->sperren->acquireLock($schluessel, ILockingProvider::LOCK_EXCLUSIVE, 'Kalender-Planung: Termin speichern'));
		} catch (LockedException) {
			throw new PlanungException(self::GERADE_GESPEICHERT, 409);
		} catch (DbException $e) {
			if (!self::istSperre($e)) {
				throw $e;
			}
			throw new PlanungException(self::GERADE_GESPEICHERT, 409);
		}
		try {
			// Der Schritt ist als Ganzes atomar (updateCalendarObject läuft in einer Transaktion) und prüft den ETag
			// selbst; unter der Sperre darf er nach einer abgebrochenen Transaktion also neu laufen.
			self::beiSperreWiederholen($schritt);
		} finally {
			// Die Freigabe darf weder das Ergebnis noch den Fehler des Schritts überschreiben. Scheitert sie, läuft
			// die Sperre im Sperrdienst von selbst ab (TTL); hier nur ins Protokoll, ohne Inhalte.
			try {
				self::beiSperreWiederholen(fn () => $this->sperren->releaseLock($schluessel, ILockingProvider::LOCK_EXCLUSIVE));
			} catch (\Throwable $e) {
				$this->logger->warning(Protokoll::text($e, $kalenderId, $objektUri, 'Sperre nicht freigegeben,'), ['app' => Application::APP_ID]);
			}
		}
	}

	private static function istSperre(DbException $e): bool {
		return in_array($e->getReason(), [DbException::REASON_LOCK_WAIT_TIMEOUT, DbException::REASON_DEADLOCK], true);
	}

	/**
	 * Wiederholt $schritt, wenn die Datenbank ihn wegen einer Sperre oder eines Deadlocks abbricht.
	 *
	 * Bei SQLite sperrt jedes Schreiben die ganze Datenbank; zwei gleichzeitige Speicherungen (auch die Sperre der
	 * anderen Anfrage im Sperrdienst) brechen sich dort sonst mit „database is locked“ gegenseitig ab.
	 */
	private static function beiSperreWiederholen(callable $schritt): mixed {
		for ($versuch = 1; ; $versuch++) {
			try {
				return $schritt();
			} catch (DbException $e) {
				if (!self::istSperre($e) || $versuch >= 5) {
					throw $e;
				}
				usleep(50_000 * $versuch);
			}
		}
	}

	/** Adresse des Formulars zu einem Termin, wie sie in der Beschreibung steht. */
	public function link(int $kalenderId, string $objektUri): string {
		return $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.page.index')
			. '?kalender=' . $kalenderId . '&termin=' . rawurlencode($objektUri);
	}

	/** @return list<ICalendar> */
	private function alleKalender(string $uid): array {
		$kalender = [];
		foreach ($this->kalenderManager->getCalendarsForPrincipal('principals/users/' . $uid) as $eintrag) {
			// Nur echte CalDAV-Kalender: Abos und Kalender anderer Apps lassen sich nicht so beschreiben.
			if (!$eintrag instanceof \OCA\DAV\CalDAV\CalendarImpl || $eintrag->isDeleted()) {
				continue;
			}
			$kalender[] = $eintrag;
		}
		return $kalender;
	}

	private function kalenderFuer(string $uid, int $kalenderId): ICalendar {
		foreach ($this->alleKalender($uid) as $kalender) {
			if ((int)$kalender->getKey() === $kalenderId) {
				return $kalender;
			}
		}
		throw new PlanungException('Kalender nicht gefunden', 404);
	}

	/** @param list<array{id:int,name:string,schreibbar:bool}> $liste */
	private function gewaehlterKalender(string $uid, array $liste): ?int {
		$gespeichert = (int)$this->userConfig->getValueString($uid, Application::APP_ID, 'kalender', '0');
		foreach ($liste as $eintrag) {
			if ($eintrag['id'] === $gespeichert) {
				return $gespeichert;
			}
		}
		// Vorbelegung über das Namensmuster aus den Einstellungen; ohne Muster der erste Kalender.
		$muster = $this->einstellungen->kalenderMuster;
		if ($muster !== '') {
			foreach ($liste as $eintrag) {
				if (mb_stripos($eintrag['name'], $muster) !== false) {
					return $eintrag['id'];
				}
			}
		}
		return $liste[0]['id'] ?? null;
	}

	/** @return array<string,mixed> */
	private function objekt(ICalendar $kalender, int $kalenderId, string $objektUri): array {
		$objekt = $this->backend->getCalendarObject($kalenderId, $objektUri);
		if ($objekt === null || !$this->sichtbar($kalender, $objekt)) {
			throw new PlanungException('Termin nicht gefunden', 404);
		}
		return $objekt;
	}

	/** Gelöschte Termine nie, private Termine in geteilten Kalendern nur für den Eigentümer. */
	private function sichtbar(ICalendar $kalender, array $objekt): bool {
		if (($objekt['{http://nextcloud.com/ns}deleted-at'] ?? null) !== null) {
			return false;
		}
		$geteilt = $kalender instanceof ICalendarIsShared && $kalender->isShared();
		return !$geteilt || (int)($objekt['classification'] ?? 0) === self::KLASSE_OEFFENTLICH;
	}

	/** Kurzfassung für die Liste; die Spalten sind die Felder mit `inListe` aus dem Schema. @return array<string,mixed> */
	private function kurz(int $kalenderId, string $objektUri, array $daten): array {
		$felder = [];
		foreach ($this->einstellungen->schema->listenFelder() as $feld) {
			$felder[$feld['key']] = $daten[$feld['key']] ?? '';
		}
		return [
			'kalender' => $kalenderId,
			'objekt' => $objektUri,
			'titel' => $daten['titel'],
			'beginn' => $daten['beginn'],
			'ende' => $daten['ende'],
			'ganztags' => $daten['ganztags'],
			'serie' => $daten['serie'],
			'ort' => $daten['ort'],
			'felder' => $felder,
			'geplant' => $daten['geplant'],
		];
	}

	public static function zeitraum(?string $von, ?string $bis): array {
		// Tagesgrenzen in deutscher Zeit; die App ist bisher einsprachig deutsch (siehe docs/KONZEPT.md, „Offen“).
		$zone = new DateTimeZone(self::ZONE);
		$start = self::datum($von) ?? new DateTimeImmutable('today -30 days', $zone);
		// „bis“ zählt mit: der Zeitraum endet am Ende dieses Tages.
		$ende = self::datum($bis)?->modify('+1 day') ?? $start->modify('+13 months');
		if ($ende <= $start) {
			throw new PlanungException('Das Ende des Zeitraums liegt vor dem Anfang');
		}
		if ($start->diff($ende)->days > 3 * 366) {
			throw new PlanungException('Der Zeitraum darf höchstens drei Jahre umfassen');
		}
		return [$start, $ende];
	}

	private static function datum(?string $wert): ?DateTimeImmutable {
		if ($wert === null || $wert === '') {
			return null;
		}
		$datum = DateTimeImmutable::createFromFormat('!Y-m-d', $wert, new DateTimeZone(self::ZONE));
		if ($datum === false || $datum->format('Y-m-d') !== $wert) {
			throw new PlanungException("Ungültiges Datum „{$wert}“");
		}
		return $datum;
	}
}
