<?php

declare(strict_types=1);

namespace OCA\CalendarPlanning\Tests\Unit;

use OCA\CalendarPlanning\Service\Planung;
use OCA\CalendarPlanning\Service\PlanungException;
use OCA\CalendarPlanning\Service\Schema;
use PHPUnit\Framework\TestCase;

class SchemaTest extends TestCase {
	/** @return array<string,mixed> */
	private static function feld(string $key, array $extra = []): array {
		return $extra + ['key' => $key, 'label' => 'Feld ' . $key, 'typ' => 'text'];
	}

	/** @return array<string,mixed> */
	private static function schema(mixed ...$felder): array {
		return ['abschnitte' => [['titel' => 'Test', 'felder' => $felder]]];
	}

	public function testVorgabeHatDieSechzehnFelderMitGueltigenEigenschaftsnamen(): void {
		$schema = Schema::vorgabe();
		$this->assertCount(16, $schema->felder);
		$this->assertCount(5, $schema->abschnitte);
		$this->assertSame(['zustaendig', 'kontakt_organisation', 'teilnehmende'], array_column($schema->listenFelder(), 'key'));
		foreach ($schema->felder as $key => $feld) {
			$this->assertMatchesRegularExpression('/^X-CALPLAN-[A-Z0-9-]+$/', Planung::eigenschaft($key));
			$this->assertContains($feld['typ'], Schema::TYPEN);
		}
		$this->assertSame('X-CALPLAN-KONTAKT-EMAIL', Planung::eigenschaft('kontakt_email'));
	}

	public function testPresetLaedtUndNenntDieAlteBezeichnung(): void {
		$preset = Schema::ausDatei(__DIR__ . '/../../schema/preset-msp.json');
		$this->assertSame('Benötigte Maschinen / Tools', $preset->felder['maschinen']['label']);
		$this->assertCount(16, $preset->felder);
	}

	public function testJsonHinUndZurueckIstStabil(): void {
		$schema = Schema::vorgabe();
		$this->assertSame($schema->alsArray(), Schema::ausJson($schema->alsJson())->alsArray());
	}

	public function testNormalisiertFehlendeAngaben(): void {
		$schema = Schema::ausArray(self::schema(['key' => 'a', 'label' => " Ein\tFeld ", 'typ' => 'select', 'optionen' => ['x', 'y'], 'pflicht' => 'ja', 'maxlen' => 20]));
		$feld = $schema->felder['a'];
		$this->assertSame('Ein Feld', $feld['label']);
		$this->assertFalse($feld['pflicht'], 'nur echtes true ist Pflicht');
		$this->assertFalse($feld['inListe']);
		$this->assertSame(20, $feld['maxlen']);
		$this->assertSame(['x', 'y'], $feld['optionen']);
		// Optionen gibt es nur bei Auswahlfeldern.
		$ohne = Schema::ausArray(self::schema(['key' => 'b', 'label' => 'B', 'typ' => 'text', 'optionen' => ['weg']]));
		$this->assertSame([], $ohne->felder['b']['optionen']);
	}

	/** @return iterable<string,array{array<string,mixed>,string}> */
	public static function ungueltig(): iterable {
		yield 'Schlüssel mit iCalendar-Syntax' => [self::schema(self::feld('END:VEVENT')), 'Der Schlüssel „END:VEVENT“ ist ungültig'];
		yield 'Schlüssel mit Großbuchstaben' => [self::schema(self::feld('Zustaendig')), 'ist ungültig. Erlaubt sind Kleinbuchstaben'];
		yield 'Schlüssel mit Bindestrich' => [self::schema(self::feld('kontakt-name')), 'ist ungültig'];
		yield 'Schlüssel zu lang' => [self::schema(self::feld(str_repeat('a', 32))), 'höchstens 31 Zeichen'];
		yield 'Schlüssel kein Text' => [self::schema(['key' => 5] + self::feld('a')), 'Der Schlüssel fehlt oder ist ungültig'];
		yield 'reservierter Schlüssel' => [self::schema(self::feld('titel')), 'reserviert'];
		yield 'doppelter Schlüssel' => [self::schema(self::feld('a'), self::feld('a')), 'Feld 2 in Abschnitt 1 („Feld a“): Der Schlüssel „a“ ist schon bei Feld 1 in Abschnitt 1 („Feld a“) vergeben.'];
		yield 'unbekannter Typ' => [self::schema(self::feld('a', ['typ' => 'html'])), 'Der Typ ist unbekannt'];
		yield 'maxlen zu groß' => [self::schema(self::feld('a', ['maxlen' => 5001])), '„Max. Zeichen“ muss eine ganze Zahl von 1 bis 5000 sein'];
		yield 'maxlen kein int' => [self::schema(self::feld('a', ['maxlen' => '20'])), 'Max. Zeichen'];
		yield 'Auswahl ohne Optionen' => [self::schema(self::feld('a', ['typ' => 'select'])), 'Eine Auswahl braucht 1 bis 50 Optionen'];
		yield 'Auswahl mit doppelter Option' => [self::schema(self::feld('a', ['typ' => 'select', 'optionen' => ['x', 'x']])), 'Die Option „x“ kommt doppelt vor'];
		yield 'Option kein Text' => [self::schema(self::feld('a', ['typ' => 'select', 'optionen' => [1]])), 'Option 1 ist kein Text'];
		yield 'Label fehlt' => [self::schema(['key' => 'a', 'typ' => 'text']), 'Feld 1 in Abschnitt 1: Die Bezeichnung fehlt.'];
		yield 'Label kein Text' => [self::schema(['key' => 'a', 'label' => ['x'], 'typ' => 'text']), 'kein Text'];
		yield 'Label zu lang' => [self::schema(self::feld('a', ['label' => str_repeat('x', 201)])), 'zu lang'];
		yield 'Abschnittstitel fehlt' => [['abschnitte' => [['felder' => []]]], 'Die Überschrift von Abschnitt 1 fehlt.'];
		yield 'keine Abschnittsliste' => [['abschnitte' => ['titel' => 'x']], 'Liste der Abschnitte fehlt'];
		yield 'Feld kein Objekt' => [self::schema('text'), 'Feld 1 in Abschnitt 1 ist ungültig aufgebaut.'];
		yield 'zu viele Listenfelder' => [self::schema(...array_map(fn ($i) => self::feld("f{$i}", ['inListe' => true]), range(1, 4))), 'Höchstens 3 Felder dürfen in der Liste stehen; mit Feld 4 in Abschnitt 1 („Feld f4“) wären es 4.'];
	}

	/**
	 * @dataProvider ungueltig
	 * @param array<string,mixed> $daten
	 */
	public function testUngueltigeSchemataWerdenAbgelehnt(array $daten, string $meldung): void {
		try {
			Schema::ausArray($daten);
			$this->fail('nicht abgelehnt');
		} catch (PlanungException $e) {
			$this->assertSame(400, $e->getCode());
			$this->assertStringContainsString($meldung, $e->getMessage());
		}
	}

	public function testMeldungenZaehlenAbEinsUndNennenDieBezeichnung(): void {
		$daten = ['abschnitte' => [
			['titel' => 'Grunddaten', 'felder' => [self::feld('a')]],
			['titel' => 'Kontakt', 'felder' => [self::feld('b'), ['key' => 'c', 'label' => 'Telefon', 'typ' => 'html']]],
		]];
		try {
			Schema::ausArray($daten);
			$this->fail('nicht abgelehnt');
		} catch (PlanungException $e) {
			$this->assertSame('Feld 2 in Abschnitt 2 („Telefon“): Der Typ ist unbekannt. Erlaubt: ' . implode(', ', Schema::TYPEN) . '.', $e->getMessage());
		}
		// Abschnitt ohne Feldliste: Nummer ab 1 und Überschrift.
		try {
			Schema::ausArray(['abschnitte' => [['titel' => 'A', 'felder' => []], ['titel' => 'Kontakt']]]);
			$this->fail('nicht abgelehnt');
		} catch (PlanungException $e) {
			$this->assertSame('Abschnitt 2 („Kontakt“): Die Liste der Felder fehlt.', $e->getMessage());
		}
		// Kein Hinweis auf technische Namen wie „maxlen“ oder nullbasierte Nummern.
		try {
			Schema::ausArray(['abschnitte' => [['titel' => 'A', 'felder' => [self::feld('a', ['maxlen' => 0, 'label' => "Lange\nBezeichnung " . str_repeat('x', 80)])]]]]);
			$this->fail('nicht abgelehnt');
		} catch (PlanungException $e) {
			$this->assertStringNotContainsString('maxlen', $e->getMessage());
			$this->assertStringNotContainsString('Abschnitt 0', $e->getMessage());
			$this->assertStringStartsWith('Feld 1 in Abschnitt 1 („Lange Bezeichnung xxx', $e->getMessage());
			$this->assertStringContainsString('…“): „Max. Zeichen“', $e->getMessage());
		}
	}

	public function testFehlendInNenntWeggefalleneFelderMitBezeichnung(): void {
		$alt = Schema::vorgabe();
		$this->assertSame([], $alt->fehlendIn($alt));
		$daten = $alt->alsArray();
		// Bezeichnung geändert, Schlüssel gleich: kein Feld fehlt.
		$daten['abschnitte'][0]['felder'][0]['label'] = 'Zuständig (Person)';
		$this->assertSame([], $alt->fehlendIn(Schema::ausArray($daten)));
		// Schlüssel geändert: das alte Feld fehlt.
		$daten['abschnitte'][0]['felder'][0]['key'] = 'zustaendig_person';
		$this->assertSame([['key' => 'zustaendig', 'label' => 'Zuständig intern']], $alt->fehlendIn(Schema::ausArray($daten)));
		// Vorlage „Makerspace“ statt Vorgabe: was fehlt, steht in der Liste, in Schema-Reihenfolge.
		$preset = Schema::ausDatei(__DIR__ . '/../../schema/preset-msp.json');
		$fehlend = array_column($alt->fehlendIn($preset), 'key');
		$this->assertSame(['ausstattung'], $fehlend);
	}

	public function testHoechstensFuenfzigFelder(): void {
		$felder = array_map(fn ($i) => self::feld("f{$i}"), range(1, 50));
		$this->assertCount(50, Schema::ausArray(self::schema(...$felder))->felder);
		$this->expectException(PlanungException::class);
		$this->expectExceptionMessage('Höchstens 50 Felder');
		Schema::ausArray(self::schema(...[...$felder, self::feld('f51')]));
	}

	public function testJsonGrenzen(): void {
		try {
			Schema::ausJson(str_repeat(' ', Schema::MAX_JSON + 1));
			$this->fail('zu groß nicht erkannt');
		} catch (PlanungException $e) {
			$this->assertStringContainsString('zu groß', $e->getMessage());
		}
		try {
			Schema::ausJson('{"abschnitte": [');
			$this->fail('kaputtes JSON nicht erkannt');
		} catch (PlanungException $e) {
			$this->assertStringContainsString('JSON', $e->getMessage());
		}
		$this->expectException(PlanungException::class);
		Schema::ausJson('"nur ein Text"');
	}

	public function testFehlendeDateiIstServerfehler(): void {
		try {
			Schema::ausDatei(__DIR__ . '/gibt-es-nicht.json');
			$this->fail('fehlende Datei nicht erkannt');
		} catch (PlanungException $e) {
			$this->assertSame(500, $e->getCode());
		}
	}
}
