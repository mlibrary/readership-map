<?php
use PHPUnit\Framework\TestCase;
use Readership\Map\Scraper;
use Symfony\Component\Yaml\Yaml;

class ScraperTest extends TestCase {

  protected function setUp() : void {
    $this->dataDir = 'tests/data/Readership/Map/ScraperTest';
    $this->coinsURL = 'https://quod.lib.umich.edu/d/did/did2222.0000.564/--intolerance?rgn=main;view=fulltext;q1=Denis+Diderot+ascribed+by+Jacques+Proust';
    $this->coinsHTML = file_get_contents($this->dataDir . '/scrapeCoins.html');
    $this->failureHTML = file_get_contents($this->dataDir . '/scrapeFailure.html');
    $this->scrapeCoinsResults = Yaml::parsefile($this->dataDir . '/scrapeCoinsResults.yml');
    $this->scrapeMetaTagResults = Yaml::parsefile($this->dataDir . '/scrapeMetaTagResults.yml');
    $this->tempCache = tempnam('/tmp', 'readership-map-scraper-test-');
    $this->cacheFileContent = '{"cache":"value"}';
    file_put_contents($this->tempCache, $this->cacheFileContent);
    $this->scraper = (new Scraper($this->dataDir . "/urls.json"))->quiet();
    $this->cachingScraper = (new Scraper($this->tempCache))->quiet();
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

  public function testScrapeFailure() {
    $results = $this->scraper->scrapeHTML($this->failureHTML, $this->coinsURL);
    $this->assertSame($results, NULL);
  }

  public function testScrapeNull() {
    $this->assertSame($this->scraper->scrape(NULL), NULL);
  }

  public function testScrapeDataDownloads() {
    $this->assertSame($this->scraper->scrape('/data/downloads'), NULL);
  }

  public function testScrapeDevNull() {
    $this->assertSame($this->scraper->scrape('/dev/null'), NULL);
  }

  public function testScrapeMetaData() {
    $results = $this->scraper->scrape($this->dataDir . '/scrapeMetaTag.html');
    $this->assertSame($results, $this->scrapeMetaTagResults);
  }

  public function testCache() {
    $result = $this->cachingScraper->scrape('cache');
    $this->assertSame($result, 'value');
    $this->cachingScraper->writeCache();
    $cacheFile = file_get_contents($this->tempCache);
    $this->assertSame($cacheFile, $this->cacheFileContent);
  }
}
