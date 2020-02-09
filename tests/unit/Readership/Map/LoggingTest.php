<?php

use PHPUnit\Framework\TestCase;
use Readership\Map\Logging;
use Symfony\Component\Yaml\Yaml;

class LoggingClass {
  use Logging;

  public function __construct() {
    $this->log('');
  }
}

class LoggingTest extends TestCase {
  protected function setUp() : void {
    $this->logging = new LoggingClass();
  }

  public function testVerbose() {
    $this->assertSame($this->logging, $this->logging->verbose());
  }

}
