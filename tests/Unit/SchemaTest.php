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
		yield 'Schlüssel mit iCalendar-Syntax' => [self::schema(self::feld('END:VEVENT')), 'Ungültiger Schlüssel'];
		yield 'Schlüssel mit Großbuchstaben' => [self::schema(self::feld('Zustaendig')), 'Ungültiger Schlüssel'];
		yield 'Schlüssel mit Bindestrich' => [self::schema(self::feld('kontakt-name')), 'Ungültiger Schlüssel'];
		yield 'Schlüssel zu lang' => [self::schema(self::feld(str_repeat('a', 32))), 'Ungültiger Schlüssel'];
		yield 'Schlüssel kein Text' => [self::schema(['key' => 5] + self::feld('a')), 'Ungültiger Schlüssel'];
		yield 'reservierter Schlüssel' => [self::schema(self::feld('titel')), 'reserviert'];
		yield 'doppelter Schlüssel' => [self::schema(self::feld('a'), self::feld('a')), 'doppelt'];
		yield 'unbekannter Typ' => [self::schema(self::feld('a', ['typ' => 'html'])), 'unbekannten Typ'];
		yield 'maxlen zu groß' => [self::schema(self::feld('a', ['maxlen' => 5001])), 'maxlen'];
		yield 'maxlen kein int' => [self::schema(self::feld('a', ['maxlen' => '20'])), 'maxlen'];
		yield 'Auswahl ohne Optionen' => [self::schema(self::feld('a', ['typ' => 'select'])), 'Optionen'];
		yield 'Auswahl mit doppelter Option' => [self::schema(self::feld('a', ['typ' => 'select', 'optionen' => ['x', 'x']])), 'doppelt'];
		yield 'Option kein Text' => [self::schema(self::feld('a', ['typ' => 'select', 'optionen' => [1]])), 'kein Text'];
		yield 'Label fehlt' => [self::schema(['key' => 'a', 'typ' => 'text']), 'fehlt'];
		yield 'Label kein Text' => [self::schema(['key' => 'a', 'label' => ['x'], 'typ' => 'text']), 'kein Text'];
		yield 'Label zu lang' => [self::schema(self::feld('a', ['label' => str_repeat('x', 201)])), 'zu lang'];
		yield 'Abschnittstitel fehlt' => [['abschnitte' => [['felder' => []]]], 'fehlt'];
		yield 'keine Abschnittsliste' => [['abschnitte' => ['titel' => 'x']], 'Liste'];
		yield 'Feld kein Objekt' => [self::schema('text'), 'kein Objekt'];
		yield 'zu viele Listenfelder' => [self::schema(...array_map(fn ($i) => self::feld("f{$i}", ['inListe' => true]), range(1, 4))), 'in der Liste'];
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
