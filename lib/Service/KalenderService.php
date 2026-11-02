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

	/**
	 * Systemkalender, in denen niemand Veranstaltungen plant (U-KAL-21): Geburtstage aus den Kontakten
	 * (`contact_birthdays`, nur lesbar, Termine erzeugt Nextcloud) und Kalender, die andere Apps anlegen und selbst
	 * pflegen (`app-generated--…`). Abos und Kalender anderer Apps ohne CalDAV-Objekte fallen schon über die Klasse
	 * heraus (alleKalender).
	 */
	private const SYSTEMKALENDER = '/^(contact_birthdays|app-generated--)/';

	/** Kalender je Konto, je Anfrage einmal aufgebaut (P-KAL-04). @var array<string,list<ICalendar>> */
	private array $kalenderJeKonto = [];

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
	 * Jedes Kalenderobjekt wird höchstens einmal geparst (P-KAL-02): Das Backend sucht nur per SQL vor (Termine, die
	 * laut Datenbank in den Zeitraum fallen, objekteImZeitraum); Zeitraum, Sichtbarkeit, Suche und Serien prüft
	 * Planung::lesen auf derselben geparsten Fassung; erst danach wird sortiert, gezählt und die Seite ausgeschnitten.
	 *
	 * @param DateTimeImmutable|null $jetzt Bezug für „nächster Termin“ einer Serie (U-KAL-16), ohne Angabe die Uhrzeit
	 * @return array{termine:list<array<string,mixed>>,seite:int,seiten:int,pro_seite:int,gesamt:int,schreibbar:bool}
	 */
	public function termine(string $uid, int $kalenderId, DateTimeImmutable $von, DateTimeImmutable $bis,
		string $suche, int $seite, int $proSeite, ?DateTimeImmutable $jetzt = null): array {
		$kalender = $this->kalenderFuer($uid, $kalenderId);
		$jetzt ??= new DateTimeImmutable('now', new DateTimeZone(self::ZONE));
		$suche = self::suchform(trim($suche));
		$termine = [];
		foreach ($this->objekteImZeitraum($kalenderId, $von, $bis) as $uri) {
			// Das Objekt liegt nach calendarQuery im Zwischenspeicher des Backends (CalDavBackend::$cachedObjects),
			// getCalendarObject fragt die Datenbank also nicht noch einmal.
			$objekt = $this->backend->getCalendarObject($kalenderId, $uri);
			if ($objekt === null) {
				continue;
			}
			try {
				$eintrag = $this->eintrag($kalender, $kalenderId, $objekt, $von, $bis, $suche, $jetzt);
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
		// Nach Zeitpunkt als Zahl (Q-KAL-04): Die Zeitstempel tragen verschiedene UTC-Versätze (Sommer-, Winterzeit,
		// UTC bei Ganztags-Terminen), als Text sortiert stimmt die Reihenfolge dann nicht. Bei gleicher Zeit nach Titel,
		// dann nach URI, damit die Seiten stabil bleiben.
		usort($termine, fn ($a, $b) => [$a[0], $a[1], $a[2]['objekt']] <=> [$b[0], $b[1], $b[2]['objekt']]);

		$gesamt = count($termine);
		$seiten = max(1, (int)ceil($gesamt / $proSeite));
		$seite = min(max(1, $seite), $seiten);
		return [
			'termine' => array_column(array_slice($termine, ($seite - 1) * $proSeite, $proSeite), 2),
			'seite' => $seite,
			'seiten' => $seiten,
			'pro_seite' => $proSeite,
			'gesamt' => $gesamt,
			'schreibbar' => $kalender instanceof ICalendarIsWritable && $kalender->isWritable(),
		];
	}

	/**
	 * URIs der Termine, die nach $von enden und vor $bis beginnen, laut den Spalten `lastoccurence` und
	 * `firstoccurence` des Backends.
	 *
	 * Mit Anfang und Ende in einer Abfrage parst das Backend jedes passende Objekt und löst Serien auf, um den
	 * Zeitraum genau zu prüfen (`requirePostFilter`), danach parst Planung::lesen dasselbe Objekt noch einmal. Mit nur
	 * einer Grenze prüft das Backend rein per SQL, ohne zu parsen. Deshalb zwei Abfragen mit je einer Grenze und davon
	 * die Schnittmenge: Geparst werden nur die Objekte, die laut Datenbank in den Zeitraum fallen (P-KAL-02), nicht
	 * mehr alle, die nach $von enden. Beide Abfragen legen jedes gefundene Objekt in den Zwischenspeicher des Backends,
	 * getCalendarObject fragt die Datenbank danach also nicht noch einmal.
	 *
	 * @return list<string>
	 */
	private function objekteImZeitraum(int $kalenderId, DateTimeImmutable $von, DateTimeImmutable $bis): array {
		$abVon = $this->backend->calendarQuery($kalenderId, self::zeitFilter($von, null));
		if ($abVon === []) {
			return [];
		}
		$vorBis = $this->backend->calendarQuery($kalenderId, self::zeitFilter(null, $bis));
		return array_values(array_intersect($abVon, $vorBis));
	}

	/**
	 * Filter für CalDavBackend::calendarQuery: VEVENT mit genau einer Grenze des Zeitraums (siehe objekteImZeitraum).
	 *
	 * @return array<string,mixed>
	 */
	private static function zeitFilter(?DateTimeImmutable $start, ?DateTimeImmutable $ende): array {
		return [
			'name' => 'VCALENDAR',
			'is-not-defined' => false,
			'time-range' => false,
			'prop-filters' => [],
			'comp-filters' => [[
				'name' => 'VEVENT',
				'is-not-defined' => false,
				'time-range' => ['start' => $start, 'end' => $ende],
				'prop-filters' => [],
				'comp-filters' => [],
			]],
		];
	}

	/**
	 * Listeneintrag zu einem Kalenderobjekt als [Zeitpunkt für die Sortierung, Titel für die Sortierung, Eintrag],
	 * null wenn unsichtbar, kein Termin, nicht im Zeitraum oder nicht zur Suche passend.
	 *
	 * @param array<string,mixed> $objekt
	 * @return array{0:int,1:string,2:array<string,mixed>}|null
	 */
	private function eintrag(ICalendar $kalender, int $kalenderId, array $objekt, DateTimeImmutable $von, DateTimeImmutable $bis,
		string $suche, DateTimeImmutable $jetzt): ?array {
		if (!$this->sichtbar($kalender, $objekt)) {
			return null;
		}
		$daten = $this->planung->lesen($objekt['calendardata'], $von, $bis, new DateTimeZone(self::ZONE), $jetzt);
		if ($daten === null) {
			return null;
		}
		if ($suche !== '' && !str_contains($this->suchtext($daten), $suche)) {
			return null;
		}
		return [self::zeitpunkt($daten), self::suchform($daten['titel']), $this->kurz($kalenderId, $objekt['uri'], $daten)];
	}

	/**
	 * Text, in dem die Suche sucht (Q-KAL-06, U-KAL-11): nur, was die Oberfläche zeigt, also Titel, Ort, die
	 * Planungsfelder aus dem Schema und das Datum als TT.MM.JJJJ. Nicht die Beschreibung mit dem Link, nicht die UID
	 * und nicht die Zeitstempel im ISO-Format.
	 *
	 * @param array<string,mixed> $daten
	 */
	private function suchtext(array $daten): string {
		$teile = [$daten['titel'], $daten['ort']];
		$ganztags = $daten['ganztags'] === true;
		foreach (['beginn', 'ende'] as $zeit) {
			if (is_string($daten[$zeit] ?? null)) {
				// Bei ganztägigen Terminen ist das Ende ausschließlich (Mitternacht nach dem letzten Tag): gesucht wird
				// nach dem letzten Tag, nicht nach dem Folgetag.
				$teile[] = self::anzeigeDatum($daten[$zeit], $ganztags, $ganztags && $zeit === 'ende');
			}
		}
		foreach ($this->einstellungen->schema->felder as $schluessel => $feld) {
			$wert = $daten[$schluessel] ?? '';
			if (!is_string($wert) || $wert === '') {
				continue;
			}
			$teile[] = $wert;
			if ($feld['typ'] === 'date' && ($datum = DateTimeImmutable::createFromFormat('!Y-m-d', $wert)) !== false) {
				$teile[] = $datum->format('d.m.Y');
			}
		}
		return self::suchform(implode("\n", $teile));
	}

	/**
	 * Vergleichsform für die Suche: Unicode-NFC (zusammengesetzte und vorkomponierte Umlaute gleich), Groß- und
	 * Kleinschreibung egal (Case-Folding, „ß“ wird „ss“), Umlaute als ae/oe/ue, damit „Loetkurs“ „Lötkurs“ findet.
	 */
	public static function suchform(string $text): string {
		if (class_exists(\Normalizer::class)) {
			$text = \Normalizer::normalize($text, \Normalizer::FORM_C) ?: $text;
		}
		return strtr(mb_convert_case($text, MB_CASE_FOLD, 'UTF-8'), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue']);
	}

	/** Datum, wie die Liste es zeigt (src/datum.js): in der Zone der Oberfläche, ganztägige Termine als UTC-Datum. */
	private static function anzeigeDatum(string $zeit, bool $ganztags, bool $tagDavor = false): string {
		$wert = (new DateTimeImmutable($zeit))->setTimezone(new DateTimeZone($ganztags ? 'UTC' : self::ZONE));
		return ($tagDavor ? $wert->modify('-1 day') : $wert)->format('d.m.Y');
	}

	/**
	 * Zeitpunkt für die Sortierung als Unix-Zeit (Q-KAL-04). Ganztägige Termine zählen ab Mitternacht ihres Tages in
	 * der Zone der Oberfläche, nicht ab UTC-Mitternacht, damit sie vor den Terminen mit Uhrzeit am selben Tag stehen.
	 *
	 * @param array<string,mixed> $daten
	 */
	private static function zeitpunkt(array $daten): int {
		if (!is_string($daten['beginn'] ?? null)) {
			return PHP_INT_MIN;
		}
		$beginn = new DateTimeImmutable($daten['beginn']);
		if ($daten['ganztags'] === true) {
			$tag = $beginn->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
			return (new DateTimeImmutable($tag, new DateTimeZone(self::ZONE)))->getTimestamp();
		}
		return $beginn->getTimestamp();
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
				throw new PlanungException('Der Termin wurde inzwischen geändert. Es wurde nichts gespeichert; die Eingaben bleiben im Formular stehen.', 409);
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

	/**
	 * Kalender des Kontos, in denen geplant werden kann; je Anfrage einmal aufgebaut (P-KAL-04), Speichern fragt sonst
	 * zweimal (Rechte, danach das Lesen des gespeicherten Termins).
	 *
	 * @return list<ICalendar>
	 */
	private function alleKalender(string $uid): array {
		if (isset($this->kalenderJeKonto[$uid])) {
			return $this->kalenderJeKonto[$uid];
		}
		$kalender = [];
		foreach ($this->kalenderManager->getCalendarsForPrincipal('principals/users/' . $uid) as $eintrag) {
			// Nur echte CalDAV-Kalender: Abos und Kalender anderer Apps lassen sich nicht so beschreiben.
			if (!$eintrag instanceof \OCA\DAV\CalDAV\CalendarImpl || $eintrag->isDeleted()) {
				continue;
			}
			// Geteilte Kalender tragen die URI des Eigentümers mit „_shared_by_…“ dahinter; das Muster passt auch dort.
			if (preg_match(self::SYSTEMKALENDER, $eintrag->getUri()) === 1) {
				continue;
			}
			$kalender[] = $eintrag;
		}
		return $this->kalenderJeKonto[$uid] = $kalender;
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
