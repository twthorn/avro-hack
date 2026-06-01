# avro-hack

Apache Avro serialization library for Hack (HHVM).

## Usage

```hack
$schema = Avro\ParseSchema(file_get_contents("schemas/user.avsc"));

$bytes = Avro\Marshal($schema, dict["name" => "Alice", "age" => 30]);
$decoded = Avro\Unmarshal($schema, $bytes);
```

## Tests

```bash
./test.sh
```

Runs `hh_client` typechecker + all test suites via Docker (HHVM 4.153).

## Structure

```
lib/        runtime (avro, resolution, container, logical types, fingerprint)
lib_test/   unit tests
test/       integration tests
schemas/    example .avsc files
```
