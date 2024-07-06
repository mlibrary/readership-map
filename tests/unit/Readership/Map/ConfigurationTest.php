<?php

use PHPUnit\Framework\TestCase;
use Readership\Map\Configuration;
use Symfony\Component\Yaml\Yaml;

class ConfigurationTest extends TestCase {

  protected function setUp() : void {
    $this->dataDir =  'tests/data/Readership/Map/ConfigurationTest';
    $this->config = new Configuration("{$this->dataDir}/config.yml");
    $this->initialViews = Yaml::parsefile("{$this->dataDir}/initialViews.yml");
    $this->additionalViews = Yaml::parsefile("{$this->dataDir}/additionalViews.yml");
    $this->finalViews = Yaml::parsefile("{$this->dataDir}/finalViews.yml");
    $this->startDate = date('Y-m-d', strtotime('yesterday'));
    $this->endDate = date('Y-m-d', strtotime('today'));
  }

  public function testPinStartDate() {
    $this->assertSame($this->config->pinStartDate(), $this->startDate);
  }

  public function testPinEndDate() {
    $this->assertSame($this->config->pinEndDate(), $this->endDate);
  }

  public function testGetViews() {
    $this->assertSame($this->config->getViews(), $this->initialViews);
  }

  public function testAddViews() {
    $this->config->addViews($this->additionalViews);
    $this->assertSame($this->config->getViews(), $this->finalViews);
  }

}
