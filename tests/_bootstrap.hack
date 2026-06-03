namespace Avro\Tests;

function bootstrap(): void {
  require_once __DIR__.'/../src/Schema.hack';
  require_once __DIR__.'/../src/Encoder.hack';
  require_once __DIR__.'/../src/Decoder.hack';
  require_once __DIR__.'/../src/_Private/SchemaParser.hack';
  require_once __DIR__.'/../src/_Private/Datum.hack';
  require_once __DIR__.'/../src/_Private/Json.hack';
  require_once __DIR__.'/../src/Avro.hack';
  require_once __DIR__.'/../src/Resolution.hack';
  require_once __DIR__.'/../src/Container.hack';
  require_once __DIR__.'/../src/Logical.hack';
  require_once __DIR__.'/../src/Fingerprint.hack';
}
