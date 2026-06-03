namespace Avro\_Private;

use type \Avro\{Schema, SchemaType, Field, AvroException};

class NameRegistry {
  public dict<string, Schema> $names = dict[];
  public function add(string $name, Schema $schema): void {
    $this->names[$name] = $schema;
  }
  public function has(string $name): bool {
    return \array_key_exists($name, $this->names);
  }
  public function get(string $name): Schema {
    return $this->names[$name];
  }
}

function ParseSchemaData(mixed $data, NameRegistry $named): Schema {
  if ($data is string) {
    switch ($data) {
      case 'null':
        return new Schema(SchemaType::NULL_TYPE, shape());
      case 'boolean':
        return new Schema(SchemaType::BOOLEAN, shape());
      case 'int':
        return new Schema(SchemaType::INT, shape());
      case 'long':
        return new Schema(SchemaType::LONG, shape());
      case 'float':
        return new Schema(SchemaType::FLOAT, shape());
      case 'double':
        return new Schema(SchemaType::DOUBLE, shape());
      case 'bytes':
        return new Schema(SchemaType::BYTES, shape());
      case 'string':
        return new Schema(SchemaType::STRING, shape());
      default:
        if ($named->has($data)) {
          return $named->get($data);
        }
        throw new AvroException("unknown type: ".$data);
    }
  }

  if ($data is vec<_>) {
    $schemas = vec[];
    foreach ($data as $s) {
      $schemas[] = ParseSchemaData($s, $named);
    }
    return new Schema(SchemaType::UNION, shape('union' => $schemas));
  }

  if ($data is dict<_, _>) {
    $type = (string)($data['type'] ?? '');
    switch ($type) {
      case 'record':
        $name = (string)($data['name'] ?? '');
        $ns = (string)($data['namespace'] ?? '');
        $fullname = $ns !== '' ? $ns.'.'.$name : $name;
        $schema = new Schema(SchemaType::RECORD, shape(
          'name' => $fullname,
          'fields' => vec[],
        ));
        $named->add($fullname, $schema);
        $fields = vec[];
        $raw_fields = $data['fields'] ?? vec[];
        if ($raw_fields is vec<_>) {
          foreach ($raw_fields as $f) {
            if ($f is dict<_, _>) {
              $fname = (string)($f['name'] ?? '');
              $fschema = ParseSchemaData($f['type'] ?? 'null', $named);
              $has_default = \array_key_exists('default', $f);
              $default = $has_default ? $f['default'] : null;
              $fields[] = new Field($fname, $fschema, $has_default, $default);
            }
          }
        }
        $schema->fields = $fields;
        return $schema;

      case 'enum':
        $name = (string)($data['name'] ?? '');
        $ns = (string)($data['namespace'] ?? '');
        $fullname = $ns !== '' ? $ns.'.'.$name : $name;
        $symbols = vec[];
        $raw_symbols = $data['symbols'] ?? vec[];
        if ($raw_symbols is vec<_>) {
          foreach ($raw_symbols as $s) {
            $symbols[] = (string)$s;
          }
        }
        $schema = new Schema(SchemaType::ENUM, shape(
          'name' => $fullname,
          'symbols' => $symbols,
        ));
        $named->add($fullname, $schema);
        return $schema;

      case 'array':
        $items = ParseSchemaData($data['items'] ?? 'null', $named);
        return new Schema(SchemaType::ARRAY_TYPE, shape('items' => $items));

      case 'map':
        $values = ParseSchemaData($data['values'] ?? 'null', $named);
        return new Schema(SchemaType::MAP_TYPE, shape('values' => $values));

      case 'fixed':
        $name = (string)($data['name'] ?? '');
        $ns = (string)($data['namespace'] ?? '');
        $fullname = $ns !== '' ? $ns.'.'.$name : $name;
        $size = (int)($data['size'] ?? 0);
        $schema = new Schema(SchemaType::FIXED, shape(
          'name' => $fullname,
          'size' => $size,
        ));
        $named->add($fullname, $schema);
        return $schema;

      default:
        return ParseSchemaData($type, $named);
    }
  }

  throw new AvroException("invalid schema data");
}
