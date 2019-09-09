<?php

require_once __DIR__ . '/vendor/autoload.php';

$client = new Google_Client();
$client->useApplicationDefaultCredentials();
$client->setApplicationName('Michigan Publishing Readership Map');
$client->setScopes(['https://www.googleapis.com/auth/analytics.readonly']);
$analytics = new Google_Service_Analytics($client);

// From https://developers.google.com/analytics/devguides/reporting/core/v3/quickstart/service-php
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
        ['dimensions' => 'ga:pageTitle,ga:hostname,ga:pagePath,ga:country,ga:region,ga:city,ga:dateHourMinute']
      );
      $rows = $results->getRows();
      print json_encode($rows);
    }
  }
}
