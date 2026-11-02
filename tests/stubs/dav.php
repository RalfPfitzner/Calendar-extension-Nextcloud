<?php

declare(strict_types=1);

// Platzhalter der zwei Klassen aus apps/dav, die KalenderService nutzt (nicht in nextcloud/ocp). Nur für Attrappen in
// den Unit-Tests; die echten Signaturen prüft tools/api-check.sh gegen die Dateien aus dem Server-Repo.

namespace OCA\DAV\CalDAV;

use OCP\Calendar\ICalendar;
use OCP\Calendar\ICalendarIsShared;
use OCP\Calendar\ICalendarIsWritable;

if (!class_exists(CalDavBackend::class, false)) {
	class CalDavBackend {
		/** @return list<string> */
		public function calendarQuery($calendarId, array $filters, $calendarType = 0): array {
			return [];
		}

		/** @return list<array<string,mixed>> */
		public function getMultipleCalendarObjects($calendarId, array $uris, $calendarType = 0): array {
			return [];
		}

		/** @return array<string,mixed>|null */
		public function getCalendarObject($calendarId, $objectUri, $calendarType = 0): ?array {
			return null;
		}

		public function updateCalendarObject($calendarId, $objectUri, $calendarData, $calendarType = 0): string {
			return '';
		}
	}
}

if (!class_exists(CalendarImpl::class, false)) {
	abstract class CalendarImpl implements ICalendar, ICalendarIsShared, ICalendarIsWritable {
	}
}
