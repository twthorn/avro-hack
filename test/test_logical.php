<?hh // strict

<<__EntryPoint>>
function test_logical_main(): void {
  require "lib/errors.php";
  require "lib/avro.php";
  require "lib/logical.php";

  echo "=== Logical Types Tests ===\n\n";

  testDate();
  testTimeMillis();
  testTimeMicros();
  testTimestampMillis();
  testTimestampMicros();
  testUUID();
  testDecimal();
  testDecimalEdgeCases();
  testLogicalTypesWithAvroSchema();

  echo "\n=== ALL LOGICAL TYPE TESTS PASSED ===\n";
}

function log_aeq(mixed $got, mixed $exp, string $msg): void {
  if ($got !== $exp) {
    throw new Exception(
      $msg."; got: ".\print_r($got, true)." expected: ".\print_r($exp, true),
    );
  }
}

function testDate(): void {
  echo "Testing date logical type: ";

  // 2024-01-15 is 19737 days since epoch (1970-01-01)
  $days = Avro\Logical\DateFromTimestamp(1705276800); // 2024-01-15 00:00:00 UTC
  log_aeq($days, 19737, 'date from timestamp');

  $ts = Avro\Logical\DateToTimestamp(19737);
  log_aeq($ts, 19737 * 86400, 'date to timestamp');

  // Round-trip
  $d = Avro\Logical\DateFromEpochDays(0);
  log_aeq($d, 0, 'epoch is day 0');

  $d = Avro\Logical\DateFromEpochDays(365);
  log_aeq($d, 365, 'day 365');

  // Store date as Avro int
  $schema = Avro\ParseSchema('"int"');
  $encoded = Avro\Marshal($schema, $days);
  $decoded = Avro\Unmarshal($schema, $encoded);
  log_aeq($decoded, 19737, 'date avro roundtrip');

  echo "PASSED\n";
}

function testTimeMillis(): void {
  echo "Testing time-millis logical type: ";

  // 14:30:05.123
  $t = Avro\Logical\TimeMillis(14, 30, 5, 123);
  $expected = (14 * 3600000) + (30 * 60000) + (5 * 1000) + 123;
  log_aeq($t, $expected, 'time millis encoding');

  $components = Avro\Logical\TimeMillisToComponents($t);
  log_aeq($components['hours'], 14, 'hours');
  log_aeq($components['minutes'], 30, 'minutes');
  log_aeq($components['seconds'], 5, 'seconds');
  log_aeq($components['millis'], 123, 'millis');

  // Midnight
  $midnight = Avro\Logical\TimeMillis(0, 0, 0);
  log_aeq($midnight, 0, 'midnight is 0');

  // End of day
  $end = Avro\Logical\TimeMillis(23, 59, 59, 999);
  $comp = Avro\Logical\TimeMillisToComponents($end);
  log_aeq($comp['hours'], 23, 'end hours');
  log_aeq($comp['millis'], 999, 'end millis');

  echo "PASSED\n";
}

function testTimeMicros(): void {
  echo "Testing time-micros logical type: ";

  $t = Avro\Logical\TimeMicros(10, 15, 30, 500000);
  $components = Avro\Logical\TimeMicrosToComponents($t);
  log_aeq($components['hours'], 10, 'hours');
  log_aeq($components['minutes'], 15, 'minutes');
  log_aeq($components['seconds'], 30, 'seconds');
  log_aeq($components['micros'], 500000, 'micros');

  // Store as Avro long
  $schema = Avro\ParseSchema('"long"');
  $encoded = Avro\Marshal($schema, $t);
  $decoded = Avro\Unmarshal($schema, $encoded);
  log_aeq($decoded, $t, 'time micros avro roundtrip');

  echo "PASSED\n";
}

function testTimestampMillis(): void {
  echo "Testing timestamp-millis logical type: ";

  $ts = Avro\Logical\TimestampMillisFromUnix(1700000000);
  log_aeq($ts, 1700000000000, 'timestamp millis');

  $unix = Avro\Logical\TimestampMillisToUnix($ts);
  log_aeq($unix, 1700000000, 'back to unix');

  // Store as Avro long
  $schema = Avro\ParseSchema('"long"');
  $encoded = Avro\Marshal($schema, $ts);
  $decoded = Avro\Unmarshal($schema, $encoded);
  log_aeq($decoded, 1700000000000, 'timestamp millis avro roundtrip');

  echo "PASSED\n";
}

function testTimestampMicros(): void {
  echo "Testing timestamp-micros logical type: ";

  $ts = Avro\Logical\TimestampMicrosFromUnix(1700000000);
  log_aeq($ts, 1700000000000000, 'timestamp micros');

  $unix = Avro\Logical\TimestampMicrosToUnix($ts);
  log_aeq($unix, 1700000000, 'back to unix');

  echo "PASSED\n";
}

function testUUID(): void {
  echo "Testing UUID logical type: ";

  // Valid UUIDs
  log_aeq(Avro\Logical\ValidateUUID('550e8400-e29b-41d4-a716-446655440000'), true, 'valid uuid');
  log_aeq(Avro\Logical\ValidateUUID('00000000-0000-0000-0000-000000000000'), true, 'nil uuid');
  log_aeq(Avro\Logical\ValidateUUID('FFFFFFFF-FFFF-FFFF-FFFF-FFFFFFFFFFFF'), true, 'max uuid');

  // Invalid UUIDs
  log_aeq(Avro\Logical\ValidateUUID('not-a-uuid'), false, 'invalid uuid');
  log_aeq(Avro\Logical\ValidateUUID(''), false, 'empty uuid');
  log_aeq(Avro\Logical\ValidateUUID('550e8400-e29b-41d4-a716'), false, 'truncated uuid');

  // Generate and validate
  $uuid = Avro\Logical\GenerateUUID();
  log_aeq(Avro\Logical\ValidateUUID($uuid), true, 'generated uuid is valid');
  log_aeq(\strlen($uuid), 36, 'uuid length');

  // Version 4 check
  log_aeq($uuid[14], '4', 'uuid version 4');

  // Store as Avro string
  $schema = Avro\ParseSchema('"string"');
  $encoded = Avro\Marshal($schema, $uuid);
  $decoded = Avro\Unmarshal($schema, $encoded);
  log_aeq($decoded, $uuid, 'uuid avro roundtrip');

  echo "PASSED\n";
}

function testDecimal(): void {
  echo "Testing decimal logical type: ";

  // Simple positive decimal: 123.45 with scale=2, precision=5
  $d = Avro\Logical\Decimal::fromFloat(123.45, 2, 5);
  log_aeq($d->unscaled, 12345, 'decimal unscaled');
  log_aeq($d->scale, 2, 'decimal scale');

  $f = $d->toFloat();
  if (\abs($f - 123.45) > 0.001) {
    throw new Exception("decimal toFloat failed: ".$f);
  }

  // Bytes round-trip
  $bytes = $d->toBytes();
  $d2 = Avro\Logical\Decimal::fromBytes($bytes, 2, 5);
  log_aeq($d2->unscaled, 12345, 'decimal bytes roundtrip');

  // Negative decimal
  $neg = Avro\Logical\Decimal::fromFloat(-99.99, 2, 4);
  log_aeq($neg->unscaled, -9999, 'negative unscaled');
  $neg_bytes = $neg->toBytes();
  $neg2 = Avro\Logical\Decimal::fromBytes($neg_bytes, 2, 4);
  log_aeq($neg2->unscaled, -9999, 'negative bytes roundtrip');

  // Zero
  $zero = Avro\Logical\Decimal::fromFloat(0.0, 2, 5);
  log_aeq($zero->unscaled, 0, 'zero unscaled');
  $zero_bytes = $zero->toBytes();
  $zero2 = Avro\Logical\Decimal::fromBytes($zero_bytes, 2, 5);
  log_aeq($zero2->unscaled, 0, 'zero bytes roundtrip');

  // Store decimal bytes in Avro
  $schema = Avro\ParseSchema('"bytes"');
  $encoded = Avro\Marshal($schema, $bytes);
  $decoded = Avro\Unmarshal($schema, $encoded);
  $d3 = Avro\Logical\Decimal::fromBytes((string)$decoded, 2, 5);
  log_aeq($d3->unscaled, 12345, 'decimal avro bytes roundtrip');

  echo "PASSED\n";
}

function testDecimalEdgeCases(): void {
  echo "Testing decimal edge cases: ";

  // Large decimal
  $large = new Avro\Logical\Decimal(999999999, 4, 13);
  $bytes = $large->toBytes();
  $large2 = Avro\Logical\Decimal::fromBytes($bytes, 4, 13);
  log_aeq($large2->unscaled, 999999999, 'large decimal');

  // Small negative
  $small_neg = new Avro\Logical\Decimal(-1, 2, 3);
  $bytes = $small_neg->toBytes();
  $small_neg2 = Avro\Logical\Decimal::fromBytes($bytes, 2, 3);
  log_aeq($small_neg2->unscaled, -1, 'small negative decimal');

  // toString
  $d = new Avro\Logical\Decimal(12345, 2, 5);
  log_aeq($d->__toString(), '123.45', 'decimal toString');

  $d_neg = new Avro\Logical\Decimal(-12345, 2, 5);
  log_aeq($d_neg->__toString(), '-123.45', 'negative decimal toString');

  $d_zero_scale = new Avro\Logical\Decimal(42, 0, 2);
  log_aeq($d_zero_scale->__toString(), '42', 'zero scale toString');

  echo "PASSED\n";
}

function testLogicalTypesWithAvroSchema(): void {
  echo "Testing logical types in Avro record: ";

  // A record that uses logical types
  $schema = Avro\ParseSchema('{
    "type": "record", "name": "Event",
    "fields": [
      {"name": "event_date", "type": "int"},
      {"name": "event_time_ms", "type": "int"},
      {"name": "event_ts_micros", "type": "long"},
      {"name": "event_id", "type": "string"},
      {"name": "amount_bytes", "type": "bytes"}
    ]
  }');

  $date = Avro\Logical\DateFromTimestamp(1700000000);
  $time = Avro\Logical\TimeMillis(14, 30, 0);
  $ts = Avro\Logical\TimestampMicrosFromUnix(1700000000);
  $uuid = Avro\Logical\GenerateUUID();
  $amount = Avro\Logical\Decimal::fromFloat(199.99, 2, 5);

  $event = dict[
    'event_date' => $date,
    'event_time_ms' => $time,
    'event_ts_micros' => $ts,
    'event_id' => $uuid,
    'amount_bytes' => $amount->toBytes(),
  ];

  $encoded = Avro\Marshal($schema, $event);
  $decoded = Avro\Unmarshal($schema, $encoded);

  if (!($decoded is dict<_, _>)) throw new Exception("expected dict");

  log_aeq($decoded['event_date'], $date, 'event date');
  log_aeq($decoded['event_time_ms'], $time, 'event time');
  log_aeq($decoded['event_ts_micros'], $ts, 'event ts');
  log_aeq($decoded['event_id'], $uuid, 'event id');

  $decoded_amount = Avro\Logical\Decimal::fromBytes((string)$decoded['amount_bytes'], 2, 5);
  log_aeq($decoded_amount->unscaled, 19999, 'event amount');

  echo "PASSED\n";
}
