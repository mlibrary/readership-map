<?php
use PHPUnit\Framework\TestCase;
use Readership\Map\Scraper;
use Symfony\Component\Yaml\Yaml;

class ScraperTest extends TestCase {

  protected function setUp() : void {
    $this->dataDir = 'tests/data/Readership/Map/ScraperTest';
    $this->coinsURL = 'https://quod.lib.umich.edu/d/did/did2222.0000.564/--intolerance?rgn=main;view=fulltext;q1=Denis+Diderot+ascribed+by+Jacques+Proust';
    $this->coinsHTML = file_get_contents($this->dataDir . '/scrapeCoins.html');
    $this->scrapeCoinsResults = Yaml::parsefile($this->dataDir . '/scrapeCoinsResults.yml');
  }

  public function testScrapeCoins() {
    $scraper = new Scraper($this->dataDir . "/urls.json");
    $results = $scraper->scrapeHTML($this->coinsHTML, $this->coinsURL);
    $this->assertSame($results, $this->scrapeCoinsResults);
  }
}
