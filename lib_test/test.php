<?hh // strict
namespace Avro\Internal;

function a(mixed $got, mixed $exp, string $msg): void {
  if ($got !== $exp) {
    $m = \sprintf(
      "%s got:'%s' expected:'%s'",
      $msg,
      \print_r($got, true),
      \print_r($exp, true),
    );
    throw new \Exception($m);
  }
}

function testLongEncoding(int $dec, string $enc): void {
  $e = new Encoder();
  $e->writeLong($dec);
  a($e->buffer(), $enc, "write long ".$dec);
  $d = Decoder::FromString($enc);
  a($d->readLong(), $dec, "read long ".$dec);
}

function testIntEncoding(int $dec, string $enc): void {
  $e = new Encoder();
  $e->writeInt($dec);
  a($e->buffer(), $enc, "write int ".$dec);
  $d = Decoder::FromString($enc);
  a($d->readInt(), $dec, "read int ".$dec);
}

function cat(int ...$is): string {
  $v = '';
  foreach ($is as $i) {
    $v .= \chr($i);
  }
  return $v;
}

<<__EntryPoint>>
function main(): void {
  require "lib/avro.php";

  echo "=== Avro-Hack Library Tests ===\n\n";

  echo "Testing zigzag+varint encoding: ";
  // Avro uses zigzag encoding: 0->0, -1->1, 1->2, -2->3, 2->4, ...
  testLongEncoding(0, cat(0x00));
  testLongEncoding(-1, cat(0x01));
  testLongEncoding(1, cat(0x02));
  testLongEncoding(-2, cat(0x03));
  testLongEncoding(2, cat(0x04));
  testLongEncoding(-64, cat(0x7F));
  testLongEncoding(64, cat(0x80, 0x01));
  testLongEncoding(-65, cat(0x81, 0x01));
  testLongEncoding(100, cat(0xC8, 0x01));
  testLongEncoding(1000, cat(0xD0, 0x0F));
  echo "PASSED\n";

  echo "Testing bool encoding: ";
  $e = new Encoder();
  $e->writeBool(false);
  a($e->buffer(), "\x00", "write bool false");
  $e = new Encoder();
  $e->writeBool(true);
  a($e->buffer(), "\x01", "write bool true");
  $d = Decoder::FromString("\x00");
  a($d->readBool(), false, "read bool false");
  $d = Decoder::FromString("\x01");
  a($d->readBool(), true, "read bool true");
  echo "PASSED\n";

  echo "Testing float encoding: ";
  $e = new Encoder();
  $e->writeFloat(0.0);
  a(\strlen($e->buffer()), 4, "float is 4 bytes");
  $d = Decoder::FromString($e->buffer());
  a($d->readFloat(), 0.0, "read float 0.0");

  $e = new Encoder();
  $e->writeFloat(1.0);
  $d = Decoder::FromString($e->buffer());
  $f = $d->readFloat();
  if (\abs($f - 1.0) > 0.0001) {
    throw new \Exception("float 1.0 roundtrip failed: ".$f);
  }
  echo "PASSED\n";

  echo "Testing double encoding: ";
  $e = new Encoder();
  $e->writeDouble(3.141592653589793);
  a(\strlen($e->buffer()), 8, "double is 8 bytes");
  $d = Decoder::FromString($e->buffer());
  a($d->readDouble(), 3.141592653589793, "read double pi");
  echo "PASSED\n";

  echo "Testing string/bytes encoding: ";
  $e = new Encoder();
  $e->writeString("hello");
  $d = Decoder::FromString($e->buffer());
  a($d->readString(), "hello", "read string hello");

  $e = new Encoder();
  $e->writeString("");
  $d = Decoder::FromString($e->buffer());
  a($d->readString(), "", "read empty string");

  $e = new Encoder();
  $e->writeBytes("\x00\x01\xFF");
  $d = Decoder::FromString($e->buffer());
  a($d->readBytes(), "\x00\x01\xFF", "read bytes");
  echo "PASSED\n";

  echo "Testing fixed encoding: ";
  $e = new Encoder();
  $e->writeFixed("abcd", 4);
  a($e->buffer(), "abcd", "write fixed 4");
  $d = Decoder::FromString("abcd");
  a($d->readFixed(4), "abcd", "read fixed 4");
  echo "PASSED\n";

  echo "Testing EOF detection: ";
  $d = Decoder::FromString("");
  a($d->isEOF(), true, "empty buffer is EOF");
  $d = Decoder::FromString("\x00");
  a($d->isEOF(), false, "non-empty buffer is not EOF");
  $d->readBool();
  a($d->isEOF(), true, "after reading all bytes is EOF");
  echo "PASSED\n";

  echo "Testing schema parsing: ";
  $s = ParseSchemaData("null", new NameRegistry());
  a($s->type, \Avro\SchemaType::NULL_TYPE, "parse null");
  $s = ParseSchemaData("boolean", new NameRegistry());
  a($s->type, \Avro\SchemaType::BOOLEAN, "parse boolean");
  $s = ParseSchemaData("int", new NameRegistry());
  a($s->type, \Avro\SchemaType::INT, "parse int");
  $s = ParseSchemaData("long", new NameRegistry());
  a($s->type, \Avro\SchemaType::LONG, "parse long");
  $s = ParseSchemaData("float", new NameRegistry());
  a($s->type, \Avro\SchemaType::FLOAT, "parse float");
  $s = ParseSchemaData("double", new NameRegistry());
  a($s->type, \Avro\SchemaType::DOUBLE, "parse double");
  $s = ParseSchemaData("bytes", new NameRegistry());
  a($s->type, \Avro\SchemaType::BYTES, "parse bytes");
  $s = ParseSchemaData("string", new NameRegistry());
  a($s->type, \Avro\SchemaType::STRING, "parse string");
  echo "PASSED\n";

  echo "Testing record schema parsing: ";
  $record_data = dict[
    'type' => 'record',
    'name' => 'Test',
    'namespace' => 'com.example',
    'fields' => vec[
      dict['name' => 'a', 'type' => 'int'],
      dict['name' => 'b', 'type' => 'string', 'default' => 'hi'],
    ],
  ];
  $s = ParseSchemaData($record_data, new NameRegistry());
  a($s->type, \Avro\SchemaType::RECORD, "parse record type");
  a($s->name, "com.example.Test", "parse record name");
  a(\count($s->fields), 2, "parse record fields count");
  a($s->fields[0]->name, "a", "field 0 name");
  a($s->fields[0]->schema->type, \Avro\SchemaType::INT, "field 0 type");
  a($s->fields[1]->name, "b", "field 1 name");
  a($s->fields[1]->has_default, true, "field 1 has default");
  a($s->fields[1]->default_value, "hi", "field 1 default value");
  echo "PASSED\n";

  echo "Testing enum schema parsing: ";
  $enum_data = dict[
    'type' => 'enum',
    'name' => 'Color',
    'symbols' => vec['RED', 'GREEN', 'BLUE'],
  ];
  $s = ParseSchemaData($enum_data, new NameRegistry());
  a($s->type, \Avro\SchemaType::ENUM, "parse enum type");
  a($s->name, "Color", "parse enum name");
  a(\count($s->symbols), 3, "parse enum symbols count");
  a($s->symbols[0], "RED", "enum symbol 0");
  a($s->symbols[2], "BLUE", "enum symbol 2");
  echo "PASSED\n";

  echo "Testing DatumMatchesSchema: ";
  $null_schema = new \Avro\Schema(\Avro\SchemaType::NULL_TYPE, shape());
  $bool_schema = new \Avro\Schema(\Avro\SchemaType::BOOLEAN, shape());
  $int_schema = new \Avro\Schema(\Avro\SchemaType::INT, shape());
  $string_schema = new \Avro\Schema(\Avro\SchemaType::STRING, shape());

  a(DatumMatchesSchema($null_schema, null), true, "null matches null");
  a(DatumMatchesSchema($null_schema, "hi"), false, "string does not match null");
  a(DatumMatchesSchema($bool_schema, true), true, "true matches bool");
  a(DatumMatchesSchema($bool_schema, 1), false, "int does not match bool");
  a(DatumMatchesSchema($int_schema, 42), true, "42 matches int");
  a(DatumMatchesSchema($int_schema, "42"), false, "string does not match int");
  a(DatumMatchesSchema($string_schema, "hello"), true, "string matches string");
  a(DatumMatchesSchema($string_schema, 42), false, "int does not match string");
  echo "PASSED\n";

  echo "\n=== ALL LIBRARY TESTS PASSED ===\n";
}
