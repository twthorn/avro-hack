<?hh // strict

<<__EntryPoint>>
function test_container_main(): void {
  require "lib/errors.php";
  require "lib/avro.php";
  require "lib/container.php";

  echo "=== Container File Tests ===\n\n";

  testWriteReadRoundTrip();
  testMultipleRecords();
  testDeflateCodec();
  testEmptyFile();
  testSchemaEmbedding();
  testInvalidMagic();
  testComplexSchema();

  echo "\n=== ALL CONTAINER TESTS PASSED ===\n";
}

function cnt_aeq(mixed $got, mixed $exp, string $msg): void {
  if ($got !== $exp) {
    throw new Exception(
      $msg."; got: ".\print_r($got, true)." expected: ".\print_r($exp, true),
    );
  }
}

function cnt_a(mixed $got, mixed $exp, string $msg): void {
  if ($got != $exp) {
    throw new Exception(
      $msg."; got: ".\print_r($got, true)." expected: ".\print_r($exp, true),
    );
  }
}

function testWriteReadRoundTrip(): void {
  echo "Testing container write/read round-trip: ";

  $schema = Avro\ParseSchema('{
    "type": "record", "name": "Simple",
    "fields": [
      {"name": "id", "type": "int"},
      {"name": "name", "type": "string"}
    ]
  }');

  $records = vec[
    dict['id' => 1, 'name' => 'Alice'],
    dict['id' => 2, 'name' => 'Bob'],
    dict['id' => 3, 'name' => 'Charlie'],
  ];

  $file_bytes = Avro\Container\WriteFile($schema, $records);

  // Verify magic bytes
  cnt_aeq(\substr($file_bytes, 0, 4), "Obj\x01", 'magic bytes');

  // Read back
  $reader = Avro\Container\ReadFile($file_bytes);
  cnt_aeq($reader->getCodec(), 'null', 'codec is null');

  $read_records = $reader->readAll();
  cnt_aeq(\count($read_records), 3, 'record count');

  $r0 = $read_records[0];
  if (!($r0 is dict<_, _>)) throw new Exception("expected dict");
  cnt_aeq($r0['id'], 1, 'record 0 id');
  cnt_aeq($r0['name'], 'Alice', 'record 0 name');

  $r2 = $read_records[2];
  if (!($r2 is dict<_, _>)) throw new Exception("expected dict");
  cnt_aeq($r2['id'], 3, 'record 2 id');
  cnt_aeq($r2['name'], 'Charlie', 'record 2 name');

  echo "PASSED\n";
}

function testMultipleRecords(): void {
  echo "Testing container with many records: ";

  $schema = Avro\ParseSchema('"int"');

  $records = vec[];
  for ($i = 0; $i < 100; $i++) {
    $records[] = $i;
  }

  $file_bytes = Avro\Container\WriteFile($schema, $records);
  $reader = Avro\Container\ReadFile($file_bytes);
  $read_records = $reader->readAll();

  cnt_aeq(\count($read_records), 100, 'should have 100 records');
  cnt_aeq($read_records[0], 0, 'first record');
  cnt_aeq($read_records[99], 99, 'last record');

  echo "PASSED\n";
}

function testDeflateCodec(): void {
  echo "Testing deflate codec: ";

  $schema = Avro\ParseSchema('{
    "type": "record", "name": "Data",
    "fields": [
      {"name": "payload", "type": "string"}
    ]
  }');

  $records = vec[];
  for ($i = 0; $i < 50; $i++) {
    $records[] = dict['payload' => \str_repeat('A', 100)];
  }

  $file_deflate = Avro\Container\WriteFile($schema, $records, Avro\Container\CODEC_DEFLATE);
  $file_null = Avro\Container\WriteFile($schema, $records, Avro\Container\CODEC_NULL);

  // Deflate should be smaller for repetitive data
  if (\strlen($file_deflate) >= \strlen($file_null)) {
    throw new Exception(
      "deflate should be smaller: deflate=".\strlen($file_deflate).
      " null=".\strlen($file_null),
    );
  }

  // Read back and verify
  $reader = Avro\Container\ReadFile($file_deflate);
  cnt_aeq($reader->getCodec(), 'deflate', 'codec should be deflate');

  $read_records = $reader->readAll();
  cnt_aeq(\count($read_records), 50, 'record count');

  $r0 = $read_records[0];
  if (!($r0 is dict<_, _>)) throw new Exception("expected dict");
  cnt_aeq($r0['payload'], \str_repeat('A', 100), 'payload preserved');

  echo "PASSED\n";
}

function testEmptyFile(): void {
  echo "Testing empty container file: ";

  $schema = Avro\ParseSchema('"string"');
  $file_bytes = Avro\Container\WriteFile($schema, vec[]);

  $reader = Avro\Container\ReadFile($file_bytes);
  $read_records = $reader->readAll();
  cnt_aeq(\count($read_records), 0, 'empty file has no records');

  echo "PASSED\n";
}

function testSchemaEmbedding(): void {
  echo "Testing schema is embedded in file: ";

  $schema = Avro\ParseSchema('{
    "type": "record", "name": "Event",
    "fields": [
      {"name": "type", "type": "string"},
      {"name": "ts", "type": "long"}
    ]
  }');

  $file_bytes = Avro\Container\WriteFile($schema, vec[dict['type' => 'click', 'ts' => 1000]]);

  // Read and verify schema was correctly embedded
  $reader = Avro\Container\ReadFile($file_bytes);
  $read_schema = $reader->getSchema();
  cnt_aeq($read_schema->type, \Avro\SchemaType::RECORD, 'schema type');
  cnt_aeq($read_schema->name, 'Event', 'schema name');
  cnt_aeq(\count($read_schema->fields), 2, 'schema field count');

  echo "PASSED\n";
}

function testInvalidMagic(): void {
  echo "Testing invalid magic bytes: ";

  try {
    Avro\Container\ReadFile("NOPE");
    throw new Exception("expected exception");
  } catch (\AvroException $e) {
    if (\strpos($e->getMessage(), "magic") === false) {
      throw new Exception("wrong error: ".$e->getMessage());
    }
  }

  try {
    Avro\Container\ReadFile("ab");
    throw new Exception("expected exception for short file");
  } catch (\AvroException $e) {
    // expected
  }

  echo "PASSED\n";
}

function testComplexSchema(): void {
  echo "Testing container with complex schema: ";

  $schema = Avro\ParseSchema('{
    "type": "record", "name": "Order",
    "fields": [
      {"name": "id", "type": "long"},
      {"name": "items", "type": {"type": "array", "items": "string"}},
      {"name": "metadata", "type": {"type": "map", "values": "int"}},
      {"name": "status", "type": {"type": "enum", "name": "Status", "symbols": ["PENDING", "SHIPPED", "DELIVERED"]}},
      {"name": "notes", "type": ["null", "string"]}
    ]
  }');

  $records = vec[
    dict[
      'id' => 1001,
      'items' => vec['widget', 'gadget'],
      'metadata' => dict['priority' => 1, 'weight' => 500],
      'status' => 'PENDING',
      'notes' => null,
    ],
    dict[
      'id' => 1002,
      'items' => vec['tool'],
      'metadata' => dict[],
      'status' => 'SHIPPED',
      'notes' => 'fragile',
    ],
  ];

  $file_bytes = Avro\Container\WriteFile($schema, $records);
  $reader = Avro\Container\ReadFile($file_bytes);
  $read_records = $reader->readAll();

  cnt_aeq(\count($read_records), 2, 'complex record count');

  $r0 = $read_records[0];
  if (!($r0 is dict<_, _>)) throw new Exception("expected dict");
  cnt_aeq($r0['id'], 1001, 'order id');
  cnt_aeq($r0['status'], 'PENDING', 'order status');
  cnt_aeq($r0['notes'], null, 'order notes null');
  cnt_a($r0['items'], vec['widget', 'gadget'], 'order items');

  $r1 = $read_records[1];
  if (!($r1 is dict<_, _>)) throw new Exception("expected dict");
  cnt_aeq($r1['notes'], 'fragile', 'order 2 notes');

  echo "PASSED\n";
}
