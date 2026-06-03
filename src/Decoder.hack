namespace Avro;

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
      throw new AvroException("buffer overrun reading bool");
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
        throw new AvroException("buffer overrun reading varint");
      }
      $b = \ord($this->buf[$this->offset]);
      $this->offset++;
      $val |= (($b & 0x7F) << $shift);
      if (($b & 0x80) === 0) {
        break;
      }
      $shift += 7;
    }
    return (($val >> 1) & 0x7FFFFFFFFFFFFFFF) ^ (-($val & 1));
  }

  public function readFloat(): float {
    if ($this->offset + 4 > $this->len) {
      throw new AvroException("buffer overrun reading float");
    }
    $f = \unpack('f', \substr($this->buf, $this->offset, 4))[1];
    $this->offset += 4;
    return $f;
  }

  public function readDouble(): float {
    if ($this->offset + 8 > $this->len) {
      throw new AvroException("buffer overrun reading double");
    }
    $d = \unpack('d', \substr($this->buf, $this->offset, 8))[1];
    $this->offset += 8;
    return $d;
  }

  public function readBytes(): string {
    $len = (int)$this->readLong();
    if ($len < 0) {
      throw new AvroException("negative bytes length: ".$len);
    }
    if ($len === 0) {
      return '';
    }
    if ($this->offset + $len > $this->len) {
      throw new AvroException("buffer overrun reading bytes");
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
      throw new AvroException("buffer overrun reading fixed");
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
