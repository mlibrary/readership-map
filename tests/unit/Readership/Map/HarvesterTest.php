<?php

use PHPUnit\Framework\TestCase;

use Readership\Map\AnalyticsAccount;
use Readership\Map\Configuration;
use Readership\Map\Harvester;
use Readership\Map\Scraper;
use Symfony\Component\Yaml\Yaml;

class StubResults {
  public function __construct($data) {
    $this->data = $data;
  }

  public function getRows() {
    return $this->data;
  }

  public function getTotalResults() {
    return count($this->data);
  }
}

class StubConfiguration extends Configuration {

  public function __construct($file = '') {
    $this->data = Yaml::parsefile($file);
  }

  public function getViews() {
    return $this->data['getViews'];
  }
}

class StubAnalyticsAccount extends AnalyticsAccount {
  public function __construct($file = '') {
    $this->data = Yaml::parsefile($file);
  }

  public function getGeoData($id, $index) {
    return new StubResults($this->data['getGeoData']);
  }

  public function getViewTotals($id, $metrics, $filters) {
    return new stubResults($this->data['getViewTotals']);
  }

  public function getViewAnnual($id, $metrics, $filters) {
    return new stubResults($this->data['getViewAnnual']);
  }

  public function getViewRecent($id, $start, $end, $metrics, $dimensions, $max_results, $filters) {
    return new stubResults($this->data['getViewRecent']);
  }

  public function pinStartDate() {
    return 'START';
  }

  public function pinEndDate() {
    return 'END';
  }
}

class StubScraper extends Scraper {
  public function __construct($file = '') {
    $this->data = Yaml::parsefile($file);
  }
}

class HarvesterTest extends TestCase {

  protected function setUp() : void {
    $this->dataDir = 'tests/data/Readership/Map/HarvesterTest';
    $this->config = new StubConfiguration($this->dataDir . '/config.yml');
    $this->account = new StubAnalyticsAccount($this->dataDir . '/account.yml');
    $this->scraper = new StubScraper($this->dataDir . '/scraper.yml');
    $this->harvester = new Harvester($this->config, $this->account, $this->scraper);
    $this->emptyJSON = '{"pageviews":{"total":[],"annual":[]},"pins":[]}';
    $this->filledJSON = Yaml::parsefile($this->dataDir . '/filledJSON.yml');
  }

  public function testEmptyToJSON() {
    $this->assertSame($this->harvester->toJSON(), $this->emptyJSON);
  }

  public function testRun() {
    $this->harvester->run();
    $this->assertSame($this->harvester->toJSON(), $this->filledJSON);
  }
}
