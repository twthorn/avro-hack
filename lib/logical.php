<?hh // strict

namespace Avro\Logical {
  use type \Avro\Schema;
  use type \Avro\SchemaType;

  const string DATE = 'date';
  const string TIME_MILLIS = 'time-millis';
  const string TIME_MICROS = 'time-micros';
  const string TIMESTAMP_MILLIS = 'timestamp-millis';
  const string TIMESTAMP_MICROS = 'timestamp-micros';
  const string UUID = 'uuid';
  const string DECIMAL = 'decimal';

  function DateFromEpochDays(int $days): int {
    return $days;
  }

  function DateToEpochDays(int $date): int {
    return $date;
  }

  function DateFromTimestamp(int $unix_timestamp): int {
    return (int)($unix_timestamp / 86400);
  }

  function DateToTimestamp(int $date): int {
    return $date * 86400;
  }

  function TimeMillis(int $hours, int $minutes, int $seconds, int $millis = 0): int {
    return ($hours * 3600000) + ($minutes * 60000) + ($seconds * 1000) + $millis;
  }

  function TimeMillisToComponents(int $time_millis): shape('hours' => int, 'minutes' => int, 'seconds' => int, 'millis' => int) {
    $hours = (int)($time_millis / 3600000);
    $time_millis -= $hours * 3600000;
    $minutes = (int)($time_millis / 60000);
    $time_millis -= $minutes * 60000;
    $seconds = (int)($time_millis / 1000);
    $millis = $time_millis - ($seconds * 1000);
    return shape('hours' => $hours, 'minutes' => $minutes, 'seconds' => $seconds, 'millis' => $millis);
  }

  function TimeMicros(int $hours, int $minutes, int $seconds, int $micros = 0): int {
    return ($hours * 3600000000) + ($minutes * 60000000) + ($seconds * 1000000) + $micros;
  }

  function TimeMicrosToComponents(int $time_micros): shape('hours' => int, 'minutes' => int, 'seconds' => int, 'micros' => int) {
    $hours = (int)($time_micros / 3600000000);
    $time_micros -= $hours * 3600000000;
    $minutes = (int)($time_micros / 60000000);
    $time_micros -= $minutes * 60000000;
    $seconds = (int)($time_micros / 1000000);
    $micros = $time_micros - ($seconds * 1000000);
    return shape('hours' => $hours, 'minutes' => $minutes, 'seconds' => $seconds, 'micros' => $micros);
  }

  function TimestampMillisFromUnix(int $unix_seconds): int {
    return $unix_seconds * 1000;
  }

  function TimestampMillisToUnix(int $ts_millis): int {
    return (int)($ts_millis / 1000);
  }

  function TimestampMillisNow(): int {
    return (int)(\microtime(true) * 1000);
  }

  function TimestampMicrosFromUnix(int $unix_seconds): int {
    return $unix_seconds * 1000000;
  }

  function TimestampMicrosToUnix(int $ts_micros): int {
    return (int)($ts_micros / 1000000);
  }

  function TimestampMicrosNow(): int {
    return (int)(\microtime(true) * 1000000);
  }

  function ValidateUUID(string $uuid): bool {
    return \preg_match(
      '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
      $uuid,
    ) === 1;
  }

  function GenerateUUID(): string {
    $bytes = '';
    for ($i = 0; $i < 16; $i++) {
      $bytes .= \chr(\mt_rand(0, 255));
    }
    $bytes[6] = \chr((\ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = \chr((\ord($bytes[8]) & 0x3f) | 0x80);

    return \sprintf(
      '%s-%s-%s-%s-%s',
      \bin2hex(\substr($bytes, 0, 4)),
      \bin2hex(\substr($bytes, 4, 2)),
      \bin2hex(\substr($bytes, 6, 2)),
      \bin2hex(\substr($bytes, 8, 2)),
      \bin2hex(\substr($bytes, 10, 6)),
    );
  }

  class Decimal {
    public function __construct(
      public int $unscaled,
      public int $scale,
      public int $precision,
    ) {}

    public function toFloat(): float {
      return (float)$this->unscaled / \pow(10, $this->scale);
    }

    public static function fromFloat(float $value, int $scale, int $precision): Decimal {
      $unscaled = (int)\round($value * \pow(10, $scale));
      return new Decimal($unscaled, $scale, $precision);
    }

    public function toBytes(): string {
      $n = $this->unscaled;
      if ($n === 0) {
        return "\x00";
      }

      $bytes = '';
      $negative = $n < 0;
      if ($negative) {
        $n = -$n - 1;
        $flip = true;
      } else {
        $flip = false;
      }

      while ($n > 0) {
        $bytes = \chr($n & 0xFF).$bytes;
        $n = $n >> 8;
      }

      if (!$flip && \ord($bytes[0]) >= 0x80) {
        $bytes = "\x00".$bytes;
      }

      if ($flip) {
        $result = '';
        for ($i = 0; $i < \strlen($bytes); $i++) {
          $result .= \chr(\ord($bytes[$i]) ^ 0xFF);
        }
        $bytes = $result;
        if (\ord($bytes[0]) < 0x80) {
          $bytes = "\xFF".$bytes;
        }
      }

      return $bytes;
    }

    public static function fromBytes(string $bytes, int $scale, int $precision): Decimal {
      if (\strlen($bytes) === 0) {
        return new Decimal(0, $scale, $precision);
      }

      $negative = (\ord($bytes[0]) & 0x80) !== 0;
      $n = 0;

      if ($negative) {
        for ($i = 0; $i < \strlen($bytes); $i++) {
          $n = ($n << 8) | (\ord($bytes[$i]) ^ 0xFF);
        }
        $n = -($n + 1);
      } else {
        for ($i = 0; $i < \strlen($bytes); $i++) {
          $n = ($n << 8) | \ord($bytes[$i]);
        }
      }

      return new Decimal($n, $scale, $precision);
    }

    public function __toString(): string {
      $abs = \abs($this->unscaled);
      $s = (string)$abs;
      if ($this->scale > 0) {
        while (\strlen($s) <= $this->scale) {
          $s = '0'.$s;
        }
        $int_part = \substr($s, 0, \strlen($s) - $this->scale);
        $frac_part = \substr($s, \strlen($s) - $this->scale);
        $s = $int_part.'.'.$frac_part;
      }
      if ($this->unscaled < 0) {
        $s = '-'.$s;
      }
      return $s;
    }
  }
}
