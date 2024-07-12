<?php
namespace Readership\Map;


use Google\Analytics\Data\V1beta\Client\BetaAnalyticsDataClient;
use Google\Analytics\Data\V1beta\Dimension;
use Google\Analytics\Data\V1beta\DateRange;
use Google\Analytics\Data\V1beta\RunReportRequest;
use Google\Analytics\Data\V1beta\Metric;
use Google\Analytics\Data\V1beta\Filter;
use Google\Analytics\Data\V1beta\FilterExpression;
use Google\Analytics\Data\V1beta\FilterExpressionList;
use Google\Analytics\Data\V1beta\Row;
use Google\Analytics\Data\V1beta\Filter\StringFilter;
use Google\Analytics\Data\V1beta\OrderBy;

use Google\Analytics\Admin\V1beta\Account;
use Google\Analytics\Admin\V1beta\Client\AnalyticsAdminServiceClient;
use Google\Analytics\Admin\V1beta\ListAccountsRequest;
use Google\Analytics\Admin\V1beta\ListPropertiesRequest;
use Google\Analytics\Admin\V1beta\DataStream;
use Google\Analytics\Admin\V1beta\GetDataStreamRequest;
use Google\Analytics\Admin\V1beta\ListDataStreamsRequest;
use Google\ApiCore\PagedListResponse;
/**
 * @codeCoverageIgnore
 */
class GoogleClientDriver {
  private $analyticsClient;
  private $adminClient;
  private $streams = [];
  private $accountInfo = [];
  private $googleClientConfig = [
    'retries' => 5,
  ];

  public function __construct($name, $scopes) {
    $this->analyticsClient = new BetaAnalyticsDataClient();
    $this->adminClient = new AnalyticsAdminServiceClient();
    $this->loadAccountData();
  }

  private function get_date_range($name, $start_date, $end_date) { 
    $dateRange = new DateRange(["name" => $name]);
    $dateRange->setStartDate($start_date); 
    $dateRange->setEndDate($end_date);
  
    return $dateRange;
  }

  public function get_dimension($name) {
    return new Dimension(["name" => $name]);
  }

  public function get_metric($name) {
    return new Metric(['name' => $name]);
  }

  public function get_stream_id_filter($stream_id){
    return new FilterExpression([
      'filter' => 
        new Filter([
          'field_name' => 'streamId',
          'string_filter' => new StringFilter([
            'value' => "$stream_id",
            'match_type' => Filter\StringFilter\MatchType::EXACT
          ])
        ])
          ]);
  }

  public function get_query_stream_filter_expression($stream_id, $filters){
    $stream_id_filter = $this->get_stream_id_filter($stream_id);

    return $filters == null ? 
      $stream_id_filter :
      new FilterExpression([
        'and_group' => new FilterExpressionList([
          'expressions' => [
            $stream_id_filter,
            new FilterExpression($filters)
          ]
        ])
      ]);
  }

  public function queryTest() {
    $dateRanges = [ $this->get_date_range('recent_range', '30daysAgo', 'today') ];
    
    $request = new RunReportRequest([
      'property' => "properties/352197853",
      'date_ranges' => $dateRanges,
      'metrics' => [ $this->get_metric('screenPageViews')],
      'dimensions' => [$this->get_dimension('pagePathPlusQueryString')],
      /*'dimension_filter' => new FilterExpression([
        'filter' => 
          new Filter([
            'field_name' => htmlspecialchars('pagePathPlusQueryString'),
            'string_filter' => new StringFilter([
              'value' => htmlspecialchars('^/(concern/.+?|epubs)/([A-Za-z0-9])'
            ),
            'match_type' => Filter\StringFilter\MatchType::PARTIAL_REGEXP
          ])
        ])
      ])*/
    ]);

    $retVal = $this->analyticsClient->runReport($request);

    return $retVal;
  }

  // TODO: Add in dimensions and filters
  public function query($property_id, $id, $start, $end, $metrics, $options) {
    try {
      fwrite(STDERR, PHP_EOL . "Start: " . json_encode($start) . PHP_EOL . json_encode($end) . PHP_EOL);
      $dateRanges = [ $this->get_date_range('recent_range', $start, $end) ];
      $map = function($name) { return $this->get_dimension($name); };

      $dimensions_map = [
        'screenPageViews' => 'dateHourMinute,hostName,pagePathPlusQueryString,city,region,country,pageTitle',
        'eventCount' => 'dateHourMinute,hostName,pagePathPlusQueryString,city,region,country,eventName'
      ];
      // $dimensions = array_map('get_dimension', explode(",", $dimensions_map[$metrics->getName()]));

      $dimension_values = $options["dimensions"] ?? $dimensions_map[$metrics];
      if(!str_contains($dimension_values, 'dateHourMinute')) {
        $dimension_values = "$dimension_values,dateHourMinute";
      }
      $dimensions = array_map(
        $map, 
        explode(",", $dimension_values)
      );

      $filters = null;

      if(key_exists("filters", $options)){
        $filters_string = $options["filters"];
        $filters_data = explode('=~', $filters_string, 2);
        $filters = new FilterExpression([
          'filter' => 
            new Filter([
              'field_name' => htmlspecialchars($filters_data[0]),
              'string_filter' => new StringFilter([
                'value' => htmlspecialchars($filters_data[1]
              ),
              'match_type' => Filter\StringFilter\MatchType::PARTIAL_REGEXP
            ])
          ])
        ]);
      }
      $request = new RunReportRequest([
        'property' => "properties/$property_id",
        'date_ranges' => $dateRanges,
        'dimensions' => $dimensions,
        'metrics' => [ $this->get_metric($metrics)],
        'order_bys' => [
          new OrderBy([
              'dimension' => new OrderBy\DimensionOrderBy([
                  'dimension_name' => 'dateHourMinute', 
                  'order_type' => OrderBy\DimensionOrderBy\OrderType::ALPHANUMERIC
              ]),
              'desc' => false,
          ]),],
      ]);

      if($filters != null){ 
        $request->setDimensionFilter($filters);
      }

      $retVal = $this->analyticsClient->runReport($request);
      return $retVal;
    }
    catch (\Exception $e) {
      $exception = PHP_EOL . "EXCEPTION (query)" . PHP_EOL . $e->getMessage() . 
            PHP_EOL . "Property ID: $property_id " . 
            PHP_EOL . $e->getTraceAsString() . PHP_EOL;
      print($exception);
      fwrite(STDERR, $exception);
      return new NullResults();
    }
  }

  public function getAccountInfo() {
    return $this->accountInfo;
  }

  public function getStreams() {
    return $this->streams;
  }

  private function listAccounts() : PagedListResponse {
    try {
      $listAccountsRequest = new ListAccountsRequest();
      return $this->adminClient->listAccounts($listAccountsRequest);
    }
    catch (\Exception $e) {
      return [];
    }
  }

  private function listProperties(string $accountId) {
    try {
      $filter = new ListPropertiesRequest();
      $filter->setFilter("parent:accounts/$accountId");
      return $this->adminClient->listProperties($filter);
    }
    catch (\Exception $e) {
      return [];
    }
  }

  private function listDataStreams(string $propertyId) {
    try {
      $streamRequest = (new ListDataStreamsRequest());
      $streamRequest->setParent("properties/$propertyId");
      return $this->adminClient->listDataStreams($streamRequest);
    }
    catch (\Exception $e) {
      return [];
    }
  }

  public function loadAccountData() {

    $accounts = $this->listAccounts();

    foreach ($accounts as $account) {
      $account_id = explode('/', $account->getName())[1];
      $account_name = $account->getDisplayName();
      
      // fwrite(STDERR, $account_id . PHP_EOL);
      
      $filter = new ListPropertiesRequest();
      $filter->setFilter("parent:accounts/$account_id");
      $properties = $this->adminClient->listProperties($filter);
  
      foreach ($properties as $property) {
        $property_id = (string) explode('/', $property->getName())[1];
        $property_name = $property->getDisplayName();
        $streamRequest = (new ListDataStreamsRequest());
        $streamRequest->setParent("properties/$property_id");

        $streams = $this->adminClient->listDataStreams($streamRequest);
  
       foreach ($streams as $stream) {
          $stream_id = (string) explode('/', $stream->getName())[3];
          $stream_name = $stream->getDisplayName();
          $stream_url  = $stream->getWebStreamData()->getDefaultUri();

          // fwrite(STDERR, "-- $stream_id $stream_name" . PHP_EOL);
  
          $this->streams[$stream_id] = [
            'id' => $stream_id,
            'stream_name' => $stream_name,
            'stream_url' => $stream_url,
            'property_name' => $property_name,
            'property_id' => $property_id,
            'account_name' => $account_name,
            'account_id' => $account_id,
          ];
        }
      }
    }
  }
}
