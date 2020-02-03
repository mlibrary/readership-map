<?php

use PHPUnit\Framework\TestCase;
use Readership\Map\Configuration;

class ConfigurationTest extends TestCase {

  public function testSomething() {
    $config = new Configuration();
    print_r($config);
    $this->assertTrue(true);
  }
}
