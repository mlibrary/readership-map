<?php

use PHPUnit\Framework\TestCase;

use Readership\Map\AnalyticsAccount;
use Readership\Map\Configuration;
use Readership\Map\Harvester;

class HarvesterTest extends TestCase {

  public function testSomething() {
    $harvester = new Harvester(new Configuration(), new AnalyticsAccount('Readership\Map\StubDriver'));
    $this->assertTrue(true);
  }
}
