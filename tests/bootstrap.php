<?php

declare(strict_types=1);

// Autoloader von Composer, dazu die Schnittstellen aus nextcloud/ocp: Das Paket bringt keinen eigenen Autoloader mit
// (phpstan.neon liest es per scanDirectories). So lassen sich Controller und Dienste mit Attrappen der
// OCP-Schnittstellen testen, ohne Nextcloud.
require __DIR__ . '/../vendor/autoload.php';

spl_autoload_register(static function (string $klasse): void {
	foreach (['OCP\\' => 'OCP/', 'NCU\\' => 'NCU/'] as $praefix => $ordner) {
		if (str_starts_with($klasse, $praefix)) {
			$datei = __DIR__ . '/../vendor/nextcloud/ocp/' . $ordner . str_replace('\\', '/', substr($klasse, strlen($praefix))) . '.php';
			if (is_file($datei)) {
				require $datei;
			}
			return;
		}
	}
});
