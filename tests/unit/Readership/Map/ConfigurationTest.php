<?php

use PHPUnit\Framework\TestCase;
use Readership\Map\Configuration;

class ConfigurationTest extends TestCase {

  protected function setUp() {
    $this->dataDir =  'tests/data/Readership/Map/ConfigurationTest';
    $this->config = new Configuration("{$this->dataDir}/config.yml");
    $this->initialViews = Symfony\Component\Yaml\Yaml::parsefile("{$this->dataDir}/initialViews.yml");
    $this->additionalViews = Symfony\Component\Yaml\Yaml::parsefile("{$this->dataDir}/additionalViews.yml");
    $this->finalViews = Symfony\Component\Yaml\Yaml::parsefile("{$this->dataDir}/finalViews.yml");
    $this->startDate = date('Y-m-d', strtotime('yesterday'));
    $this->endDate = date('Y-m-d', strtotime('yesterday'));
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
