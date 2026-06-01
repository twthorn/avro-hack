<?hh // strict

<<__EntryPoint>>
function test_fingerprint_main(): void {
  require "lib/errors.php";
  require "lib/avro.php";
  require "lib/fingerprint.php";

  echo "=== Schema Fingerprint Tests ===\n\n";

  testCanonicalForm();
  testCanonicalStability();
  testMD5Fingerprint();
  testSHA256Fingerprint();
  testCRC64Fingerprint();
  testRecursiveSchema();
  testDifferentSchemasProduceDifferentFingerprints();

  echo "\n=== ALL FINGERPRINT TESTS PASSED ===\n";
}

function fp_aeq(mixed $got, mixed $exp, string $msg): void {
  if ($got !== $exp) {
    throw new Exception(
      $msg."; got: ".\print_r($got, true)." expected: ".\print_r($exp, true),
    );
  }
}

function testCanonicalForm(): void {
  echo "Testing canonical form: ";

  // Primitives
  $s = Avro\ParseSchema('"null"');
  fp_aeq(Avro\Fingerprint\Canonical($s), '"null"', 'null canonical');

  $s = Avro\ParseSchema('"int"');
  fp_aeq(Avro\Fingerprint\Canonical($s), '"int"', 'int canonical');

  $s = Avro\ParseSchema('"string"');
  fp_aeq(Avro\Fingerprint\Canonical($s), '"string"', 'string canonical');

  // Record - fields in canonical order: name, type, fields
  $s = Avro\ParseSchema('{
    "type": "record",
    "name": "Test",
    "namespace": "com.example",
    "doc": "A test record",
    "fields": [
      {"name": "a", "type": "int", "doc": "field a"},
      {"name": "b", "type": "string"}
    ]
  }');
  $canonical = Avro\Fingerprint\Canonical($s);
  // Canonical should strip doc, namespace is folded into name
  if (\strpos($canonical, 'doc') !== false) {
    throw new Exception("canonical should not contain 'doc'");
  }
  if (\strpos($canonical, '"name":"com.example.Test"') === false) {
    throw new Exception("canonical should contain full name, got: ".$canonical);
  }

  // Enum
  $s = Avro\ParseSchema('{"type":"enum","name":"Color","symbols":["R","G","B"]}');
  $canonical = Avro\Fingerprint\Canonical($s);
  fp_aeq($canonical, '{"name":"Color","type":"enum","symbols":["R","G","B"]}', 'enum canonical');

  // Array
  $s = Avro\ParseSchema('{"type":"array","items":"int"}');
  fp_aeq(Avro\Fingerprint\Canonical($s), '{"type":"array","items":"int"}', 'array canonical');

  // Map
  $s = Avro\ParseSchema('{"type":"map","values":"string"}');
  fp_aeq(Avro\Fingerprint\Canonical($s), '{"type":"map","values":"string"}', 'map canonical');

  // Union
  $s = Avro\ParseSchema('["null","string"]');
  fp_aeq(Avro\Fingerprint\Canonical($s), '["null","string"]', 'union canonical');

  // Fixed
  $s = Avro\ParseSchema('{"type":"fixed","name":"F16","size":16}');
  fp_aeq(Avro\Fingerprint\Canonical($s), '{"name":"F16","type":"fixed","size":16}', 'fixed canonical');

  echo "PASSED\n";
}

function testCanonicalStability(): void {
  echo "Testing canonical form stability: ";

  // Same schema expressed differently should produce same canonical
  $s1 = Avro\ParseSchema('{
    "type": "record",
    "name": "User",
    "fields": [
      {"name": "name", "type": "string"},
      {"name": "age", "type": "int"}
    ]
  }');

  // Same schema, different formatting/ordering of JSON keys
  $s2 = Avro\ParseSchema('{
    "fields": [
      {"type": "string", "name": "name"},
      {"type": "int", "name": "age"}
    ],
    "name": "User",
    "type": "record"
  }');

  fp_aeq(Avro\Fingerprint\Canonical($s1), Avro\Fingerprint\Canonical($s2), 'canonical stability');

  echo "PASSED\n";
}

function testMD5Fingerprint(): void {
  echo "Testing MD5 fingerprint: ";

  $s = Avro\ParseSchema('"int"');
  $md5 = Avro\Fingerprint\MD5($s);
  fp_aeq(\strlen($md5), 16, 'MD5 is 16 bytes');

  $hex = Avro\Fingerprint\MD5Hex($s);
  fp_aeq(\strlen($hex), 32, 'MD5 hex is 32 chars');

  // Same schema always produces same fingerprint
  $s2 = Avro\ParseSchema('"int"');
  fp_aeq(Avro\Fingerprint\MD5Hex($s2), $hex, 'MD5 deterministic');

  // Different schemas produce different fingerprints
  $s3 = Avro\ParseSchema('"string"');
  $hex3 = Avro\Fingerprint\MD5Hex($s3);
  if ($hex3 === $hex) {
    throw new Exception("different schemas should have different MD5");
  }

  echo "PASSED\n";
}

function testSHA256Fingerprint(): void {
  echo "Testing SHA-256 fingerprint: ";

  $s = Avro\ParseSchema('"long"');
  $sha = Avro\Fingerprint\SHA256($s);
  fp_aeq(\strlen($sha), 32, 'SHA-256 is 32 bytes');

  $hex = Avro\Fingerprint\SHA256Hex($s);
  fp_aeq(\strlen($hex), 64, 'SHA-256 hex is 64 chars');

  // Deterministic
  $s2 = Avro\ParseSchema('"long"');
  fp_aeq(Avro\Fingerprint\SHA256Hex($s2), $hex, 'SHA-256 deterministic');

  echo "PASSED\n";
}

function testCRC64Fingerprint(): void {
  echo "Testing CRC-64-AVRO fingerprint: ";

  $s = Avro\ParseSchema('"null"');
  $crc = Avro\Fingerprint\CRC64($s);
  fp_aeq(\strlen($crc), 8, 'CRC-64 is 8 bytes');

  $hex = Avro\Fingerprint\CRC64Hex($s);
  fp_aeq(\strlen($hex), 16, 'CRC-64 hex is 16 chars');

  // Deterministic
  $s2 = Avro\ParseSchema('"null"');
  fp_aeq(Avro\Fingerprint\CRC64Hex($s2), $hex, 'CRC-64 deterministic');

  // Different schemas differ
  $s3 = Avro\ParseSchema('"int"');
  if (Avro\Fingerprint\CRC64Hex($s3) === $hex) {
    throw new Exception("different schemas should have different CRC-64");
  }

  echo "PASSED\n";
}

function testRecursiveSchema(): void {
  echo "Testing fingerprint of recursive schema: ";

  $s = Avro\ParseSchema('{
    "type": "record",
    "name": "TreeNode",
    "fields": [
      {"name": "value", "type": "int"},
      {"name": "children", "type": {"type": "array", "items": "TreeNode"}}
    ]
  }');

  // Should not infinite loop
  $canonical = Avro\Fingerprint\Canonical($s);
  if (\strlen($canonical) === 0) {
    throw new Exception("canonical should not be empty");
  }

  // Should contain reference to TreeNode (not expand infinitely)
  $md5 = Avro\Fingerprint\MD5Hex($s);
  fp_aeq(\strlen($md5), 32, 'recursive schema MD5');

  echo "PASSED\n";
}

function testDifferentSchemasProduceDifferentFingerprints(): void {
  echo "Testing uniqueness of fingerprints: ";

  $schemas = vec[
    '"null"', '"boolean"', '"int"', '"long"', '"float"', '"double"',
    '"bytes"', '"string"',
    '{"type":"record","name":"A","fields":[{"name":"x","type":"int"}]}',
    '{"type":"record","name":"B","fields":[{"name":"x","type":"int"}]}',
    '{"type":"enum","name":"E","symbols":["A","B"]}',
    '{"type":"array","items":"int"}',
    '{"type":"map","values":"string"}',
    '["null","string"]',
    '["null","int"]',
  ];

  $fingerprints = dict[];
  foreach ($schemas as $json) {
    $s = Avro\ParseSchema($json);
    $fp = Avro\Fingerprint\MD5Hex($s);
    if (\array_key_exists($fp, $fingerprints)) {
      throw new Exception(
        "fingerprint collision: ".$json." vs ".$fingerprints[$fp],
      );
    }
    $fingerprints[$fp] = $json;
  }

  fp_aeq(\count($fingerprints), \count($schemas), 'all fingerprints unique');

  echo "PASSED\n";
}
