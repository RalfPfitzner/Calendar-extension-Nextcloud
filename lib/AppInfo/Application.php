<?php

declare(strict_types=1);

namespace OCA\CalendarPlanning\AppInfo;

use OCA\CalendarPlanning\Service\Einstellungen;
use OCA\CalendarPlanning\Service\EinstellungenLader;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;

class Application extends App implements IBootstrap {
	public const APP_ID = 'calendar_planning';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		// Die Einstellungen werden einmal je Anfrage aus der App-Konfiguration gelesen und als Wert verteilt.
		$context->registerService(Einstellungen::class, fn (ContainerInterface $c) => $c->get(EinstellungenLader::class)->laden());
	}

	public function boot(IBootContext $context): void {
	}
}
