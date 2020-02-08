<?php
namespace Readership\Map;

trait Logging {
  private $logVerbose = TRUE;

  public function verbose() {
    $this->logVerbose = TRUE;
    return $this;
  }

  public function quiet() {
    $this->logVerbose = FALSE;
    return $this;
  }

  private function log($string) {
    if ($this->logVerbose) {
      fwrite(STDERR, $string);
    }
    return $this;
  }
}
