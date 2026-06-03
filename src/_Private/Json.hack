namespace Avro\_Private;

use type \Avro\{Schema, SchemaType, AvroException};

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
      foreach ($schema->union as $s) {
        if (DatumMatchesSchema($s, $datum)) {
          if ($s->type === SchemaType::NULL_TYPE) {
            return null;
          }
          return dict[SchemaTypeName($s) => WriteDatumJson($s, $datum)];
        }
      }
      throw new AvroException("datum does not match any union branch for JSON encoding");
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
      if ($data is dict<_, _>) {
        foreach ($data as $type_name => $val) {
          foreach ($schema->union as $s) {
            if (SchemaTypeName($s) === (string)$type_name) {
              return ReadDatumJson($s, $val);
            }
          }
        }
      }
      foreach ($schema->union as $s) {
        if (DatumMatchesSchema($s, $data)) {
          return ReadDatumJson($s, $data);
        }
      }
      throw new AvroException("could not resolve union branch for JSON decoding");
    case SchemaType::FIXED:
      return \base64_decode((string)$data);
  }
}
