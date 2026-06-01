<?hh // strict

namespace Errors {
  interface Error {
    public function Ok(): bool;
    public function MustOk(): void;
    public function Error(): string;
  }

  <<__Memoize>>
  function Ok(): Error {
    return new \OkImpl();
  }

  function Error(string $s): Error {
    return new \ErrImpl($s);
  }

  function Errorf(\HH\FormatString<\PlainSprintf> $f, mixed ...$v): Error {
    return Error(\vsprintf($f, $v));
  }
}

namespace {
  class OkImpl implements Errors\Error {
    public function __construct() {}
    public function Ok(): bool {
      return true;
    }
    public function MustOk(): void {}
    public function Error(): string {
      return "OK";
    }
    public function __toString(): string {
      return "OK";
    }
  }

  class ErrImpl implements Errors\Error {
    public function __construct(private string $err) {}
    public function Ok(): bool {
      return false;
    }
    public function MustOk(): void {
      throw new Exception(\sprintf('error not ok: %s', $this->err));
    }
    public function Error(): string {
      return $this->err;
    }
    public function __toString(): string {
      return $this->err;
    }
  }
}
