namespace Avro;

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

class AvroException extends \Exception {}
