<?hh // strict

namespace Avro\Container {
  use type \Avro\Schema;

  const string MAGIC = "Obj\x01";
  const int SYNC_SIZE = 16;
  const string CODEC_NULL = 'null';
  const string CODEC_DEFLATE = 'deflate';

  function WriteFile(Schema $schema, vec<mixed> $records, string $codec = CODEC_NULL): string {
    $schema_json = SchemaToJson($schema);
    $sync = GenerateSync();
    $buf = '';

    // Header: magic
    $buf .= MAGIC;

    // Header: metadata as Avro map (hand-encode to avoid circular dependency)
    $meta = dict[
      'avro.schema' => $schema_json,
      'avro.codec' => $codec,
    ];
    $meta_enc = new \Avro\Internal\Encoder();
    $meta_enc->writeLong(\count($meta));
    foreach ($meta as $k => $v) {
      $meta_enc->writeString($k);
      $meta_enc->writeBytes($v);
    }
    $meta_enc->writeLong(0);
    $buf .= $meta_enc->buffer();

    // Header: sync marker
    $buf .= $sync;

    // Data blocks
    if (\count($records) > 0) {
      $block_encoder = new \Avro\Internal\Encoder();
      foreach ($records as $record) {
        \Avro\Internal\WriteDatum($schema, $record, $block_encoder);
      }
      $block_data = $block_encoder->buffer();

      if ($codec === CODEC_DEFLATE) {
        $compressed = \gzdeflate($block_data);
        if (!($compressed is string)) {
          throw new \AvroException("deflate compression failed");
        }
        $block_data = $compressed;
      }

      $block_header = new \Avro\Internal\Encoder();
      $block_header->writeLong(\count($records));
      $block_header->writeBytes($block_data);
      $buf .= $block_header->buffer();
      $buf .= $sync;
    }

    return $buf;
  }

  function ReadFile(string $data): ContainerReader {
    return new ContainerReader($data);
  }

  class ContainerReader {
    private string $sync;
    private Schema $schema;
    private string $codec;
    private int $data_offset;
    private string $data;

    public function __construct(string $data) {
      $this->data = $data;

      if (\strlen($data) < 4) {
        throw new \AvroException("container file too short");
      }

      // Read magic
      $magic = \substr($data, 0, 4);
      if ($magic !== MAGIC) {
        throw new \AvroException("invalid Avro container file magic");
      }

      // Read metadata using decoder starting after magic
      $d = \Avro\Internal\Decoder::FromString(\substr($data, 4));
      $meta = dict[];
      $block_count = $d->readLong();
      while ($block_count !== 0) {
        if ($block_count < 0) {
          $d->readLong();
          $block_count = -$block_count;
        }
        for ($i = 0; $i < $block_count; $i++) {
          $key = $d->readString();
          $value = $d->readBytes();
          $meta[$key] = $value;
        }
        $block_count = $d->readLong();
      }

      $meta_end = 4 + $d->getOffset();

      // Parse schema from metadata
      $schema_json = $meta['avro.schema'] ?? '';
      if ($schema_json === '') {
        throw new \AvroException("container file missing avro.schema metadata");
      }
      $this->schema = \Avro\ParseSchema($schema_json);
      $this->codec = $meta['avro.codec'] ?? CODEC_NULL;

      // Read sync marker
      $this->sync = \substr($data, $meta_end, SYNC_SIZE);
      $this->data_offset = $meta_end + SYNC_SIZE;
    }

    public function getSchema(): Schema {
      return $this->schema;
    }

    public function getCodec(): string {
      return $this->codec;
    }

    public function readAll(): vec<mixed> {
      $records = vec[];
      $pos = $this->data_offset;
      $data = $this->data;
      $data_len = \strlen($data);

      while ($pos < $data_len) {
        $block_d = \Avro\Internal\Decoder::FromString(\substr($data, $pos));

        $object_count = $block_d->readLong();
        $block_bytes = $block_d->readBytes();
        $pos += $block_d->getOffset();

        // Verify sync
        if ($pos + SYNC_SIZE > $data_len) {
          throw new \AvroException("unexpected end of file before sync marker");
        }
        $file_sync = \substr($data, $pos, SYNC_SIZE);
        if ($file_sync !== $this->sync) {
          throw new \AvroException("sync marker mismatch");
        }
        $pos += SYNC_SIZE;

        // Decompress if needed
        if ($this->codec === CODEC_DEFLATE) {
          $decompressed = \gzinflate($block_bytes);
          if (!($decompressed is string)) {
            throw new \AvroException("deflate decompression failed");
          }
          $block_bytes = $decompressed;
        }

        // Read records from block
        $record_d = \Avro\Internal\Decoder::FromString($block_bytes);
        for ($i = 0; $i < (int)$object_count; $i++) {
          $records[] = \Avro\Internal\ReadDatum($this->schema, $record_d);
        }
      }

      return $records;
    }
  }

  function SchemaToJson(Schema $schema): string {
    $data = SchemaToData($schema);
    return \json_encode($data, \JSON_PARTIAL_OUTPUT_ON_ERROR);
  }

  function SchemaToData(Schema $schema): mixed {
    switch ($schema->type) {
      case \Avro\SchemaType::NULL_TYPE: return 'null';
      case \Avro\SchemaType::BOOLEAN: return 'boolean';
      case \Avro\SchemaType::INT: return 'int';
      case \Avro\SchemaType::LONG: return 'long';
      case \Avro\SchemaType::FLOAT: return 'float';
      case \Avro\SchemaType::DOUBLE: return 'double';
      case \Avro\SchemaType::BYTES: return 'bytes';
      case \Avro\SchemaType::STRING: return 'string';
      case \Avro\SchemaType::RECORD:
        $fields = vec[];
        foreach ($schema->fields as $f) {
          $field_data = dict['name' => $f->name, 'type' => SchemaToData($f->schema)];
          if ($f->has_default) {
            $field_data['default'] = $f->default_value;
          }
          $fields[] = $field_data;
        }
        return dict['type' => 'record', 'name' => $schema->name, 'fields' => $fields];
      case \Avro\SchemaType::ENUM:
        return dict['type' => 'enum', 'name' => $schema->name, 'symbols' => $schema->symbols];
      case \Avro\SchemaType::ARRAY_TYPE:
        $items = $schema->items;
        return dict['type' => 'array', 'items' => $items !== null ? SchemaToData($items) : 'null'];
      case \Avro\SchemaType::MAP_TYPE:
        $values = $schema->values;
        return dict['type' => 'map', 'values' => $values !== null ? SchemaToData($values) : 'null'];
      case \Avro\SchemaType::UNION:
        $branches = vec[];
        foreach ($schema->union as $s) {
          $branches[] = SchemaToData($s);
        }
        return $branches;
      case \Avro\SchemaType::FIXED:
        return dict['type' => 'fixed', 'name' => $schema->name, 'size' => $schema->size];
    }
  }

  function GenerateSync(): string {
    $sync = '';
    for ($i = 0; $i < SYNC_SIZE; $i++) {
      $sync .= \chr(\mt_rand(0, 255));
    }
    return $sync;
  }
}
