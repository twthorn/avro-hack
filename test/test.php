<?hh // strict

<<__EntryPoint>>
function main(): void {
  require "lib/errors.php";
  require "lib/avro.php";

  echo "=== Avro-Hack Test Suite ===\n\n";

  testPrimitives();
  testUserSchema();
  testEventSchema();
  testNestedSchema();
  testJsonRoundTrip();
  testEdgeCases();
  testUnionTypes();

  testErrorPaths();
  testDefaultValues();
  testFixedType();
  testSchemaReuse();

  echo "\n=== ALL TESTS PASSED ===\n";
}

function a(mixed $got, mixed $exp, string $msg): void {
  if ($got != $exp) {
    throw new Exception(
      $msg.
      "; got:\n".
      \print_r($got, true).
      "\n expected:\n".
      \print_r($exp, true),
    );
  }
}

function aeq(mixed $got, mixed $exp, string $msg): void {
  if ($got !== $exp) {
    throw new Exception(
      $msg.
      "; got:\n".
      \print_r($got, true).
      "\n expected:\n".
      \print_r($exp, true),
    );
  }
}

function testPrimitives(): void {
  echo "Testing primitives: ";

  // null
  $schema = Avro\ParseSchema('"null"');
  $encoded = Avro\Marshal($schema, null);
  aeq($encoded, '', 'null should encode to empty');
  $decoded = Avro\Unmarshal($schema, $encoded);
  aeq($decoded, null, 'null should decode to null');

  // boolean
  $schema = Avro\ParseSchema('"boolean"');
  $encoded = Avro\Marshal($schema, true);
  $decoded = Avro\Unmarshal($schema, $encoded);
  aeq($decoded, true, 'bool true roundtrip');
  $encoded = Avro\Marshal($schema, false);
  $decoded = Avro\Unmarshal($schema, $encoded);
  aeq($decoded, false, 'bool false roundtrip');

  // int
  $schema = Avro\ParseSchema('"int"');
  $test_ints = vec[0, 1, -1, 42, -42, 2147483647, -2147483648];
  foreach ($test_ints as $v) {
    $encoded = Avro\Marshal($schema, $v);
    $decoded = Avro\Unmarshal($schema, $encoded);
    aeq($decoded, $v, "int roundtrip for ".$v);
  }

  // long
  $schema = Avro\ParseSchema('"long"');
  $test_longs = vec[0, 1, -1, 9223372036854775807, -9223372036854775807 - 1];
  foreach ($test_longs as $v) {
    $encoded = Avro\Marshal($schema, $v);
    $decoded = Avro\Unmarshal($schema, $encoded);
    aeq($decoded, $v, "long roundtrip for ".$v);
  }

  // float
  $schema = Avro\ParseSchema('"float"');
  $encoded = Avro\Marshal($schema, 3.14);
  $decoded = Avro\Unmarshal($schema, $encoded);
  // float precision loss is expected
  $diff = \abs((float)$decoded - 3.14);
  if ($diff > 0.001) {
    throw new Exception("float roundtrip failed, diff: ".$diff);
  }

  // double
  $schema = Avro\ParseSchema('"double"');
  $test_doubles = vec[0.0, 1.0, -1.0, 3.141592653589793, 1.7976931348623157e+308];
  foreach ($test_doubles as $v) {
    $encoded = Avro\Marshal($schema, $v);
    $decoded = Avro\Unmarshal($schema, $encoded);
    aeq($decoded, $v, "double roundtrip for ".$v);
  }

  // string
  $schema = Avro\ParseSchema('"string"');
  $test_strings = vec['', 'hello', 'Hello, World!', "unicode: \xC3\xA9\xC3\xA0\xC3\xBC"];
  foreach ($test_strings as $v) {
    $encoded = Avro\Marshal($schema, $v);
    $decoded = Avro\Unmarshal($schema, $encoded);
    aeq($decoded, $v, "string roundtrip");
  }

  // bytes
  $schema = Avro\ParseSchema('"bytes"');
  $test_bytes = vec['', "\x00\x01\x02\xFF", "binary\x00data"];
  foreach ($test_bytes as $v) {
    $encoded = Avro\Marshal($schema, $v);
    $decoded = Avro\Unmarshal($schema, $encoded);
    aeq($decoded, $v, "bytes roundtrip");
  }

  echo "PASSED\n";
}

function testUserSchema(): void {
  echo "Testing user schema: ";

  $schema_json = \file_get_contents('schemas/user.avsc');
  $schema = Avro\ParseSchema($schema_json);

  $user = dict[
    'name' => 'Alice Smith',
    'age' => 30,
    'email' => 'alice@example.com',
    'score' => 99.5,
    'active' => true,
  ];

  $encoded = Avro\Marshal($schema, $user);
  $decoded = Avro\Unmarshal($schema, $encoded);

  if (!($decoded is dict<_, _>)) {
    throw new Exception("expected dict from record decode");
  }
  aeq($decoded['name'], 'Alice Smith', 'user name');
  aeq($decoded['age'], 30, 'user age');
  aeq($decoded['email'], 'alice@example.com', 'user email');
  aeq($decoded['score'], 99.5, 'user score');
  aeq($decoded['active'], true, 'user active');

  // Test with null optional field
  $user_no_email = dict[
    'name' => 'Bob',
    'age' => 25,
    'email' => null,
    'score' => 0.0,
    'active' => false,
  ];

  $encoded = Avro\Marshal($schema, $user_no_email);
  $decoded = Avro\Unmarshal($schema, $encoded);

  if (!($decoded is dict<_, _>)) {
    throw new Exception("expected dict from record decode");
  }
  aeq($decoded['name'], 'Bob', 'user2 name');
  aeq($decoded['age'], 25, 'user2 age');
  aeq($decoded['email'], null, 'user2 email should be null');
  aeq($decoded['score'], 0.0, 'user2 score');
  aeq($decoded['active'], false, 'user2 active');

  echo "PASSED\n";
}

function testEventSchema(): void {
  echo "Testing event schema: ";

  $schema_json = \file_get_contents('schemas/event.avsc');
  $schema = Avro\ParseSchema($schema_json);

  $event = dict[
    'id' => 123456789,
    'type' => 'PURCHASE',
    'timestamp' => 1700000000000,
    'tags' => vec['promo', 'flash-sale', 'electronics'],
    'metadata' => dict[
      'source' => 'mobile',
      'region' => 'us-west-2',
    ],
    'payload' => "\x01\x02\x03\x04",
  ];

  $encoded = Avro\Marshal($schema, $event);
  $decoded = Avro\Unmarshal($schema, $encoded);

  if (!($decoded is dict<_, _>)) {
    throw new Exception("expected dict from event decode");
  }
  aeq($decoded['id'], 123456789, 'event id');
  aeq($decoded['type'], 'PURCHASE', 'event type');
  aeq($decoded['timestamp'], 1700000000000, 'event timestamp');
  a($decoded['tags'], vec['promo', 'flash-sale', 'electronics'], 'event tags');
  a($decoded['metadata'], dict['source' => 'mobile', 'region' => 'us-west-2'], 'event metadata');
  aeq($decoded['payload'], "\x01\x02\x03\x04", 'event payload');

  // Test empty collections
  $event_empty = dict[
    'id' => 0,
    'type' => 'CLICK',
    'timestamp' => 0,
    'tags' => vec[],
    'metadata' => dict[],
    'payload' => '',
  ];

  $encoded = Avro\Marshal($schema, $event_empty);
  $decoded = Avro\Unmarshal($schema, $encoded);

  if (!($decoded is dict<_, _>)) {
    throw new Exception("expected dict from event decode");
  }
  a($decoded['tags'], vec[], 'empty tags');
  a($decoded['metadata'], dict[], 'empty metadata');

  echo "PASSED\n";
}

function testNestedSchema(): void {
  echo "Testing nested schema: ";

  $schema_json = \file_get_contents('schemas/nested.avsc');
  $schema = Avro\ParseSchema($schema_json);

  $tracking = "0123456789abcdef";

  $order = dict[
    'order_id' => 'ORD-001',
    'customer' => dict[
      'id' => 42,
      'name' => 'Charlie',
      'tier' => 'GOLD',
    ],
    'items' => vec[
      dict['sku' => 'WIDGET-A', 'quantity' => 2, 'price_cents' => 1999],
      dict['sku' => 'GADGET-B', 'quantity' => 1, 'price_cents' => 4999],
    ],
    'shipping_address' => '123 Main St',
    'tracking_id' => $tracking,
  ];

  $encoded = Avro\Marshal($schema, $order);
  $decoded = Avro\Unmarshal($schema, $encoded);

  if (!($decoded is dict<_, _>)) {
    throw new Exception("expected dict from order decode");
  }
  aeq($decoded['order_id'], 'ORD-001', 'order_id');

  $customer = $decoded['customer'];
  if (!($customer is dict<_, _>)) {
    throw new Exception("expected dict from customer decode");
  }
  aeq($customer['id'], 42, 'customer id');
  aeq($customer['name'], 'Charlie', 'customer name');
  aeq($customer['tier'], 'GOLD', 'customer tier');

  $items = $decoded['items'];
  if (!($items is vec<_>)) {
    throw new Exception("expected vec from items decode");
  }
  aeq(\count($items), 2, 'items count');

  $item0 = $items[0];
  if (!($item0 is dict<_, _>)) {
    throw new Exception("expected dict from item decode");
  }
  aeq($item0['sku'], 'WIDGET-A', 'item0 sku');
  aeq($item0['quantity'], 2, 'item0 quantity');
  aeq($item0['price_cents'], 1999, 'item0 price');

  aeq($decoded['shipping_address'], '123 Main St', 'shipping address');
  aeq($decoded['tracking_id'], $tracking, 'tracking_id');

  echo "PASSED\n";
}

function testJsonRoundTrip(): void {
  echo "Testing JSON round-trip: ";

  $schema_json = \file_get_contents('schemas/user.avsc');
  $schema = Avro\ParseSchema($schema_json);

  $user = dict[
    'name' => 'Diana',
    'age' => 28,
    'email' => 'diana@test.com',
    'score' => 88.8,
    'active' => true,
  ];

  $json = Avro\MarshalJson($schema, $user);
  $decoded = Avro\UnmarshalJson($schema, $json);

  if (!($decoded is dict<_, _>)) {
    throw new Exception("expected dict from JSON decode");
  }
  aeq($decoded['name'], 'Diana', 'json user name');
  aeq($decoded['age'], 28, 'json user age');
  aeq($decoded['score'], 88.8, 'json user score');
  aeq($decoded['active'], true, 'json user active');

  // Test enum JSON
  $schema_json = \file_get_contents('schemas/event.avsc');
  $schema = Avro\ParseSchema($schema_json);

  $event = dict[
    'id' => 1,
    'type' => 'VIEW',
    'timestamp' => 1000,
    'tags' => vec['test'],
    'metadata' => dict['key' => 'value'],
    'payload' => "hi",
  ];

  $json = Avro\MarshalJson($schema, $event);
  $decoded = Avro\UnmarshalJson($schema, $json);

  if (!($decoded is dict<_, _>)) {
    throw new Exception("expected dict from JSON event decode");
  }
  aeq($decoded['type'], 'VIEW', 'json event type');
  a($decoded['tags'], vec['test'], 'json event tags');

  echo "PASSED\n";
}

function testEdgeCases(): void {
  echo "Testing edge cases: ";

  // Large varint values
  $schema = Avro\ParseSchema('"long"');
  $large_values = vec[
    0,
    1,
    -1,
    127,
    128,
    -128,
    16383,
    16384,
    2147483647,
    -2147483648,
    9223372036854775807,
    -9223372036854775807 - 1,
  ];
  foreach ($large_values as $v) {
    $encoded = Avro\Marshal($schema, $v);
    $decoded = Avro\Unmarshal($schema, $encoded);
    aeq($decoded, $v, "varint edge case for ".$v);
  }

  // Empty record
  $schema = Avro\ParseSchema('{"type":"record","name":"Empty","fields":[]}');
  $encoded = Avro\Marshal($schema, dict[]);
  $decoded = Avro\Unmarshal($schema, $encoded);
  a($decoded, dict[], 'empty record');

  // Deeply nested arrays
  $schema = Avro\ParseSchema('{"type":"array","items":"int"}');
  $big_arr = vec[];
  for ($i = 0; $i < 1000; $i++) {
    $big_arr[] = $i;
  }
  $encoded = Avro\Marshal($schema, $big_arr);
  $decoded = Avro\Unmarshal($schema, $encoded);
  a($decoded, $big_arr, 'large array roundtrip');

  echo "PASSED\n";
}

function testUnionTypes(): void {
  echo "Testing union types: ";

  // Simple nullable string
  $schema = Avro\ParseSchema('["null", "string"]');
  $encoded = Avro\Marshal($schema, null);
  $decoded = Avro\Unmarshal($schema, $encoded);
  aeq($decoded, null, 'nullable null');

  $encoded = Avro\Marshal($schema, "hello");
  $decoded = Avro\Unmarshal($schema, $encoded);
  aeq($decoded, "hello", 'nullable string');

  // Union with multiple types
  $schema = Avro\ParseSchema('["null", "int", "string"]');
  $encoded = Avro\Marshal($schema, null);
  $decoded = Avro\Unmarshal($schema, $encoded);
  aeq($decoded, null, 'multi-union null');

  $encoded = Avro\Marshal($schema, 42);
  $decoded = Avro\Unmarshal($schema, $encoded);
  aeq($decoded, 42, 'multi-union int');

  $encoded = Avro\Marshal($schema, "world");
  $decoded = Avro\Unmarshal($schema, $encoded);
  aeq($decoded, "world", 'multi-union string');

  echo "PASSED\n";
}

function expectException(string $msg, (function(): void) $fn): void {
  try {
    $fn();
    throw new Exception("expected exception for: ".$msg);
  } catch (\AvroException $_e) {
    // expected
  }
}

function testErrorPaths(): void {
  echo "Testing error paths: ";

  // Unknown type in schema
  expectException("unknown type", () ==> {
    Avro\ParseSchema('"bogus"');
  });

  // Invalid schema JSON
  expectException("invalid json", () ==> {
    Avro\ParseSchema('{not valid json}');
  });

  // Enum with unknown symbol
  $schema = Avro\ParseSchema('{"type":"enum","name":"Color","symbols":["RED","GREEN","BLUE"]}');
  expectException("unknown enum symbol", () ==> {
    $s = Avro\ParseSchema('{"type":"enum","name":"Color","symbols":["RED","GREEN","BLUE"]}');
    Avro\Marshal($s, "YELLOW");
  });

  // Union mismatch
  expectException("union mismatch", () ==> {
    $s = Avro\ParseSchema('["null", "int"]');
    Avro\Marshal($s, "not an int or null");
  });

  // Fixed size mismatch
  expectException("fixed size mismatch", () ==> {
    $s = Avro\ParseSchema('{"type":"fixed","name":"F4","size":4}');
    Avro\Marshal($s, "toolong");
  });

  // Buffer overrun on truncated data
  expectException("buffer overrun", () ==> {
    $s = Avro\ParseSchema('"string"');
    // Encode a long string length but provide no data
    Avro\Unmarshal($s, "\x80\x01");
  });

  echo "PASSED\n";
}

function testDefaultValues(): void {
  echo "Testing default values: ";

  $schema = Avro\ParseSchema('{
    "type": "record",
    "name": "WithDefaults",
    "fields": [
      {"name": "required_field", "type": "string"},
      {"name": "optional_field", "type": "string", "default": "default_value"},
      {"name": "nullable", "type": ["null", "string"], "default": null}
    ]
  }');

  // Marshal with only required field, others use defaults
  $data = dict[
    'required_field' => 'hello',
    'optional_field' => 'default_value',
    'nullable' => null,
  ];
  $encoded = Avro\Marshal($schema, $data);
  $decoded = Avro\Unmarshal($schema, $encoded);

  if (!($decoded is dict<_, _>)) {
    throw new Exception("expected dict");
  }
  aeq($decoded['required_field'], 'hello', 'required field');
  aeq($decoded['optional_field'], 'default_value', 'optional with default');
  aeq($decoded['nullable'], null, 'nullable default');

  // JSON decode with missing fields uses defaults
  $json_input = '{"required_field": "test"}';
  $decoded = Avro\UnmarshalJson($schema, $json_input);
  if (!($decoded is dict<_, _>)) {
    throw new Exception("expected dict from json");
  }
  aeq($decoded['required_field'], 'test', 'json required');
  aeq($decoded['optional_field'], 'default_value', 'json uses default');

  echo "PASSED\n";
}

function testFixedType(): void {
  echo "Testing fixed type: ";

  $schema = Avro\ParseSchema('{"type":"fixed","name":"MD5","size":16}');
  $md5 = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f";

  $encoded = Avro\Marshal($schema, $md5);
  // Fixed encoding is just the raw bytes, no length prefix
  aeq(\strlen($encoded), 16, 'fixed encoded length');
  aeq($encoded, $md5, 'fixed is raw bytes');

  $decoded = Avro\Unmarshal($schema, $encoded);
  aeq($decoded, $md5, 'fixed roundtrip');

  // JSON round-trip (base64)
  $json = Avro\MarshalJson($schema, $md5);
  $from_json = Avro\UnmarshalJson($schema, $json);
  aeq($from_json, $md5, 'fixed json roundtrip');

  echo "PASSED\n";
}

function testSchemaReuse(): void {
  echo "Testing schema reuse (named types): ";

  $schema_json = '{
    "type": "record",
    "name": "Container",
    "fields": [
      {"name": "item1", "type": {
        "type": "record", "name": "Item",
        "fields": [{"name": "value", "type": "int"}]
      }},
      {"name": "item2", "type": "Item"}
    ]
  }';

  $schema = Avro\ParseSchema($schema_json);

  $data = dict[
    'item1' => dict['value' => 10],
    'item2' => dict['value' => 20],
  ];

  $encoded = Avro\Marshal($schema, $data);
  $decoded = Avro\Unmarshal($schema, $encoded);

  if (!($decoded is dict<_, _>)) {
    throw new Exception("expected dict");
  }
  $item1 = $decoded['item1'];
  $item2 = $decoded['item2'];
  if (!($item1 is dict<_, _>) || !($item2 is dict<_, _>)) {
    throw new Exception("expected dict items");
  }
  aeq($item1['value'], 10, 'schema reuse item1');
  aeq($item2['value'], 20, 'schema reuse item2');

  echo "PASSED\n";
}
