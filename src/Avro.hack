namespace Avro;

function Marshal(Schema $schema, mixed $datum): string {
  $e = new Encoder();
  _Private\WriteDatum($schema, $datum, $e);
  return $e->buffer();
}

function Unmarshal(Schema $schema, string $data): mixed {
  $d = Decoder::FromString($data);
  return _Private\ReadDatum($schema, $d);
}

function MarshalJson(Schema $schema, mixed $datum): string {
  $encoded = _Private\WriteDatumJson($schema, $datum);
  return \json_encode($encoded, \JSON_PRETTY_PRINT | \JSON_PARTIAL_OUTPUT_ON_ERROR);
}

function UnmarshalJson(Schema $schema, string $json): mixed {
  $data = \json_decode($json, true, 512, \JSON_FB_HACK_ARRAYS);
  if ($data === null && $json !== 'null') {
    throw new AvroException("json_decode failed");
  }
  return _Private\ReadDatumJson($schema, $data);
}

function ParseSchema(string $json): Schema {
  $data = \json_decode($json, true, 512, \JSON_FB_HACK_ARRAYS);
  if ($data === null && $json !== 'null') {
    throw new AvroException("schema json_decode failed");
  }
  $registry = new _Private\NameRegistry();
  return _Private\ParseSchemaData($data, $registry);
}
