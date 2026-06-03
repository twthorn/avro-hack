namespace Avro\_Private;

use type \Avro\{Schema, SchemaType, Field, Encoder, Decoder, AvroException};

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
      if ($datum is dict<_, _>) {
        foreach ($schema->fields as $field) {
          $val = $datum[$field->name] ?? $field->default_value;
          WriteDatum($field->schema, $val, $e);
        }
      }
      break;
    case SchemaType::ENUM:
      $idx = \array_search((string)$datum, $schema->symbols);
      if ($idx === false) {
        throw new AvroException("unknown enum symbol: ".(string)$datum);
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
      WriteUnion($schema->union, $datum, $e);
      break;
    case SchemaType::FIXED:
      $e->writeFixed((string)$datum, $schema->size);
      break;
  }
}

function WriteUnion(vec<Schema> $schemas, mixed $datum, Encoder $e): void {
  for ($i = 0; $i < \count($schemas); $i++) {
    if (DatumMatchesSchema($schemas[$i], $datum)) {
      $e->writeLong($i);
      WriteDatum($schemas[$i], $datum, $e);
      return;
    }
  }
  throw new AvroException("datum does not match any union branch");
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
      if ($idx < 0 || $idx >= \count($schema->symbols)) {
        throw new AvroException("enum index out of range: ".$idx);
      }
      return $schema->symbols[$idx];
    case SchemaType::ARRAY_TYPE:
      $items_schema = $schema->items as nonnull;
      $items = vec[];
      $block_count = $d->readLong();
      while ($block_count !== 0) {
        if ($block_count < 0) {
          $d->readLong();
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
          $d->readLong();
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
      if ($idx < 0 || $idx >= \count($schema->union)) {
        throw new AvroException("union index out of range: ".$idx);
      }
      return ReadDatum($schema->union[$idx], $d);
    case SchemaType::FIXED:
      return $d->readFixed($schema->size);
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
