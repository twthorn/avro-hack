# avro-hack

Apache Avro serialization library for Hack (Hacklang) / HHVM.

## Usage

```hack
$schema = Avro\ParseSchema(file_get_contents("user.avsc"));

$bytes = Avro\Marshal($schema, dict["name" => "Alice", "age" => 30]);
$decoded = Avro\Unmarshal($schema, $bytes);
```

## Tests

```bash
./build.sh test
```

Runs `hh_client` typechecker + tests via Docker (HHVM 4.153).

## Structure

```
src/            Avro.hack, Schema.hack, Encoder.hack, Decoder.hack, Resolution.hack, Container.hack, Logical.hack, Fingerprint.hack
src/_Private/   internal implementation (Datum, Json, SchemaParser)
tests/          test suite
examples/       .avsc schema files
```
