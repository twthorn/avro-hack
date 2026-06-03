namespace Avro;

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
    $n = ($n << 1) ^ ($n >> 63);
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
      throw new AvroException(
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
