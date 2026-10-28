<?php

declare(strict_types=1);

namespace OCA\CalendarPlanning\Service;

/**
 * Feldschema des Planungsformulars: Abschnitte mit Feldern, vom Admin definiert.
 *
 * Ein Feld: {key, label, typ, pflicht, maxlen, optionen, inListe}. Aus dem Schlüssel entsteht der Name
 * der Eigenschaft im Termin (Planung::eigenschaft). Das Schema ist Admin-Eingabe und wird trotzdem wie
 * fremde Eingabe behandelt: Schlüssel streng, Texte einzeilig, alle Größen gedeckelt.
 * Reine Datenklasse ohne Nextcloud-Abhängigkeit.
 */
final class Schema {
	public const TYPEN = ['text', 'textarea', 'email', 'tel', 'number', 'select', 'checkbox', 'date'];

	/** Typen, deren Werte einzeilig sind (Umbrüche werden zu Leerzeichen). */
	public const EINZEILIG = ['text', 'email', 'tel', 'number', 'select', 'checkbox', 'date'];

	public const SCHLUESSEL_MUSTER = '/^[a-z][a-z0-9_]{0,30}$/';
	public const MAX_FELDER = 50;
	public const MAX_ABSCHNITTE = 20;
	public const MAX_IN_LISTE = 3;
	public const MAX_OPTIONEN = 50;
	public const MAX_LAENGE = 5000;
	public const MAX_TEXT = 200;
	public const MAX_JSON = 65536;

	/** Schlüssel, die das Formular selbst oder die Antwort der API belegen. */
	public const RESERVIERT = [
		'titel', 'ort', 'ort_extern', 'uid', 'beginn', 'ende', 'ganztags', 'serie', 'beschreibung',
		'geplant', 'kalender', 'objekt', 'etag', 'schreibbar', 'felder',
	];

	/** @var list<array{titel:string,felder:list<array{key:string,label:string,typ:string,pflicht:bool,maxlen:int,optionen:list<string>,inListe:bool}>}> */
	public readonly array $abschnitte;

	/** @var array<string,array{key:string,label:string,typ:string,pflicht:bool,maxlen:int,optionen:list<string>,inListe:bool}> Schlüssel => Feld, in Schema-Reihenfolge */
	public readonly array $felder;

	/** @param list<array{titel:string,felder:list<array<string,mixed>>}> $abschnitte bereits geprüft (siehe ausArray) */
	private function __construct(array $abschnitte) {
		$this->abschnitte = $abschnitte;
		$felder = [];
		foreach ($abschnitte as $abschnitt) {
			foreach ($abschnitt['felder'] as $feld) {
				$felder[$feld['key']] = $feld;
			}
		}
		$this->felder = $felder;
	}

	/** Das mitgelieferte Vorgabe-Schema (schema/vorgabe.json). */
	public static function vorgabe(): self {
		return self::ausDatei(__DIR__ . '/../../schema/vorgabe.json');
	}

	/** @throws PlanungException wenn die Datei fehlt oder ungültig ist */
	public static function ausDatei(string $pfad): self {
		$json = @file_get_contents($pfad);
		if ($json === false) {
			throw new PlanungException('Schema-Datei nicht lesbar: ' . basename($pfad), 500);
		}
		return self::ausJson($json);
	}

	/** @throws PlanungException bei ungültigem Schema (Code 400) */
	public static function ausJson(string $json): self {
		if (strlen($json) > self::MAX_JSON) {
			throw new PlanungException('Das Schema ist zu groß (höchstens ' . self::MAX_JSON . ' Bytes)');
		}
		try {
			$daten = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			throw new PlanungException('Das Schema ist kein gültiges JSON: ' . $e->getMessage());
		}
		if (!is_array($daten)) {
			throw new PlanungException('Das Schema muss ein Objekt mit „abschnitte“ sein');
		}
		return self::ausArray($daten);
	}

	/**
	 * Prüft ein Schema aus der Oberfläche oder Konfiguration und normalisiert es.
	 *
	 * @param array<string,mixed> $daten {abschnitte: [{titel, felder: [{key, label, typ, pflicht?, maxlen?, optionen?, inListe?}]}]}
	 * @throws PlanungException bei ungültigem Schema (Code 400)
	 */
	public static function ausArray(array $daten): self {
		$abschnitte = $daten['abschnitte'] ?? null;
		if (!is_array($abschnitte) || !array_is_list($abschnitte)) {
			throw new PlanungException('Das Schema braucht eine Liste „abschnitte“');
		}
		if (count($abschnitte) > self::MAX_ABSCHNITTE) {
			throw new PlanungException('Höchstens ' . self::MAX_ABSCHNITTE . ' Abschnitte');
		}
		$ergebnis = [];
		$schluessel = [];
		$inListe = 0;
		foreach ($abschnitte as $nummer => $abschnitt) {
			if (!is_array($abschnitt)) {
				throw new PlanungException("Abschnitt {$nummer} ist kein Objekt");
			}
			$titel = self::text($abschnitt['titel'] ?? '', "Titel von Abschnitt {$nummer}", self::MAX_TEXT, true);
			$felder = $abschnitt['felder'] ?? null;
			if (!is_array($felder) || !array_is_list($felder)) {
				throw new PlanungException("Abschnitt „{$titel}“ braucht eine Liste „felder“");
			}
			$geprueft = [];
			foreach ($felder as $feld) {
				$feld = self::feld($feld);
				if (isset($schluessel[$feld['key']])) {
					throw new PlanungException("Der Schlüssel „{$feld['key']}“ kommt doppelt vor");
				}
				$schluessel[$feld['key']] = true;
				if (count($schluessel) > self::MAX_FELDER) {
					throw new PlanungException('Höchstens ' . self::MAX_FELDER . ' Felder');
				}
				$inListe += $feld['inListe'] ? 1 : 0;
				if ($inListe > self::MAX_IN_LISTE) {
					throw new PlanungException('Höchstens ' . self::MAX_IN_LISTE . ' Felder dürfen in der Liste stehen');
				}
				$geprueft[] = $feld;
			}
			$ergebnis[] = ['titel' => $titel, 'felder' => $geprueft];
		}
		return new self($ergebnis);
	}

	/**
	 * @param mixed $feld
	 * @return array{key:string,label:string,typ:string,pflicht:bool,maxlen:int,optionen:list<string>,inListe:bool}
	 */
	private static function feld(mixed $feld): array {
		if (!is_array($feld)) {
			throw new PlanungException('Ein Feld ist kein Objekt');
		}
		$key = $feld['key'] ?? '';
		if (!is_string($key) || preg_match(self::SCHLUESSEL_MUSTER, $key) !== 1) {
			$anzeige = is_string($key) ? mb_substr($key, 0, 40) : gettype($key);
			throw new PlanungException("Ungültiger Schlüssel „{$anzeige}“: erlaubt sind a–z, 0–9 und _, beginnend mit einem Buchstaben, höchstens 31 Zeichen");
		}
		if (in_array($key, self::RESERVIERT, true)) {
			throw new PlanungException("Der Schlüssel „{$key}“ ist reserviert");
		}
		$label = self::text($feld['label'] ?? '', "Bezeichnung von „{$key}“", self::MAX_TEXT, true);
		$typ = $feld['typ'] ?? 'text';
		if (!is_string($typ) || !in_array($typ, self::TYPEN, true)) {
			throw new PlanungException("Feld „{$key}“ hat einen unbekannten Typ");
		}
		$maxlen = $feld['maxlen'] ?? self::MAX_LAENGE;
		if (!is_int($maxlen) || $maxlen < 1 || $maxlen > self::MAX_LAENGE) {
			throw new PlanungException("Feld „{$key}“: maxlen muss eine ganze Zahl zwischen 1 und " . self::MAX_LAENGE . ' sein');
		}
		$optionen = $feld['optionen'] ?? [];
		if (!is_array($optionen) || !array_is_list($optionen)) {
			throw new PlanungException("Feld „{$key}“: optionen muss eine Liste sein");
		}
		if ($typ !== 'select') {
			$optionen = [];
		} else {
			if ($optionen === [] || count($optionen) > self::MAX_OPTIONEN) {
				throw new PlanungException("Feld „{$key}“: eine Auswahl braucht 1 bis " . self::MAX_OPTIONEN . ' Optionen');
			}
			$bereinigt = [];
			foreach ($optionen as $option) {
				$option = self::text($option, "Option von „{$key}“", self::MAX_TEXT, true);
				if (in_array($option, $bereinigt, true)) {
					throw new PlanungException("Feld „{$key}“: Option „{$option}“ kommt doppelt vor");
				}
				$bereinigt[] = $option;
			}
			$optionen = $bereinigt;
		}
		return [
			'key' => $key,
			'label' => $label,
			'typ' => $typ,
			'pflicht' => ($feld['pflicht'] ?? false) === true,
			'maxlen' => $maxlen,
			'optionen' => $optionen,
			'inListe' => ($feld['inListe'] ?? false) === true,
		];
	}

	/** Einzeiliger Text ohne Steuerzeichen; wirft bei falschem Typ, Überlänge oder (falls Pflicht) Leere. */
	private static function text(mixed $wert, string $was, int $maxLaenge, bool $pflicht): string {
		if (!is_string($wert)) {
			throw new PlanungException("{$was} ist kein Text");
		}
		$wert = Einstellungen::einzeilig($wert, $maxLaenge + 1);
		if (mb_strlen($wert) > $maxLaenge) {
			throw new PlanungException("{$was} ist zu lang (höchstens {$maxLaenge} Zeichen)");
		}
		if ($pflicht && $wert === '') {
			throw new PlanungException("{$was} fehlt");
		}
		return $wert;
	}

	/** Felder, die als Spalten in der Liste stehen (höchstens MAX_IN_LISTE, in Schema-Reihenfolge). */
	public function listenFelder(): array {
		return array_values(array_filter($this->felder, fn (array $feld) => $feld['inListe']));
	}

	/** @return array{abschnitte:list<array<string,mixed>>} */
	public function alsArray(): array {
		return ['abschnitte' => $this->abschnitte];
	}

	public function alsJson(): string {
		return json_encode($this->alsArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
	}
}
