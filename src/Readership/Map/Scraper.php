<?php
namespace Readership\Map;

class Scraper {
  private $urls;
  private $cache;

  public function __construct($cache = 'urls.json') {
    $this->cache = $cache;
    if (file_exists($this->cache)) {
      $this->urls = json_decode(file_get_contents($this->cache), TRUE);
    }
    else {
      $this->urls = [];
    }
  }

  public function writeCache() {
    file_put_contents('urls.json', json_encode($this->urls));
    return $this;
  }

  private function scrape_meta_tags($qp) {
    $ret = [];

    return $ret;
  }

  public function scrape($url) {

    if (is_null($url)) {
      return NULL;
    }

    if (isset($this->urls[$url])) {
      return $this->urls[$url];
    }

    if (strpos($url, '/data/downloads') !== NULL) {
      return $this->urls[$url] = NULL;
    }

    $html = @file_get_contents($url);
    if (empty($html)) {
      fwrite(STDERR, "  Scrape failed: $url empty\n");
      return $this->urls[$url] = NULL;
    }

    $ret = [];
    $qp = html5qp($html);

    $meta_tags = [
      ['citation_title'],
      ['citation_author'],
      ['citation_doi', 'DC.Identifier', 'citation_hdl']
    ];

    $this->log("Parsing meta_tags\n");

    foreach ($meta_tags as $tag_list) {
      $value = NULL;
      foreach($tag_list as $tag) {
        $content = qp($qp, "meta[@name='$tag']")->attr('content');
        if (!empty($content)) {
          $value = $content;
          break;
        }
      }
      if ($value) {
        $ret[] = $value;
      }
      else {
        $ret[] = '';
      }
    }
    $this->log("Finished parsing meta_tags\n");

    if (strpos($ret[2], 'doi:') === 0) {
      $ret[2] = 'https://doi.org/' . substr($ret[2], 4, strlen($ret[2]));
    } elseif (strpos($ret[2], '10.') === 0) {
      $ret[2] = 'https://doi.org/' . $ret[2];
    } elseif (strpos($ret[2], '2027') === 0) {
      $ret[2] = 'https://hdl.handle.net/' . $ret[2];
    } else {
      $ret[2] = $url;
    }
    $this->log("Checking OA status\n");

    $content = qp($qp, "img[@alt='Open Access icon']");
    if ($content->length > 0) {
      $ret[] = 'open';
    }
    else {
      $ret[] = 'subscription';
    }
    if (empty($ret[0]) || empty($ret[1]) || empty($ret[2])) {
      fwrite(STDERR, "  Scrape failed: $url unable to find metadata\n");
      return $this->urls[$url] = NULL;
    }

    $ret = [
      'citation_title' => $ret[0],
      'citation_author' => $ret[1],
      'citation_url' => $ret[2],
      'access' => $ret[3],
    ];

    return $this->urls[$url] = $ret;
  }

  private function log($string) {
    fwrite(STDERR, $string);
  }
}
