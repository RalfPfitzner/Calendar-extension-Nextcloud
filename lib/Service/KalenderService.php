<?php

declare(strict_types=1);

namespace OCA\MspPlanung\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\DAV\CalDAV\CalDavBackend;
use OCA\MspPlanung\AppInfo\Application;
use OCP\Calendar\ICalendar;
use OCP\Calendar\ICalendarIsShared;
use OCP\Calendar\ICalendarIsWritable;
use OCP\Calendar\IManager;
use OCP\IConfig;
use OCP\IURLGenerator;

/**
 * Lesen und Schreiben der Termine über das Kalender-Backend von Nextcloud.
 *
 * Welche Kalender jemand sieht und ob er schreiben darf, kommt aus der
 * öffentlichen Kalender-Schnittstelle (OCP); nur das eigentliche Lesen und
 * Schreiben der Kalenderobjekte geht direkt an das CalDAV-Backend, weil OCP
 * kein Ändern bestehender Termine anbietet.
 */
class KalenderService {
	/** Wird beim Vorbelegen der Kalenderauswahl gesucht. */
	private const KALENDER_VORGABE = 'MSP';

	/** Klassifizierung „öffentlich“ im CalDAV-Backend. */
	private const KLASSE_OEFFENTLICH = 0;

	public function __construct(
		private IManager $kalenderManager,
		private CalDavBackend $backend,
		private IURLGenerator $urlGenerator,
		private IConfig $config,
		private Planung $planung,
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
		$this->config->setUserValue($uid, Application::APP_ID, 'kalender', (string)$kalenderId);
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
			if (!$this->sichtbar($kalender, $objekt)) {
				continue;
			}
			$daten = $this->planung->lesen($objekt['calendardata'], $von, $bis);
			if ($daten === null) {
				continue;
			}
			if ($suche !== '' && !str_contains(mb_strtolower(implode("\n", array_filter($daten, 'is_string'))), $suche)) {
				continue;
			}
			$termine[] = $this->kurz($kalenderId, $objekt['uri'], $daten);
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

	/** @return array<string,mixed> */
	public function termin(string $uid, int $kalenderId, string $objektUri): array {
		$kalender = $this->kalenderFuer($uid, $kalenderId);
		$objekt = $this->objekt($kalender, $kalenderId, $objektUri);
		$daten = $this->planung->lesen($objekt['calendardata']);
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
		$objekt = $this->objekt($kalender, $kalenderId, $objektUri);
		if ($objekt['etag'] !== $etag) {
			throw new PlanungException('Der Termin wurde inzwischen geändert. Bitte neu laden und die Eingaben erneut eintragen.', 409);
		}
		$neu = $this->planung->schreiben($objekt['calendardata'], $planung, $this->link($kalenderId, $objektUri));
		$this->backend->updateCalendarObject($kalenderId, $objektUri, $neu);
		return $this->termin($uid, $kalenderId, $objektUri);
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
		$gespeichert = (int)$this->config->getUserValue($uid, Application::APP_ID, 'kalender', '0');
		foreach ($liste as $eintrag) {
			if ($eintrag['id'] === $gespeichert) {
				return $gespeichert;
			}
		}
		foreach ($liste as $eintrag) {
			if (stripos($eintrag['name'], self::KALENDER_VORGABE) !== false) {
				return $eintrag['id'];
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

	/** @return array<string,mixed> */
	private function kurz(int $kalenderId, string $objektUri, array $daten): array {
		return [
			'kalender' => $kalenderId,
			'objekt' => $objektUri,
			'titel' => $daten['titel'],
			'beginn' => $daten['beginn'],
			'ende' => $daten['ende'],
			'ganztags' => $daten['ganztags'],
			'serie' => $daten['serie'],
			'ort' => $daten['ort'],
			'zustaendig' => $daten['zustaendig'],
			'kontakt' => trim($daten['kontakt_organisation'] . ($daten['kontakt_name'] !== '' ? ' · ' . $daten['kontakt_name'] : ''), ' ·'),
			'teilnehmende' => $daten['teilnehmende'],
			'geplant' => $daten['geplant'],
		];
	}

	public static function zeitraum(?string $von, ?string $bis): array {
		// Der Makerspace plant in deutscher Zeit; Tagesgrenzen danach richten.
		$zone = new DateTimeZone('Europe/Berlin');
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
		$datum = DateTimeImmutable::createFromFormat('!Y-m-d', $wert, new DateTimeZone('Europe/Berlin'));
		if ($datum === false || $datum->format('Y-m-d') !== $wert) {
			throw new PlanungException("Ungültiges Datum „{$wert}“");
		}
		return $datum;
	}
}
