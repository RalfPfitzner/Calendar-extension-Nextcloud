<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
		['name' => 'api#einstellungen', 'url' => '/api/einstellungen', 'verb' => 'GET'],
		['name' => 'api#kalender', 'url' => '/api/kalender', 'verb' => 'GET'],
		['name' => 'api#kalenderWaehlen', 'url' => '/api/kalender/auswahl', 'verb' => 'PUT'],
		['name' => 'api#termine', 'url' => '/api/termine', 'verb' => 'GET'],
		['name' => 'api#termin', 'url' => '/api/termin', 'verb' => 'GET'],
		['name' => 'api#speichern', 'url' => '/api/termin', 'verb' => 'PUT'],
	],
];
