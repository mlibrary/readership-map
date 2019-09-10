<?php

require_once __DIR__ . '/vendor/autoload.php';

function scrape($url) {
  static $urls = [];
  if (isset($urls[$url])) {
    return $urls[$url];
  }
  $html = @file_get_contents($url);
  if (empty($html)) {
    return ['', '', ''];
  }

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
  return $urls[$url] = $ret;
}

$client = new Google_Client();
$client->useApplicationDefaultCredentials();
$client->setApplicationName('Michigan Publishing Readership Map');
$client->setScopes(['https://www.googleapis.com/auth/analytics.readonly']);
$analytics = new Google_Service_Analytics($client);

// From https://developers.google.com/analytics/devguides/reporting/core/v3/quickstart/service-php
// https://ga-dev-tools.appspot.com/dimensions-metrics-explorer/
$pins = [];
$accounts = $analytics->management_accounts->listManagementAccounts();
foreach ($accounts->getItems() as $account) {
  $account_id = $account->getId();
  $properties = $analytics->management_webproperties->listManagementWebproperties($account_id);
  foreach ($properties->getItems() as $property) {
    $property_id = $property->getId();
    $views = $analytics->management_profiles->listManagementProfiles($account_id, $property_id);
    foreach ($views->getItems() as $view) {
      $view_id = $view->getId();
      $results = $analytics->data_ga->get(
        'ga:' . $view_id,
        '7daysAgo',
        'today',
        'ga:sessions',
        ['dimensions' => 'ga:dateHourMinute,ga:hostname,ga:pagePath,ga:city,ga:region,ga:country,ga:pageTitle']
      );
      $rows = $results->getRows();
      foreach ($rows as $row) {
        list($date, $hostname, $path, $city, $region, $country, $title, $sessions) = $row;
        $url = "https://{$hostname}{$path}";
        list($citation_title, $citation_author, $citation_url) = scrape($url);

        $location = join(array_unique([$city, $region, $country]), ', ');
        $pins[] = [
          'date' => $date,
          'url' => $url,
          'citation_url' => $citation_url,
          'location' => $location,
          'title' => $title,
          'title' => $citation_title,
          'author' => $citation_author,
          'sessions' => $sessions,
        ];
      }
    }
  }
}
print json_encode($pins);
