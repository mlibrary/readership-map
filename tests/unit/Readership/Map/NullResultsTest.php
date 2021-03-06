<?php

use PHPUnit\Framework\TestCase;

use Readership\Map\NullResults;

class NullResultsTest extends TestCase {

  protected function setUp() : void {
    $this->results = new NullResults();
  }

  public function testNullResults() {
    $this->assertSame($this->results->getRows(), []);
  }
}
