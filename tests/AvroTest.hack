namespace Avro\Tests;

use function \Avro\{Marshal, Unmarshal, MarshalJson, UnmarshalJson, ParseSchema};
use type \Avro\{Schema, SchemaType, AvroException};

<<__EntryPoint>>
function main(): void {
  require_once __DIR__.'/_bootstrap.hack';
  \Avro\Tests\bootstrap();

  echo "=== Avro-Hack Test Suite ===\n\n";

  test_primitives();
  test_record();
  test_enum();
  test_array_map();
  test_union();
  test_fixed();
  test_json_roundtrip();
  test_schema_reuse();
  test_error_paths();

  echo "\n=== ALL TESTS PASSED ===\n";
}

function assert_eq(mixed $got, mixed $exp, string $msg): void {
  if ($got !== $exp) {
    throw new \Exception($msg."; got: ".\print_r($got, true)." expected: ".\print_r($exp, true));
  }
}

function test_primitives(): void {
  echo "Testing primitives: ";

  $schema = ParseSchema('"null"');
  assert_eq(Unmarshal($schema, Marshal($schema, null)), null, 'null');

  $schema = ParseSchema('"boolean"');
  assert_eq(Unmarshal($schema, Marshal($schema, true)), true, 'true');
  assert_eq(Unmarshal($schema, Marshal($schema, false)), false, 'false');

  $schema = ParseSchema('"int"');
  foreach (vec[0, 1, -1, 42, -42, 2147483647] as $v) {
    assert_eq(Unmarshal($schema, Marshal($schema, $v)), $v, "int $v");
  }

  $schema = ParseSchema('"long"');
  foreach (vec[0, 1, -1, 9223372036854775807, -9223372036854775807 - 1] as $v) {
    assert_eq(Unmarshal($schema, Marshal($schema, $v)), $v, "long $v");
  }

  $schema = ParseSchema('"double"');
  foreach (vec[0.0, 3.141592653589793, -1.0] as $v) {
    assert_eq(Unmarshal($schema, Marshal($schema, $v)), $v, "double");
  }

  $schema = ParseSchema('"string"');
  foreach (vec['', 'hello', "unicode: \xC3\xA9"] as $v) {
    assert_eq(Unmarshal($schema, Marshal($schema, $v)), $v, "string");
  }

  $schema = ParseSchema('"bytes"');
  foreach (vec['', "\x00\x01\xFF"] as $v) {
    assert_eq(Unmarshal($schema, Marshal($schema, $v)), $v, "bytes");
  }

  echo "PASSED\n";
}

function test_record(): void {
  echo "Testing record: ";

  $schema = ParseSchema('{
    "type": "record", "name": "User", "fields": [
      {"name": "name", "type": "string"},
      {"name": "age", "type": "int"},
      {"name": "score", "type": "double"},
      {"name": "active", "type": "boolean"}
    ]
  }');

  $user = dict['name' => 'Alice', 'age' => 30, 'score' => 99.5, 'active' => true];
  $decoded = Unmarshal($schema, Marshal($schema, $user));
  if (!($decoded is dict<_, _>)) throw new \Exception("expected dict");
  assert_eq($decoded['name'], 'Alice', 'name');
  assert_eq($decoded['age'], 30, 'age');
  assert_eq($decoded['score'], 99.5, 'score');
  assert_eq($decoded['active'], true, 'active');

  echo "PASSED\n";
}

function test_enum(): void {
  echo "Testing enum: ";

  $schema = ParseSchema('{"type":"enum","name":"Color","symbols":["RED","GREEN","BLUE"]}');
  assert_eq(Unmarshal($schema, Marshal($schema, 'GREEN')), 'GREEN', 'enum');

  echo "PASSED\n";
}

function test_array_map(): void {
  echo "Testing array and map: ";

  $schema = ParseSchema('{"type":"array","items":"int"}');
  $arr = vec[1, 2, 3, 4, 5];
  $decoded = Unmarshal($schema, Marshal($schema, $arr));
  if (!($decoded is vec<_>)) throw new \Exception("expected vec");
  assert_eq(\count($decoded), 5, 'array count');
  assert_eq($decoded[0], 1, 'arr[0]');

  $schema = ParseSchema('{"type":"map","values":"string"}');
  $map = dict['a' => 'x', 'b' => 'y'];
  $decoded = Unmarshal($schema, Marshal($schema, $map));
  if (!($decoded is dict<_, _>)) throw new \Exception("expected dict");
  assert_eq($decoded['a'], 'x', 'map a');

  echo "PASSED\n";
}

function test_union(): void {
  echo "Testing union: ";

  $schema = ParseSchema('["null", "string"]');
  assert_eq(Unmarshal($schema, Marshal($schema, null)), null, 'union null');
  assert_eq(Unmarshal($schema, Marshal($schema, "hi")), "hi", 'union string');

  $schema = ParseSchema('["null", "int", "string"]');
  assert_eq(Unmarshal($schema, Marshal($schema, 42)), 42, 'union int');

  echo "PASSED\n";
}

function test_fixed(): void {
  echo "Testing fixed: ";

  $schema = ParseSchema('{"type":"fixed","name":"F16","size":16}');
  $val = "0123456789abcdef";
  assert_eq(Unmarshal($schema, Marshal($schema, $val)), $val, 'fixed');

  echo "PASSED\n";
}

function test_json_roundtrip(): void {
  echo "Testing JSON round-trip: ";

  $schema = ParseSchema('{
    "type": "record", "name": "Ev", "fields": [
      {"name": "id", "type": "int"},
      {"name": "tags", "type": {"type": "array", "items": "string"}}
    ]
  }');

  $data = dict['id' => 1, 'tags' => vec['a', 'b']];
  $json = MarshalJson($schema, $data);
  $decoded = UnmarshalJson($schema, $json);
  if (!($decoded is dict<_, _>)) throw new \Exception("expected dict");
  assert_eq($decoded['id'], 1, 'json id');

  echo "PASSED\n";
}

function test_schema_reuse(): void {
  echo "Testing schema reuse: ";

  $schema = ParseSchema('{
    "type": "record", "name": "Container", "fields": [
      {"name": "a", "type": {"type": "record", "name": "Item", "fields": [{"name": "v", "type": "int"}]}},
      {"name": "b", "type": "Item"}
    ]
  }');

  $data = dict['a' => dict['v' => 1], 'b' => dict['v' => 2]];
  $decoded = Unmarshal($schema, Marshal($schema, $data));
  if (!($decoded is dict<_, _>)) throw new \Exception("expected dict");
  $a = $decoded['a'];
  $b = $decoded['b'];
  if (!($a is dict<_, _>) || !($b is dict<_, _>)) throw new \Exception("expected dict items");
  assert_eq($a['v'], 1, 'reuse a');
  assert_eq($b['v'], 2, 'reuse b');

  echo "PASSED\n";
}

function test_error_paths(): void {
  echo "Testing error paths: ";

  try { ParseSchema('"bogus"'); throw new \Exception("should fail"); } catch (AvroException $_) {}
  try { ParseSchema('{invalid}'); throw new \Exception("should fail"); } catch (AvroException $_) {}

  $s = ParseSchema('["null", "int"]');
  try { Marshal($s, "not int or null"); throw new \Exception("should fail"); } catch (AvroException $_) {}

  echo "PASSED\n";
}
