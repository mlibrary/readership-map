<?php
namespace Readership\Map;

class Scraper {
  use Logging;

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
    file_put_contents($this->cache, json_encode($this->urls));
    return $this;
  }

  public function scrape($url) {

    if (is_null($url)) {
      $this->log("  Scrape failed: $url null\n");
      return NULL;
    }

    if (isset($this->urls[$url])) {
      return $this->urls[$url];
    }

    if (strpos($url, '/data/downloads') !== FALSE) {
      return $this->urls[$url] = NULL;
    }

    $options = ['http' => ['user_agent' => 'Readership Map Metadata Scraper Bot']];
    $context = stream_context_create($options);
    $html = @file_get_contents($url, FALSE, $context);
    if (empty($html)) {
      $reason = !empty($htp_response_header) && count($http_response_header) > 0 ? $http_response_header[0] : '';
      $this->log("  Scrape failed: $url empty / $reason\n");
      return $this->urls[$url] = NULL;
    }
    return $this->urls[$url] = $this->scrapeHTML($html, $url);
  }

  public function scrapeHTML($html, $url = '') {

    $ret = [];
    $qp = html5qp($html);

    $meta_tags = [
      ['citation_title'],
      ['citation_author'],
      ['citation_doi', 'DC.Identifier', 'citation_hdl']
    ];

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

    if (strpos($ret[2], 'doi:') === 0) {
      $ret[2] = 'https://doi.org/' . substr($ret[2], 4, strlen($ret[2]));
    } elseif (strpos($ret[2], '10.') === 0) {
      $ret[2] = 'https://doi.org/' . $ret[2];
    } elseif (strpos($ret[2], '2027') === 0) {
      $ret[2] = 'https://hdl.handle.net/' . $ret[2];
    } else {
      $ret[2] = $url;
    }

    $content = qp($qp, "img[@alt='Open Access']");
    if ($content->length > 0) {
      $ret[] = 'open';
    }
    else {
      $ret[] = 'subscription';
    }
    if (empty($ret[0]) || empty($ret[1]) || empty($ret[2])) {
      $ret = $this->scrapeCoins($qp);
      if (empty($ret[0]) || empty($ret[1]) || empty($ret[2])) {
        //$this->log("  Scrape failed: $url unable to find metadata\n");
        return NULL;
      }
    }

    $ret = [
      'citation_title' => $ret[0],
      'citation_author' => $ret[1],
      'citation_url' => $ret[2],
      'access' => $ret[3],
    ];

    return $ret;
  }

  private function scrapeCoins($qp) {
    $content = qp($qp, "span[@class='Z3988']")->attr('title');
    if (empty($content)) {
      return [];
    }
    $pairs = [];
    foreach (explode('&', $content) as $pair) {
      $keyvalue = explode('=', $pair, 2);
      if(count($keyvalue) == 2){
        list($key, $val) = $keyvalue;
        $pairs[$key] = urldecode($val);
      }
    }
    return [
      isset($pairs['rft.title']) ? $pairs['rft.title'] : NULL,
      isset($pairs['rft.au']) ? $pairs['rft.au'] : NULL,
      isset($pairs['rft_id']) ? $pairs['rft_id'] : NULL,
      'open'
    ];
  }

}
