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
    $this->tempCache = tempnam('/tmp', 'readership-map-scraper-test-');
    $this->cacheFileContent = '{"cache":"value"}';
    file_put_contents($this->tempCache, $this->cacheFileContent);
    $this->scraper = new Scraper($this->dataDir . "/urls.json");
    $this->cachingScraper = new Scraper($this->tempCache);
  }

  protected function tearDown() : void {
    if (file_exists($this->tempCache)) {
      unlink($this->tempCache);
    }
  }

  public function testScrapeCoins() {
    $results = $this->scraper->scrapeHTML($this->coinsHTML, $this->coinsURL);
    $this->assertSame($results, $this->scrapeCoinsResults);
  }

  public function testCache() {
    $result = $this->cachingScraper->scrape('cache');
    $this->assertSame($result, 'value');
    $this->cachingScraper->writeCache();
    $cacheFile = file_get_contents($this->tempCache);
    $this->assertSame($cacheFile, $this->cacheFileContent);
  }
}
