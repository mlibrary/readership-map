<?php

use PHPUnit\Framework\TestCase;
use Readership\Map\DataValidator;

class DataValidatorTest extends TestCase {

  protected function setUp() : void {
    $this->dataDir = 'tests/data/Readership/Map/DataValidatorTest';
    $this->validator = new DataValidator();
    $this->validFile = $this->dataDir . '/validFile.json';
    $this->invalidPins = $this->dataDir . '/invalidPins.json';
    $this->invalidCounts = $this->dataDir . '/invalidCounts.json';
  }

  public function testValidFile() {
    $result = $this->validator->validFile($this->validFile);
    $this->assertSame($result, 0);
  }

  public function testInvalidPins() {
    $result = $this->validator->validFile($this->invalidPins);
    $this->assertSame($result, 1);
  }

  public function testInvalidCounts() {
    $result = $this->validator->validFile($this->invalidCounts);
    $this->assertSame($result, 1);
  }
}
