<?hh // strict

namespace Avro\Resolution {
  use type \Avro\Schema;
  use type \Avro\SchemaType;
  use type \Avro\Field;

  function Resolve(Schema $writer, Schema $reader): Schema {
    return Internal\ResolveSchemas($writer, $reader);
  }

  function UnmarshalWithSchema(
    Schema $writer_schema,
    Schema $reader_schema,
    string $data,
  ): mixed {
    $resolved = Resolve($writer_schema, $reader_schema);
    $d = \Avro\Internal\Decoder::FromString($data);
    return ReadResolved($writer_schema, $reader_schema, $d);
  }
}

namespace Avro\Resolution\Internal {
  use type \Avro\Schema;
  use type \Avro\SchemaType;
  use type \Avro\Field;

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
          return $reader;

        case SchemaType::RECORD:
          return $reader;

        case SchemaType::ENUM:
          if ($writer->name !== $reader->name) {
            throw new \AvroException(
              "enum name mismatch: writer=".$writer->name." reader=".$reader->name,
            );
          }
          return $reader;

        case SchemaType::FIXED:
          if ($writer->name !== $reader->name) {
            throw new \AvroException(
              "fixed name mismatch: writer=".$writer->name." reader=".$reader->name,
            );
          }
          if ($writer->size !== $reader->size) {
            throw new \AvroException(
              "fixed size mismatch: writer=".$writer->size." reader=".$reader->size,
            );
          }
          return $reader;

        case SchemaType::ARRAY_TYPE:
          $w_items = $writer->items;
          $r_items = $reader->items;
          if ($w_items !== null && $r_items !== null) {
            ResolveSchemas($w_items, $r_items);
          }
          return $reader;

        case SchemaType::MAP_TYPE:
          $w_values = $writer->values;
          $r_values = $reader->values;
          if ($w_values !== null && $r_values !== null) {
            ResolveSchemas($w_values, $r_values);
          }
          return $reader;

        case SchemaType::UNION:
          return $reader;
      }
    }

    if ($writer->type === SchemaType::INT && $reader->type === SchemaType::LONG) {
      return $reader;
    }
    if ($writer->type === SchemaType::INT && $reader->type === SchemaType::FLOAT) {
      return $reader;
    }
    if ($writer->type === SchemaType::INT && $reader->type === SchemaType::DOUBLE) {
      return $reader;
    }
    if ($writer->type === SchemaType::LONG && $reader->type === SchemaType::FLOAT) {
      return $reader;
    }
    if ($writer->type === SchemaType::LONG && $reader->type === SchemaType::DOUBLE) {
      return $reader;
    }
    if ($writer->type === SchemaType::FLOAT && $reader->type === SchemaType::DOUBLE) {
      return $reader;
    }
    if ($writer->type === SchemaType::STRING && $reader->type === SchemaType::BYTES) {
      return $reader;
    }
    if ($writer->type === SchemaType::BYTES && $reader->type === SchemaType::STRING) {
      return $reader;
    }

    if ($writer->type === SchemaType::UNION && $reader->type !== SchemaType::UNION) {
      foreach ($writer->union as $branch) {
        try {
          return ResolveSchemas($branch, $reader);
        } catch (\AvroException $_) {
          continue;
        }
      }
    }

    if ($reader->type === SchemaType::UNION) {
      foreach ($reader->union as $branch) {
        try {
          return ResolveSchemas($writer, $branch);
        } catch (\AvroException $_) {
          continue;
        }
      }
    }

    throw new \AvroException(
      "incompatible schemas: writer=".\Avro\Internal\SchemaTypeName($writer).
      " reader=".\Avro\Internal\SchemaTypeName($reader),
    );
  }
}

namespace Avro\Resolution {
  use type \Avro\Schema;
  use type \Avro\SchemaType;
  use type \Avro\Field;

  function ReadResolved(
    Schema $writer,
    Schema $reader,
    \Avro\Internal\Decoder $d,
  ): mixed {
    if ($writer->type === $reader->type) {
      switch ($writer->type) {
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
        case SchemaType::FIXED:
          return $d->readFixed($writer->size);
        case SchemaType::ENUM:
          $idx = $d->readInt();
          $w_symbols = $writer->symbols;
          if ($idx < 0 || $idx >= \count($w_symbols)) {
            throw new \AvroException("enum index out of range: ".$idx);
          }
          $symbol = $w_symbols[$idx];
          if (!\in_array($symbol, $reader->symbols)) {
            throw new \AvroException("enum symbol not in reader: ".$symbol);
          }
          return $symbol;
        case SchemaType::ARRAY_TYPE:
          $w_items = $writer->items as nonnull;
          $r_items = $reader->items as nonnull;
          $items = vec[];
          $block_count = $d->readLong();
          while ($block_count !== 0) {
            if ($block_count < 0) {
              $d->readLong();
              $block_count = -$block_count;
            }
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
            if ($block_count < 0) {
              $d->readLong();
              $block_count = -$block_count;
            }
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
          $w_branches = $writer->union;
          if ($idx < 0 || $idx >= \count($w_branches)) {
            throw new \AvroException("union index out of range");
          }
          $w_branch = $w_branches[$idx];
          foreach ($reader->union as $r_branch) {
            try {
              Internal\ResolveSchemas($w_branch, $r_branch);
              return ReadResolved($w_branch, $r_branch, $d);
            } catch (\AvroException $_) {
              continue;
            }
          }
          throw new \AvroException("no matching reader branch for writer union index ".$idx);
      }
    }

    if ($writer->type === SchemaType::INT && $reader->type === SchemaType::LONG) {
      return (int)$d->readInt();
    }
    if ($writer->type === SchemaType::INT && $reader->type === SchemaType::FLOAT) {
      return (float)$d->readInt();
    }
    if ($writer->type === SchemaType::INT && $reader->type === SchemaType::DOUBLE) {
      return (float)$d->readInt();
    }
    if ($writer->type === SchemaType::LONG && $reader->type === SchemaType::FLOAT) {
      return (float)$d->readLong();
    }
    if ($writer->type === SchemaType::LONG && $reader->type === SchemaType::DOUBLE) {
      return (float)$d->readLong();
    }
    if ($writer->type === SchemaType::FLOAT && $reader->type === SchemaType::DOUBLE) {
      return (float)$d->readFloat();
    }
    if ($writer->type === SchemaType::STRING && $reader->type === SchemaType::BYTES) {
      return $d->readString();
    }
    if ($writer->type === SchemaType::BYTES && $reader->type === SchemaType::STRING) {
      return $d->readBytes();
    }

    if ($reader->type === SchemaType::UNION) {
      foreach ($reader->union as $r_branch) {
        try {
          Internal\ResolveSchemas($writer, $r_branch);
          return ReadResolved($writer, $r_branch, $d);
        } catch (\AvroException $_) {
          continue;
        }
      }
    }

    throw new \AvroException("cannot resolve reader/writer schemas during read");
  }

  function ReadResolvedRecord(
    Schema $writer,
    Schema $reader,
    \Avro\Internal\Decoder $d,
  ): dict<string, mixed> {
    $writer_field_map = dict[];
    foreach ($writer->fields as $f) {
      $writer_field_map[$f->name] = $f;
    }

    $writer_values = dict[];
    $reader_field_map = dict[];
    foreach ($reader->fields as $rf) {
      $reader_field_map[$rf->name] = $rf;
    }

    foreach ($writer->fields as $wf) {
      if (\array_key_exists($wf->name, $reader_field_map)) {
        $rf = $reader_field_map[$wf->name];
        $writer_values[$wf->name] = ReadResolved($wf->schema, $rf->schema, $d);
      } else {
        \Avro\Internal\ReadDatum($wf->schema, $d);
      }
    }

    $result = dict[];
    foreach ($reader->fields as $rf) {
      if (\array_key_exists($rf->name, $writer_values)) {
        $result[$rf->name] = $writer_values[$rf->name];
      } else if ($rf->has_default) {
        $result[$rf->name] = $rf->default_value;
      } else {
        throw new \AvroException(
          "reader field '".$rf->name."' not in writer and has no default",
        );
      }
    }
    return $result;
  }

  function PromoteValue(mixed $value, Schema $from, Schema $to): mixed {
    if ($from->type === SchemaType::INT && $to->type === SchemaType::LONG) {
      return (int)$value;
    }
    if ($from->type === SchemaType::INT && $to->type === SchemaType::FLOAT) {
      return (float)$value;
    }
    if ($from->type === SchemaType::INT && $to->type === SchemaType::DOUBLE) {
      return (float)$value;
    }
    if ($from->type === SchemaType::LONG && $to->type === SchemaType::FLOAT) {
      return (float)$value;
    }
    if ($from->type === SchemaType::LONG && $to->type === SchemaType::DOUBLE) {
      return (float)$value;
    }
    if ($from->type === SchemaType::FLOAT && $to->type === SchemaType::DOUBLE) {
      return (float)$value;
    }
    if ($from->type === SchemaType::STRING && $to->type === SchemaType::BYTES) {
      return (string)$value;
    }
    if ($from->type === SchemaType::BYTES && $to->type === SchemaType::STRING) {
      return (string)$value;
    }
    return $value;
  }
}
