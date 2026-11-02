<?php

declare(strict_types=1);

namespace OCA\CalendarPlanning\Tests\Unit;

use OCA\CalendarPlanning\Service\EinstellungenSpeicher;
use OCA\CalendarPlanning\Service\PlanungException;
use OCA\CalendarPlanning\Service\Schema;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** S-KAL-02: Ein Schema, das sich nicht wieder laden ließe, wird schon beim Speichern abgelehnt. */
class EinstellungenSpeicherTest extends TestCase {
	/** Gültiges Schema mit $felder Auswahlfeldern zu je 50 Optionen von $laenge Zeichen. */
	private static function grossesSchema(int $felder, int $laenge): array {
		$liste = [];
		for ($i = 0; $i < $felder; $i++) {
			$optionen = [];
			for ($j = 0; $j < Schema::MAX_OPTIONEN; $j++) {
				$optionen[] = sprintf('%02d-%02d ', $i, $j) . str_repeat('ä', $laenge - 6);
			}
			$liste[] = ['key' => "f{$i}", 'label' => "Feld {$i}", 'typ' => 'select', 'optionen' => $optionen];
		}
		return ['abschnitte' => [['titel' => 'Groß', 'felder' => $liste]]];
	}

	/** Speicher über einer App-Konfiguration im Arbeitsspeicher. */
	private function speicher(array &$werte): EinstellungenSpeicher {
		$config = $this->createMock(IAppConfig::class);
		$config->method('setValueString')->willReturnCallback(function (string $app, string $schluessel, string $wert) use (&$werte) {
			$werte[$schluessel] = $wert;
			return true;
		});
		$config->method('getValueString')->willReturnCallback(function (string $app, string $schluessel, string $vorgabe = '') use (&$werte) {
			return $werte[$schluessel] ?? $vorgabe;
		});
		return new EinstellungenSpeicher($config, new NullLogger());
	}

	public function testZuGrossesSchemaWirdAbgelehntUndAltesBleibt(): void {
		$werte = [];
		$speicher = $this->speicher($werte);
		$klein = $speicher->schemaSpeichern(self::grossesSchema(2, 20));
		$gespeichert = $werte['schema'];

		// Für sich gültig (Schema::ausArray nimmt es an), aber als JSON weit über Schema::MAX_JSON.
		$gross = self::grossesSchema(10, 100);
		$this->assertGreaterThan(Schema::MAX_JSON, strlen(Schema::ausArray($gross)->alsJson()));
		try {
			$speicher->schemaSpeichern($gross);
			$this->fail('zu großes Schema wurde gespeichert');
		} catch (PlanungException $e) {
			$this->assertSame(400, $e->getCode());
			$this->assertStringContainsString('zu groß', $e->getMessage());
			$this->assertStringContainsString('höchstens 64 KiB', $e->getMessage());
		}
		$this->assertSame($gespeichert, $werte['schema']);
		$this->assertSame($klein->alsArray(), $speicher->laden()->schema->alsArray());
	}

	public function testWasGespeichertWirdLaesstSichWiederLaden(): void {
		$werte = [];
		$speicher = $this->speicher($werte);
		// Knapp unter der Grenze: wird gespeichert und genauso wieder gelesen, nicht durch die Vorgabe ersetzt.
		$schema = self::grossesSchema(6, 100);
		$this->assertLessThanOrEqual(Schema::MAX_JSON, strlen(Schema::ausArray($schema)->alsJson()));
		$neu = $speicher->schemaSpeichern($schema);
		$this->assertSame($neu->alsArray(), $speicher->laden()->schema->alsArray());
		$this->assertNotSame(Schema::vorgabe()->alsArray(), $speicher->laden()->schema->alsArray());
	}
}
