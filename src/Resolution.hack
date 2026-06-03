namespace Avro\Resolution;

use type \Avro\{Schema, SchemaType, Field, Decoder, AvroException};

function Resolve(Schema $writer, Schema $reader): Schema {
  return _Private\ResolveSchemas($writer, $reader);
}

function UnmarshalWithSchema(Schema $writer_schema, Schema $reader_schema, string $data): mixed {
  Resolve($writer_schema, $reader_schema);
  $d = Decoder::FromString($data);
  return ReadResolved($writer_schema, $reader_schema, $d);
}

function ReadResolved(Schema $writer, Schema $reader, Decoder $d): mixed {
  if ($writer->type === $reader->type) {
    switch ($writer->type) {
      case SchemaType::NULL_TYPE: return null;
      case SchemaType::BOOLEAN: return $d->readBool();
      case SchemaType::INT: return $d->readInt();
      case SchemaType::LONG: return $d->readLong();
      case SchemaType::FLOAT: return $d->readFloat();
      case SchemaType::DOUBLE: return $d->readDouble();
      case SchemaType::BYTES: return $d->readBytes();
      case SchemaType::STRING: return $d->readString();
      case SchemaType::FIXED: return $d->readFixed($writer->size);
      case SchemaType::ENUM:
        $idx = $d->readInt();
        if ($idx < 0 || $idx >= \count($writer->symbols)) {
          throw new AvroException("enum index out of range: ".$idx);
        }
        $symbol = $writer->symbols[$idx];
        if (!\in_array($symbol, $reader->symbols)) {
          throw new AvroException("enum symbol not in reader: ".$symbol);
        }
        return $symbol;
      case SchemaType::ARRAY_TYPE:
        $w_items = $writer->items as nonnull;
        $r_items = $reader->items as nonnull;
        $items = vec[];
        $block_count = $d->readLong();
        while ($block_count !== 0) {
          if ($block_count < 0) { $d->readLong(); $block_count = -$block_count; }
          for ($i = 0; $i < $block_count; $i++) {
            $items[] = ReadResolved($w_items, $r_items, $d);
          }
          $block_count = $d->readLong();
        }
        return $items;
      case SchemaType::MAP_TYPE:
        $w_values = $writer->values as nonnull;
        $r_values = $reader->values as nonnull;
        $map = dict[];
        $block_count = $d->readLong();
        while ($block_count !== 0) {
          if ($block_count < 0) { $d->readLong(); $block_count = -$block_count; }
          for ($i = 0; $i < $block_count; $i++) {
            $key = $d->readString();
            $map[$key] = ReadResolved($w_values, $r_values, $d);
          }
          $block_count = $d->readLong();
        }
        return $map;
      case SchemaType::RECORD:
        return ReadResolvedRecord($writer, $reader, $d);
      case SchemaType::UNION:
        $idx = (int)$d->readLong();
        if ($idx < 0 || $idx >= \count($writer->union)) {
          throw new AvroException("union index out of range");
        }
        $w_branch = $writer->union[$idx];
        foreach ($reader->union as $r_branch) {
          try {
            _Private\ResolveSchemas($w_branch, $r_branch);
            return ReadResolved($w_branch, $r_branch, $d);
          } catch (AvroException $_) { continue; }
        }
        throw new AvroException("no matching reader branch for writer union index ".$idx);
    }
  }

  if ($writer->type === SchemaType::INT && $reader->type === SchemaType::LONG) return (int)$d->readInt();
  if ($writer->type === SchemaType::INT && $reader->type === SchemaType::FLOAT) return (float)$d->readInt();
  if ($writer->type === SchemaType::INT && $reader->type === SchemaType::DOUBLE) return (float)$d->readInt();
  if ($writer->type === SchemaType::LONG && $reader->type === SchemaType::FLOAT) return (float)$d->readLong();
  if ($writer->type === SchemaType::LONG && $reader->type === SchemaType::DOUBLE) return (float)$d->readLong();
  if ($writer->type === SchemaType::FLOAT && $reader->type === SchemaType::DOUBLE) return (float)$d->readFloat();
  if ($writer->type === SchemaType::STRING && $reader->type === SchemaType::BYTES) return $d->readString();
  if ($writer->type === SchemaType::BYTES && $reader->type === SchemaType::STRING) return $d->readBytes();

  if ($reader->type === SchemaType::UNION) {
    foreach ($reader->union as $r_branch) {
      try {
        _Private\ResolveSchemas($writer, $r_branch);
        return ReadResolved($writer, $r_branch, $d);
      } catch (AvroException $_) { continue; }
    }
  }

  throw new AvroException("cannot resolve reader/writer schemas during read");
}

function ReadResolvedRecord(Schema $writer, Schema $reader, Decoder $d): dict<string, mixed> {
  $reader_field_map = dict[];
  foreach ($reader->fields as $rf) {
    $reader_field_map[$rf->name] = $rf;
  }

  $writer_values = dict[];
  foreach ($writer->fields as $wf) {
    if (\array_key_exists($wf->name, $reader_field_map)) {
      $rf = $reader_field_map[$wf->name];
      $writer_values[$wf->name] = ReadResolved($wf->schema, $rf->schema, $d);
    } else {
      \Avro\_Private\ReadDatum($wf->schema, $d);
    }
  }

  $result = dict[];
  foreach ($reader->fields as $rf) {
    if (\array_key_exists($rf->name, $writer_values)) {
      $result[$rf->name] = $writer_values[$rf->name];
    } else if ($rf->has_default) {
      $result[$rf->name] = $rf->default_value;
    } else {
      throw new AvroException("reader field '".$rf->name."' not in writer and has no default");
    }
  }
  return $result;
}

namespace Avro\Resolution\_Private;

use type \Avro\{Schema, SchemaType, AvroException};

function ResolveSchemas(Schema $writer, Schema $reader): Schema {
  if ($writer->type === $reader->type) {
    switch ($writer->type) {
      case SchemaType::NULL_TYPE:
      case SchemaType::BOOLEAN:
      case SchemaType::INT:
      case SchemaType::LONG:
      case SchemaType::FLOAT:
      case SchemaType::DOUBLE:
      case SchemaType::BYTES:
      case SchemaType::STRING:
      case SchemaType::RECORD:
      case SchemaType::UNION:
        return $reader;
      case SchemaType::ENUM:
        if ($writer->name !== $reader->name) throw new AvroException("enum name mismatch");
        return $reader;
      case SchemaType::FIXED:
        if ($writer->name !== $reader->name) throw new AvroException("fixed name mismatch");
        if ($writer->size !== $reader->size) throw new AvroException("fixed size mismatch");
        return $reader;
      case SchemaType::ARRAY_TYPE:
        $wi = $writer->items; $ri = $reader->items;
        if ($wi !== null && $ri !== null) ResolveSchemas($wi, $ri);
        return $reader;
      case SchemaType::MAP_TYPE:
        $wv = $writer->values; $rv = $reader->values;
        if ($wv !== null && $rv !== null) ResolveSchemas($wv, $rv);
        return $reader;
    }
  }

  if ($writer->type === SchemaType::INT && $reader->type === SchemaType::LONG) return $reader;
  if ($writer->type === SchemaType::INT && $reader->type === SchemaType::FLOAT) return $reader;
  if ($writer->type === SchemaType::INT && $reader->type === SchemaType::DOUBLE) return $reader;
  if ($writer->type === SchemaType::LONG && $reader->type === SchemaType::FLOAT) return $reader;
  if ($writer->type === SchemaType::LONG && $reader->type === SchemaType::DOUBLE) return $reader;
  if ($writer->type === SchemaType::FLOAT && $reader->type === SchemaType::DOUBLE) return $reader;
  if ($writer->type === SchemaType::STRING && $reader->type === SchemaType::BYTES) return $reader;
  if ($writer->type === SchemaType::BYTES && $reader->type === SchemaType::STRING) return $reader;

  if ($writer->type === SchemaType::UNION && $reader->type !== SchemaType::UNION) {
    foreach ($writer->union as $branch) {
      try { return ResolveSchemas($branch, $reader); } catch (AvroException $_) { continue; }
    }
  }
  if ($reader->type === SchemaType::UNION) {
    foreach ($reader->union as $branch) {
      try { return ResolveSchemas($writer, $branch); } catch (AvroException $_) { continue; }
    }
  }

  throw new AvroException(
    "incompatible schemas: writer=".\Avro\_Private\SchemaTypeName($writer).
    " reader=".\Avro\_Private\SchemaTypeName($reader),
  );
}
