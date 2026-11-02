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

	/** `\z` statt `$`: `$` ließe einen Zeilenumbruch am Ende durch („abc\n“). */
	public const SCHLUESSEL_MUSTER = '/^[a-z][a-z0-9_]{0,30}\z/';
	public const MAX_FELDER = 50;
	public const MAX_ABSCHNITTE = 20;
	public const MAX_IN_LISTE = 3;
	public const MAX_OPTIONEN = 50;
	public const MAX_LAENGE = 5000;
	public const MAX_TEXT = 200;
	public const MAX_JSON = 65536;

	/**
	 * Schlüssel, die das Formular selbst oder die Antwort der API belegen, und `link`: Dessen Eigenschaft
	 * X-CALPLAN-LINK merkt sich die zuletzt geschriebene Link-Zeile der Beschreibung (Planung::EIGENSCHAFT_LINK).
	 */
	public const RESERVIERT = [
		'titel', 'ort', 'ort_extern', 'uid', 'beginn', 'ende', 'ganztags', 'serie', 'beschreibung',
		'geplant', 'kalender', 'objekt', 'etag', 'schreibbar', 'felder', 'link',
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
	 * Fehlermeldungen richten sich an Admins: Abschnitte und Felder werden wie im Editor ab 1 gezählt und,
	 * wo bekannt, mit ihrer Bezeichnung genannt (U-KAL-19).
	 *
	 * @param array<string,mixed> $daten {abschnitte: [{titel, felder: [{key, label, typ, pflicht?, maxlen?, optionen?, inListe?}]}]}
	 * @throws PlanungException bei ungültigem Schema (Code 400)
	 */
	public static function ausArray(array $daten): self {
		$abschnitte = $daten['abschnitte'] ?? null;
		if (!is_array($abschnitte) || !array_is_list($abschnitte)) {
			throw new PlanungException('Die Felder ließen sich nicht lesen: Die Liste der Abschnitte fehlt.');
		}
		if (count($abschnitte) > self::MAX_ABSCHNITTE) {
			throw new PlanungException('Höchstens ' . self::MAX_ABSCHNITTE . ' Abschnitte sind möglich.');
		}
		$ergebnis = [];
		/** @var array<string,string> $schluessel Schlüssel => Stelle, an der er zuerst vorkommt */
		$schluessel = [];
		$inListe = 0;
		foreach ($abschnitte as $index => $abschnitt) {
			$nummer = $index + 1;
			if (!is_array($abschnitt)) {
				throw new PlanungException("Abschnitt {$nummer} ist ungültig aufgebaut.");
			}
			$titel = self::text($abschnitt['titel'] ?? '', "Die Überschrift von Abschnitt {$nummer}", self::MAX_TEXT, true);
			$felder = $abschnitt['felder'] ?? null;
			if (!is_array($felder) || !array_is_list($felder)) {
				throw new PlanungException("Abschnitt {$nummer} („{$titel}“): Die Liste der Felder fehlt.");
			}
			$geprueft = [];
			foreach ($felder as $feldIndex => $feld) {
				$stelle = self::stelle($feld, $feldIndex + 1, $nummer);
				$feld = self::feld($feld, $stelle);
				if (isset($schluessel[$feld['key']])) {
					throw new PlanungException("{$stelle}: Der Schlüssel „{$feld['key']}“ ist schon bei {$schluessel[$feld['key']]} vergeben. Jedes Feld braucht einen eigenen Schlüssel.");
				}
				$schluessel[$feld['key']] = $stelle;
				if (count($schluessel) > self::MAX_FELDER) {
					throw new PlanungException('Höchstens ' . self::MAX_FELDER . " Felder sind möglich; {$stelle} ist eines zu viel.");
				}
				$inListe += $feld['inListe'] ? 1 : 0;
				if ($inListe > self::MAX_IN_LISTE) {
					throw new PlanungException('Höchstens ' . self::MAX_IN_LISTE . " Felder dürfen in der Liste stehen; mit {$stelle} wären es " . $inListe . '. Bitte bei einem Feld „Liste“ abwählen.');
				}
				$geprueft[] = $feld;
			}
			$ergebnis[] = ['titel' => $titel, 'felder' => $geprueft];
		}
		return new self($ergebnis);
	}

	/** „Feld 2 in Abschnitt 1 („Bezeichnung“)“ – die Bezeichnung nur, wenn sie lesbarer Text ist. */
	private static function stelle(mixed $feld, int $nummer, int $abschnitt): string {
		$stelle = "Feld {$nummer} in Abschnitt {$abschnitt}";
		$label = is_array($feld) && is_string($feld['label'] ?? null) ? Einstellungen::einzeilig($feld['label'], 61) : '';
		if ($label === '') {
			return $stelle;
		}
		return $stelle . ' („' . (mb_strlen($label) > 60 ? mb_substr($label, 0, 59) . '…' : $label) . '“)';
	}

	/**
	 * @param mixed $feld
	 * @param string $stelle für Meldungen, z. B. „Feld 2 in Abschnitt 1 („Name“)“
	 * @return array{key:string,label:string,typ:string,pflicht:bool,maxlen:int,optionen:list<string>,inListe:bool}
	 */
	private static function feld(mixed $feld, string $stelle): array {
		if (!is_array($feld)) {
			throw new PlanungException("{$stelle} ist ungültig aufgebaut.");
		}
		$key = $feld['key'] ?? '';
		if (!is_string($key) || preg_match(self::SCHLUESSEL_MUSTER, $key) !== 1) {
			$anzeige = is_string($key) ? Einstellungen::einzeilig(mb_substr($key, 0, 40), 40) : '';
			$was = $anzeige === '' ? 'Der Schlüssel fehlt oder ist ungültig' : "Der Schlüssel „{$anzeige}“ ist ungültig";
			throw new PlanungException("{$stelle}: {$was}. Erlaubt sind Kleinbuchstaben a–z, Ziffern 0–9 und _, am Anfang ein Buchstabe, höchstens 31 Zeichen.");
		}
		if (in_array($key, self::RESERVIERT, true)) {
			throw new PlanungException("{$stelle}: Der Schlüssel „{$key}“ ist für das Formular reserviert. Bitte einen anderen wählen.");
		}
		$label = self::text($feld['label'] ?? '', "{$stelle}: Die Bezeichnung", self::MAX_TEXT, true);
		$typ = $feld['typ'] ?? 'text';
		if (!is_string($typ) || !in_array($typ, self::TYPEN, true)) {
			throw new PlanungException("{$stelle}: Der Typ ist unbekannt. Erlaubt: " . implode(', ', self::TYPEN) . '.');
		}
		$maxlen = $feld['maxlen'] ?? self::MAX_LAENGE;
		if (!is_int($maxlen) || $maxlen < 1 || $maxlen > self::MAX_LAENGE) {
			throw new PlanungException("{$stelle}: „Max. Zeichen“ muss eine ganze Zahl von 1 bis " . self::MAX_LAENGE . ' sein.');
		}
		$optionen = $feld['optionen'] ?? [];
		if (!is_array($optionen) || !array_is_list($optionen)) {
			throw new PlanungException("{$stelle}: Die Optionen sind keine Liste.");
		}
		if ($typ !== 'select') {
			$optionen = [];
		} else {
			if ($optionen === [] || count($optionen) > self::MAX_OPTIONEN) {
				throw new PlanungException("{$stelle}: Eine Auswahl braucht 1 bis " . self::MAX_OPTIONEN . ' Optionen, eine je Zeile.');
			}
			$bereinigt = [];
			foreach ($optionen as $i => $option) {
				$option = self::text($option, "{$stelle}: Option " . ($i + 1), self::MAX_TEXT, true);
				if (in_array($option, $bereinigt, true)) {
					throw new PlanungException("{$stelle}: Die Option „{$option}“ kommt doppelt vor.");
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
			throw new PlanungException("{$was} ist kein Text.");
		}
		$wert = Einstellungen::einzeilig($wert, $maxLaenge + 1);
		if (mb_strlen($wert) > $maxLaenge) {
			throw new PlanungException("{$was} ist zu lang (höchstens {$maxLaenge} Zeichen).");
		}
		if ($pflicht && $wert === '') {
			throw new PlanungException("{$was} fehlt.");
		}
		return $wert;
	}

	/** Felder, die als Spalten in der Liste stehen (höchstens MAX_IN_LISTE, in Schema-Reihenfolge). */
	public function listenFelder(): array {
		return array_values(array_filter($this->felder, fn (array $feld) => $feld['inListe']));
	}

	/**
	 * Felder dieses Schemas, deren Schlüssel im neuen Schema fehlt (Q-KAL-01). Erfasste Werte solcher Felder
	 * bleiben im Termin, erscheinen aber nicht mehr im Formular. Ob es solche Werte gibt, weiß das Schema nicht;
	 * dafür müsste die App alle Kalender aller Konten lesen.
	 *
	 * @return list<array{key:string,label:string}>
	 */
	public function fehlendIn(self $neu): array {
		$fehlend = [];
		foreach ($this->felder as $key => $feld) {
			if (!isset($neu->felder[$key])) {
				$fehlend[] = ['key' => $key, 'label' => $feld['label']];
			}
		}
		return $fehlend;
	}

	/** @return array{abschnitte:list<array<string,mixed>>} */
	public function alsArray(): array {
		return ['abschnitte' => $this->abschnitte];
	}

	public function alsJson(): string {
		return json_encode($this->alsArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
	}
}
