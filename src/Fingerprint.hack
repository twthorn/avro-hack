
namespace Avro\Fingerprint {
  use type \Avro\Schema;
  use type \Avro\SchemaType;

  // Canonical form per Avro spec: https://avro.apache.org/docs/current/specification/#parsing-canonical-form-for-schemas
  function Canonical(Schema $schema): string {
    return CanonicalForm($schema, dict[]);
  }

  function CRC64(Schema $schema): string {
    $canonical = Canonical($schema);
    return CRC64Avro($canonical);
  }

  function MD5(Schema $schema): string {
    $canonical = Canonical($schema);
    return \md5($canonical, true);
  }

  function SHA256(Schema $schema): string {
    $canonical = Canonical($schema);
    return \hash('sha256', $canonical, true);
  }

  function MD5Hex(Schema $schema): string {
    return \md5(Canonical($schema));
  }

  function SHA256Hex(Schema $schema): string {
    return \hash('sha256', Canonical($schema));
  }

  function CanonicalForm(Schema $schema, dict<string, bool> $seen): string {
    switch ($schema->type) {
      case SchemaType::NULL_TYPE: return '"null"';
      case SchemaType::BOOLEAN: return '"boolean"';
      case SchemaType::INT: return '"int"';
      case SchemaType::LONG: return '"long"';
      case SchemaType::FLOAT: return '"float"';
      case SchemaType::DOUBLE: return '"double"';
      case SchemaType::BYTES: return '"bytes"';
      case SchemaType::STRING: return '"string"';

      case SchemaType::RECORD:
        if (\array_key_exists($schema->name, $seen)) {
          return '"'.$schema->name.'"';
        }
        $seen[$schema->name] = true;
        $fields = vec[];
        foreach ($schema->fields as $f) {
          $fields[] = '{"name":"'.$f->name.'","type":'.\Avro\Fingerprint\CanonicalForm($f->schema, $seen).'}';
        }
        return '{"name":"'.$schema->name.'","type":"record","fields":['.
          \implode(',', $fields).']}';

      case SchemaType::ENUM:
        $symbols = vec[];
        foreach ($schema->symbols as $s) {
          $symbols[] = '"'.$s.'"';
        }
        return '{"name":"'.$schema->name.'","type":"enum","symbols":['.
          \implode(',', $symbols).']}';

      case SchemaType::ARRAY_TYPE:
        $items = $schema->items;
        $items_canonical = $items !== null ? CanonicalForm($items, $seen) : '"null"';
        return '{"type":"array","items":'.$items_canonical.'}';

      case SchemaType::MAP_TYPE:
        $values = $schema->values;
        $values_canonical = $values !== null ? CanonicalForm($values, $seen) : '"null"';
        return '{"type":"map","values":'.$values_canonical.'}';

      case SchemaType::UNION:
        $branches = vec[];
        foreach ($schema->union as $s) {
          $branches[] = CanonicalForm($s, $seen);
        }
        return '['.\implode(',', $branches).']';

      case SchemaType::FIXED:
        return '{"name":"'.$schema->name.'","type":"fixed","size":'.$schema->size.'}';
    }
  }

  function CRC64Poly(): int {
    // 0xc96c5795d7870f42 as signed int64
    return -3932672073523589054;
  }

  function CRC64Empty(): int {
    // 0xc15d213aa4d7a795 as signed int64
    return -4513414715797952619;
  }

  function CRC64Avro(string $data): string {
    $fp = CRC64Empty();
    for ($i = 0; $i < \strlen($data); $i++) {
      $b = \ord($data[$i]);
      for ($j = 0; $j < 8; $j++) {
        if ((($fp ^ $b) & 1) === 1) {
          $fp = (($fp >> 1) & 0x7FFFFFFFFFFFFFFF) ^ CRC64Poly();
        } else {
          $fp = ($fp >> 1) & 0x7FFFFFFFFFFFFFFF;
        }
        $b >>= 1;
      }
    }
    return \pack('J', $fp);
  }

  function CRC64Hex(Schema $schema): string {
    return \bin2hex(CRC64($schema));
  }
}
