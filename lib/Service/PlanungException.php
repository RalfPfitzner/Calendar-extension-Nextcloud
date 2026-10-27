<?php

declare(strict_types=1);

namespace OCA\CalendarPlanning\Service;

/** Fehler, deren Text der Nutzer sehen darf; der Code ist der HTTP-Status. */
class PlanungException extends \Exception {
	public function __construct(string $meldung, int $status = 400) {
		parent::__construct($meldung, $status);
	}
}
