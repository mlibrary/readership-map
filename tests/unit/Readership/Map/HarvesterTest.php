<?php

use PHPUnit\Framework\TestCase;

use Readership\Map\AnalyticsAccount;
use Readership\Map\Configuration;
use Readership\Map\Harvester;
use Readership\Map\Scraper;
use Symfony\Component\Yaml\Yaml;

class StubResults {
  private $data;
  public function __construct($data) {
    $this->data = $data;
  }

  public function getRows() {
    return $this->data['rows'];
  }

  public function getTotalResults() {
    if (isset($this->data['totalResults'])) {
      return $this->data['totalResults'];
    }
    return count($this->data['rows']);
  }
}

class StubConfiguration extends Configuration {
  private $data;
  public function __construct($file = '') {
    $this->data = Yaml::parsefile($file);
  }

  public function getViews() {
    return $this->data['getViews'];
  }
}

class StubAnalyticsAccount extends AnalyticsAccount {
  private $data;
  public function __construct($file = '') {
    $this->data = Yaml::parsefile($file);
  }

  public function getGeoData($property_id, $id, $index) {
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

  public function scrape($url) {
    return [
      'citation_title' => 'Title',
      'citation_author' => 'Author',
      'citation_url' => 'URL',
      'access' => 'open',
    ];
  }
}

class HarvesterTest extends TestCase {
  private $harvester;
  private $emptyJSON;
  private
  protected function setUp() : void {
    $this->dataDir = 'tests/data/Readership/Map/HarvesterTest';
    $this->config = new StubConfiguration($this->dataDir . '/config.yml');
    $this->account = new StubAnalyticsAccount($this->dataDir . '/account.yml');
    $this->scraper = new StubScraper($this->dataDir . '/scraper.yml');
    $this->harvester = (new Harvester($this->config, $this->account, $this->scraper))->quiet();
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
