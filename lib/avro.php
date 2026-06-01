<?hh // strict

namespace Avro {
  use type \Errors\Error;
  use function \Errors\Ok;

  function Marshal(Schema $schema, mixed $datum): string {
    $e = new Internal\Encoder();
    Internal\WriteDatum($schema, $datum, $e);
    return $e->buffer();
  }

  function Unmarshal(Schema $schema, string $data): mixed {
    $d = Internal\Decoder::FromString($data);
    return Internal\ReadDatum($schema, $d);
  }

  function MarshalJson(Schema $schema, mixed $datum): string {
    $encoded = Internal\WriteDatumJson($schema, $datum);
    return \json_encode($encoded, \JSON_PRETTY_PRINT | \JSON_PARTIAL_OUTPUT_ON_ERROR);
  }

  function UnmarshalJson(Schema $schema, string $json): mixed {
    $data = \json_decode($json, true, 512, \JSON_FB_HACK_ARRAYS);
    if ($data === null && $json !== 'null') {
      throw new \AvroException("json_decode failed");
    }
    return Internal\ReadDatumJson($schema, $data);
  }

  function ParseSchema(string $json): Schema {
    $data = \json_decode($json, true, 512, \JSON_FB_HACK_ARRAYS);
    if ($data === null && $json !== 'null') {
      throw new \AvroException("schema json_decode failed");
    }
    $registry = new Internal\NameRegistry();
    return Internal\ParseSchemaData($data, $registry);
  }
}

namespace Avro\Internal {
  use type \Avro\Schema;
  use type \Avro\SchemaType;

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
          throw new \AvroException("unknown type: ".$data);
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
                $fields[] = new \Avro\Field($fname, $fschema, $has_default, $default);
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

    throw new \AvroException("invalid schema data");
  }

  function WriteDatum(Schema $schema, mixed $datum, Encoder $e): void {
    switch ($schema->type) {
      case SchemaType::NULL_TYPE:
        break;
      case SchemaType::BOOLEAN:
        $e->writeBool((bool)$datum);
        break;
      case SchemaType::INT:
        $e->writeInt((int)$datum);
        break;
      case SchemaType::LONG:
        $e->writeLong((int)$datum);
        break;
      case SchemaType::FLOAT:
        $e->writeFloat((float)$datum);
        break;
      case SchemaType::DOUBLE:
        $e->writeDouble((float)$datum);
        break;
      case SchemaType::BYTES:
        $e->writeBytes((string)$datum);
        break;
      case SchemaType::STRING:
        $e->writeString((string)$datum);
        break;
      case SchemaType::RECORD:
        $fields = $schema->fields;
        if ($datum is dict<_, _>) {
          foreach ($fields as $field) {
            $val = $datum[$field->name] ?? $field->default_value;
            WriteDatum($field->schema, $val, $e);
          }
        }
        break;
      case SchemaType::ENUM:
        $symbols = $schema->symbols;
        $idx = \array_search((string)$datum, $symbols);
        if ($idx === false) {
          throw new \AvroException("unknown enum symbol: ".(string)$datum);
        }
        $e->writeInt((int)$idx);
        break;
      case SchemaType::ARRAY_TYPE:
        $items_schema = $schema->items as nonnull;
        if ($datum is vec<_>) {
          if (\count($datum) > 0) {
            $e->writeLong(\count($datum));
            foreach ($datum as $item) {
              WriteDatum($items_schema, $item, $e);
            }
          }
          $e->writeLong(0);
        }
        break;
      case SchemaType::MAP_TYPE:
        $values_schema = $schema->values as nonnull;
        if ($datum is dict<_, _>) {
          if (\count($datum) > 0) {
            $e->writeLong(\count($datum));
            foreach ($datum as $k => $v) {
              $e->writeString((string)$k);
              WriteDatum($values_schema, $v, $e);
            }
          }
          $e->writeLong(0);
        }
        break;
      case SchemaType::UNION:
        $union_schemas = $schema->union;
        WriteUnion($union_schemas, $datum, $e);
        break;
      case SchemaType::FIXED:
        $e->writeFixed((string)$datum, $schema->size);
        break;
    }
  }

  function WriteUnion(vec<Schema> $schemas, mixed $datum, Encoder $e): void {
    for ($i = 0; $i < \count($schemas); $i++) {
      $s = $schemas[$i];
      if (DatumMatchesSchema($s, $datum)) {
        $e->writeLong($i);
        WriteDatum($s, $datum, $e);
        return;
      }
    }
    throw new \AvroException("datum does not match any union branch");
  }

  function DatumMatchesSchema(Schema $schema, mixed $datum): bool {
    switch ($schema->type) {
      case SchemaType::NULL_TYPE:
        return $datum === null;
      case SchemaType::BOOLEAN:
        return $datum is bool;
      case SchemaType::INT:
      case SchemaType::LONG:
        return $datum is int;
      case SchemaType::FLOAT:
      case SchemaType::DOUBLE:
        return $datum is float || $datum is int;
      case SchemaType::BYTES:
      case SchemaType::STRING:
        return $datum is string;
      case SchemaType::RECORD:
        return $datum is dict<_, _>;
      case SchemaType::ENUM:
        if (!($datum is string)) return false;
        return \in_array($datum, $schema->symbols);
      case SchemaType::ARRAY_TYPE:
        return $datum is vec<_>;
      case SchemaType::MAP_TYPE:
        return $datum is dict<_, _>;
      case SchemaType::UNION:
        return false;
      case SchemaType::FIXED:
        return $datum is string && \strlen($datum) === $schema->size;
    }
  }

  function ReadDatum(Schema $schema, Decoder $d): mixed {
    switch ($schema->type) {
      case SchemaType::NULL_TYPE:
        return null;
      case SchemaType::BOOLEAN:
        return $d->readBool();
      case SchemaType::INT:
        return $d->readInt();
      case SchemaType::LONG:
        return $d->readLong();
      case SchemaType::FLOAT:
        return $d->readFloat();
      case SchemaType::DOUBLE:
        return $d->readDouble();
      case SchemaType::BYTES:
        return $d->readBytes();
      case SchemaType::STRING:
        return $d->readString();
      case SchemaType::RECORD:
        $record = dict[];
        foreach ($schema->fields as $field) {
          $record[$field->name] = ReadDatum($field->schema, $d);
        }
        return $record;
      case SchemaType::ENUM:
        $idx = $d->readInt();
        $symbols = $schema->symbols;
        if ($idx < 0 || $idx >= \count($symbols)) {
          throw new \AvroException("enum index out of range: ".$idx);
        }
        return $symbols[$idx];
      case SchemaType::ARRAY_TYPE:
        $items_schema = $schema->items as nonnull;
        $items = vec[];
        $block_count = $d->readLong();
        while ($block_count !== 0) {
          if ($block_count < 0) {
            $d->readLong(); // skip block size
            $block_count = -$block_count;
          }
          for ($i = 0; $i < $block_count; $i++) {
            $items[] = ReadDatum($items_schema, $d);
          }
          $block_count = $d->readLong();
        }
        return $items;
      case SchemaType::MAP_TYPE:
        $values_schema = $schema->values as nonnull;
        $map = dict[];
        $block_count = $d->readLong();
        while ($block_count !== 0) {
          if ($block_count < 0) {
            $d->readLong(); // skip block size
            $block_count = -$block_count;
          }
          for ($i = 0; $i < $block_count; $i++) {
            $key = $d->readString();
            $map[$key] = ReadDatum($values_schema, $d);
          }
          $block_count = $d->readLong();
        }
        return $map;
      case SchemaType::UNION:
        $idx = (int)$d->readLong();
        $union_schemas = $schema->union;
        if ($idx < 0 || $idx >= \count($union_schemas)) {
          throw new \AvroException("union index out of range: ".$idx);
        }
        return ReadDatum($union_schemas[$idx], $d);
      case SchemaType::FIXED:
        return $d->readFixed($schema->size);
    }
  }

  function WriteDatumJson(Schema $schema, mixed $datum): mixed {
    switch ($schema->type) {
      case SchemaType::NULL_TYPE:
        return null;
      case SchemaType::BOOLEAN:
        return (bool)$datum;
      case SchemaType::INT:
      case SchemaType::LONG:
        return (int)$datum;
      case SchemaType::FLOAT:
      case SchemaType::DOUBLE:
        return (float)$datum;
      case SchemaType::BYTES:
        return \base64_encode((string)$datum);
      case SchemaType::STRING:
        return (string)$datum;
      case SchemaType::RECORD:
        $obj = dict[];
        if ($datum is dict<_, _>) {
          foreach ($schema->fields as $field) {
            $val = $datum[$field->name] ?? $field->default_value;
            $obj[$field->name] = WriteDatumJson($field->schema, $val);
          }
        }
        return $obj;
      case SchemaType::ENUM:
        return (string)$datum;
      case SchemaType::ARRAY_TYPE:
        $items_schema = $schema->items as nonnull;
        $arr = vec[];
        if ($datum is vec<_>) {
          foreach ($datum as $item) {
            $arr[] = WriteDatumJson($items_schema, $item);
          }
        }
        return $arr;
      case SchemaType::MAP_TYPE:
        $values_schema = $schema->values as nonnull;
        $map = dict[];
        if ($datum is dict<_, _>) {
          foreach ($datum as $k => $v) {
            $map[(string)$k] = WriteDatumJson($values_schema, $v);
          }
        }
        return $map;
      case SchemaType::UNION:
        if ($datum === null) {
          return null;
        }
        $union_schemas = $schema->union;
        for ($i = 0; $i < \count($union_schemas); $i++) {
          $s = $union_schemas[$i];
          if (DatumMatchesSchema($s, $datum)) {
            if ($s->type === SchemaType::NULL_TYPE) {
              return null;
            }
            $type_name = SchemaTypeName($s);
            return dict[$type_name => WriteDatumJson($s, $datum)];
          }
        }
        throw new \AvroException("datum does not match any union branch for JSON encoding");
      case SchemaType::FIXED:
        return \base64_encode((string)$datum);
    }
  }

  function ReadDatumJson(Schema $schema, mixed $data): mixed {
    switch ($schema->type) {
      case SchemaType::NULL_TYPE:
        return null;
      case SchemaType::BOOLEAN:
        return (bool)$data;
      case SchemaType::INT:
      case SchemaType::LONG:
        return (int)$data;
      case SchemaType::FLOAT:
      case SchemaType::DOUBLE:
        return (float)$data;
      case SchemaType::BYTES:
        return \base64_decode((string)$data);
      case SchemaType::STRING:
        return (string)$data;
      case SchemaType::RECORD:
        $record = dict[];
        if ($data is dict<_, _>) {
          foreach ($schema->fields as $field) {
            if (\array_key_exists($field->name, $data)) {
              $record[$field->name] = ReadDatumJson($field->schema, $data[$field->name]);
            } else if ($field->has_default) {
              $record[$field->name] = $field->default_value;
            } else {
              $record[$field->name] = null;
            }
          }
        }
        return $record;
      case SchemaType::ENUM:
        return (string)$data;
      case SchemaType::ARRAY_TYPE:
        $items_schema = $schema->items as nonnull;
        $arr = vec[];
        if ($data is vec<_>) {
          foreach ($data as $item) {
            $arr[] = ReadDatumJson($items_schema, $item);
          }
        }
        return $arr;
      case SchemaType::MAP_TYPE:
        $values_schema = $schema->values as nonnull;
        $map = dict[];
        if ($data is dict<_, _>) {
          foreach ($data as $k => $v) {
            $map[(string)$k] = ReadDatumJson($values_schema, $v);
          }
        }
        return $map;
      case SchemaType::UNION:
        if ($data === null) {
          return null;
        }
        $union_schemas = $schema->union;
        if ($data is dict<_, _>) {
          foreach ($data as $type_name => $val) {
            foreach ($union_schemas as $s) {
              if (SchemaTypeName($s) === (string)$type_name) {
                return ReadDatumJson($s, $val);
              }
            }
          }
        }
        // Try primitives directly
        foreach ($union_schemas as $s) {
          if (DatumMatchesSchema($s, $data)) {
            return ReadDatumJson($s, $data);
          }
        }
        throw new \AvroException("could not resolve union branch for JSON decoding");
      case SchemaType::FIXED:
        return \base64_decode((string)$data);
    }
  }

  function SchemaTypeName(Schema $s): string {
    switch ($s->type) {
      case SchemaType::NULL_TYPE: return 'null';
      case SchemaType::BOOLEAN: return 'boolean';
      case SchemaType::INT: return 'int';
      case SchemaType::LONG: return 'long';
      case SchemaType::FLOAT: return 'float';
      case SchemaType::DOUBLE: return 'double';
      case SchemaType::BYTES: return 'bytes';
      case SchemaType::STRING: return 'string';
      case SchemaType::RECORD: return $s->name;
      case SchemaType::ENUM: return $s->name;
      case SchemaType::ARRAY_TYPE: return 'array';
      case SchemaType::MAP_TYPE: return 'map';
      case SchemaType::UNION: return 'union';
      case SchemaType::FIXED: return $s->name;
    }
  }

  class Encoder {
    private string $buf;
    public function __construct() {
      $this->buf = "";
    }

    public function writeBool(bool $b): void {
      $this->buf .= $b ? "\x01" : "\x00";
    }

    public function writeInt(int $n): void {
      $this->writeLong($n);
    }

    public function writeLong(int $n): void {
      // Zigzag encode
      $n = ($n << 1) ^ ($n >> 63);
      // Varint encode
      while (($n & ~0x7F) !== 0) {
        $this->buf .= \chr(($n & 0x7F) | 0x80);
        $n = ($n >> 7) & 0x1FFFFFFFFFFFFFF;
      }
      $this->buf .= \chr($n & 0x7F);
    }

    public function writeFloat(float $f): void {
      $this->buf .= \pack('f', $f);
    }

    public function writeDouble(float $d): void {
      $this->buf .= \pack('d', $d);
    }

    public function writeBytes(string $s): void {
      $this->writeLong(\strlen($s));
      $this->buf .= $s;
    }

    public function writeString(string $s): void {
      $this->writeBytes($s);
    }

    public function writeFixed(string $s, int $size): void {
      if (\strlen($s) !== $size) {
        throw new \AvroException(
          "fixed size mismatch: expected ".$size." got ".\strlen($s),
        );
      }
      $this->buf .= $s;
    }

    public function writeRaw(string $s): void {
      $this->buf .= $s;
    }

    public function buffer(): string {
      return $this->buf;
    }
  }

  class Decoder {
    private function __construct(
      private string $buf,
      private int $offset,
      private int $len,
    ) {}

    public static function FromString(string $buf): Decoder {
      return new Decoder($buf, 0, \strlen($buf));
    }

    public function readBool(): bool {
      if ($this->offset >= $this->len) {
        throw new \AvroException("buffer overrun reading bool");
      }
      $b = \ord($this->buf[$this->offset]) !== 0;
      $this->offset++;
      return $b;
    }

    public function readInt(): int {
      return (int)$this->readLong();
    }

    public function readLong(): int {
      $val = 0;
      $shift = 0;
      while (true) {
        if ($this->offset >= $this->len) {
          throw new \AvroException("buffer overrun reading varint");
        }
        $b = \ord($this->buf[$this->offset]);
        $this->offset++;
        $val |= (($b & 0x7F) << $shift);
        if (($b & 0x80) === 0) {
          break;
        }
        $shift += 7;
      }
      // Zigzag decode
      return (($val >> 1) & 0x7FFFFFFFFFFFFFFF) ^ (-($val & 1));
    }

    public function readFloat(): float {
      if ($this->offset + 4 > $this->len) {
        throw new \AvroException("buffer overrun reading float");
      }
      $f = \unpack('f', \substr($this->buf, $this->offset, 4))[1];
      $this->offset += 4;
      return $f;
    }

    public function readDouble(): float {
      if ($this->offset + 8 > $this->len) {
        throw new \AvroException("buffer overrun reading double");
      }
      $d = \unpack('d', \substr($this->buf, $this->offset, 8))[1];
      $this->offset += 8;
      return $d;
    }

    public function readBytes(): string {
      $len = (int)$this->readLong();
      if ($len < 0) {
        throw new \AvroException("negative bytes length: ".$len);
      }
      if ($len === 0) {
        return '';
      }
      if ($this->offset + $len > $this->len) {
        throw new \AvroException("buffer overrun reading bytes");
      }
      $s = \substr($this->buf, $this->offset, $len);
      $this->offset += $len;
      return $s;
    }

    public function readString(): string {
      return $this->readBytes();
    }

    public function readFixed(int $size): string {
      if ($this->offset + $size > $this->len) {
        throw new \AvroException("buffer overrun reading fixed");
      }
      $s = \substr($this->buf, $this->offset, $size);
      $this->offset += $size;
      return $s;
    }

    public function isEOF(): bool {
      return $this->offset >= $this->len;
    }

    public function getOffset(): int {
      return $this->offset;
    }
  }
}

namespace Avro {
  enum SchemaType: int {
    NULL_TYPE = 0;
    BOOLEAN = 1;
    INT = 2;
    LONG = 3;
    FLOAT = 4;
    DOUBLE = 5;
    BYTES = 6;
    STRING = 7;
    RECORD = 8;
    ENUM = 9;
    ARRAY_TYPE = 10;
    MAP_TYPE = 11;
    UNION = 12;
    FIXED = 13;
  }

  type schema_opts_t = shape(
    ?'name' => string,
    ?'fields' => vec<Field>,
    ?'symbols' => vec<string>,
    ?'items' => Schema,
    ?'values' => Schema,
    ?'union' => vec<Schema>,
    ?'size' => int,
  );

  class Schema {
    public SchemaType $type;
    public string $name;
    public vec<Field> $fields;
    public vec<string> $symbols;
    public ?Schema $items;
    public ?Schema $values;
    public vec<Schema> $union;
    public int $size;

    public function __construct(SchemaType $type, schema_opts_t $opts) {
      $this->type = $type;
      $this->name = $opts['name'] ?? '';
      $this->fields = $opts['fields'] ?? vec[];
      $this->symbols = $opts['symbols'] ?? vec[];
      $this->items = $opts['items'] ?? null;
      $this->values = $opts['values'] ?? null;
      $this->union = $opts['union'] ?? vec[];
      $this->size = $opts['size'] ?? 0;
    }
  }

  class Field {
    public function __construct(
      public string $name,
      public Schema $schema,
      public bool $has_default,
      public mixed $default_value,
    ) {}
  }
}

namespace {
  class AvroException extends \Exception {}
}
