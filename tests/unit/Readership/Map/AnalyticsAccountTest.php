<?php

use PHPUnit\Framework\TestCase;
use Readership\Map\AnalyticsAccount;
use Symfony\Component\Yaml\Yaml;

class StubDriver {
  public function __construct($app, $scopes) {
  }

  public function getViews() {
    return 'views';
  }

  public function getAccountInfo() {
    return 'accountInfo';
  }

  public function query($id, $start, $end, $metrics, $options) {
    return [
     "id" => $id,
     "start" => $start,
     "end"   => $end,
     "metrics" => $metrics,
     "options" => $options,
    ];
  }
}

class AnalyticsAccountTest extends TestCase {

  protected function setUp() : void {
    $this->dataDir = 'tests/data/Readership/Map/AnalyticsAccountTest';
    $this->account = new AnalyticsAccount('StubDriver');
    $this->results = Yaml::parsefile($this->dataDir . "/results.yml");
  }

  public function testGetViews() {
    $this->assertSame($this->account->getViews(), 'views');
  }

  public function testGetAccountInfo() {
    $this->assertSame($this->account->getAccountInfo(), 'accountInfo');
  }

  public function testGetViewRecent() {
    $results = $this->account->getViewRecent(
      'id',
      'start',
      'end', 
      'metrics',
      'dimensions',
      'max_results',
      'filters'
    );
    $this->assertSame($results, $this->results['getViewRecent']);
  }

  public function testGetViewAnnual() {
    $results = $this->account->getViewAnnual('id', 'metrics', 'filters');
    $this->assertSame($results, $this->results['getViewAnnual']);
  }

  public function testGetViewTotals() {
    $results = $this->account->getViewTotals('id', 'metrics', 'filters');
    $this->assertSame($results, $this->results['getViewTotals']);
  }

  public function testGetGeoData() {
    $results = $this->account->getGeoData('id', 'index');
    $this->assertSame($results, $this->results['getGeoData']);
  }
}
