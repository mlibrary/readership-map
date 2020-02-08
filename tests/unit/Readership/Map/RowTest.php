<?php

use PHPUnit\Framework\TestCase;
use Readership\Map\Row;
use Symfony\Component\Yaml\Yaml;

class Scraper {
  public function scrape($url = '') {
    return 'Metadata';
  }
}

class RowTest extends TestCase {
  protected function setUp() {
    $this->dataDir = 'tests/data/Readership/Map/RowTest';
    $this->dimensions = 'ga:city,ga:region,ga:country,ga:dateHourMinute';
    $this->metrics = 'ga:eventLabel,ga:hostname,ga:pagePath';
    $this->rowData = Yaml::parsefile("{$this->dataDir}/rowData.yml");
    $this->row = new Row($this->dimensions, $this->metrics, $this->rowData, new Scraper());
    $this->geoMap = [
      'City//Region//Country' => 'Position',
    ];
    $this->viewURL = 'https://quod-lib-umich-edu.www-fulcrum-org.site.edu';
  }

  public function testGetDate() {
    $this->assertSame($this->row->getDate(), 'Date');
  }

  public function testGetPosition() {
    $this->assertSame($this->row->getPosition($this->geoMap), 'Position');
  }

  public function testGetLocation() {
    $this->assertSame($this->row->getLocation(), 'City, Region, Country');
  }

  public function testGetMetadata() {
    $this->assertSame($this->row->getMetadata($this->viewURL), 'Metadata');
  }
}
