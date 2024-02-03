<?php
require_once __DIR__ . '/../dailyGETs/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__.'/../dailyGETs');
$dotenv->load();

use phpseclib3\Net\SFTP;

use HubSpot\Factory;
use HubSpot\Client\Crm\Deals\ApiException;
use HubSpot\Client\Crm\Deals\Model\Filter;
use HubSpot\Client\Crm\Deals\Model\FilterGroup;
use HubSpot\Client\Crm\Deals\Model\PublicObjectSearchRequest;

$client = Factory::createWithAccessToken($_ENV['HS_KEY']);

$now = new DateTimeImmutable(datetime: 'now');

$filter1 = new Filter([
    'property_name' => 'hs_lastmodifieddate',
    'operator' => 'GT',
    'value' => $now->sub(DateInterval::createFromDateString('30 days'))->getTimestamp(),
]);

$filterGroup1 = new FilterGroup([
    'filters' => [$filter1]
]);

$limit = 100;

$sorts = [
    [ // yes I know that this is inconsistent. It bugs me, too
        'propertyName' => 'hs_lastmodifieddate',
        'direction' => 'ASCENDING'
    ],
];

$properties = [
    "hs_object_id",
    "createdate",
    "dealname",
    "dealstage",
    "hs_is_closed_won",
    "hs_is_closed",
    "hs_lastmodifieddate",
    "hs_analytics_latest_source",
    "hs_analytics_latest_source_timestamp",
    "hs_analytics_source",
    "hs_analytics_source_data_1",
    "hs_analytics_source_data_2",
    "dealtype",
    "dealname",
    "closedate",
    "num_nomes",
    "days_to_close",
    "notes_last_updated",
    "notes_last_contacted"
];

$search = new PublicObjectSearchRequest();
$search->setFilterGroups([$filterGroup1]);
$search->setSorts($sorts);
$search->setLimit($limit);
$search->setProperties($properties);

$apiResponse = null; $results = [];
do {
    try {
        // call and append to results; handles pagination. 
        $apiResponse = $client->crm()->deals()->searchApi()->doSearch($search);
        foreach($apiResponse["results"] as $item) {
            $results[] = $item->getProperties();
        }

        if ($apiResponse["paging"] !== null) {
            $search->setAfter((int)$apiResponse["paging"]["next"]["after"]);
        }

        // rate limit is 4 requests per second
        // 250 000 microseconds = 0.25 seconds
        usleep(250000);

    } catch (ApiException $e) {
        echo "Exception when calling search_api->do_search: ", $e->getResponseObject();
        print_rt($results);
    }

} while ($apiResponse["paging"] !== null);

// build csv (in memory lmao)
$outputString = to_csv_headers($results[0]);
foreach($results as $line) {
    $outputString .= to_csv_row($line);
}

// push data to ftp server as csv
$attemptsLeft=10;
$sftp;
do {
    --$attemptsLeft;
    try {
        $sftp = establish_ftp();
    } catch (Exception $e) {
        echo $e->getResponseObject();
    }
} while ($attemptsLeft != 0 && !$sftp);

$sftp->put("Insurely-Deal-Date-{$now->format('d-M-Y.U')}.csv", $outputString);

// close out the connection
$sftp->reset();
// EOF--is there no explicit exit point?

// $_ENV vars are described in .env.example
// releases acquired resources on failure.
function establish_ftp() {
   $sftp = new SFTP($_ENV['FTP_HOST'], $_ENV['FTP_PORT']);
   $sftp->login($_ENV['FTP_USER'], $_ENV['FTP_PASS']);
   if (!$sftp) {
    $sftp->reset();
    throw Exception($sftp->getErrors());
   }
   return $sftp;
}

// logically identical to @to_csv_row except array_keys is used instead of values
// steps: 
//  1. rip keys from an array entry
//  2. reduce to comma-delimited string
//  3. delete the trailing comma
//  4. add a (windows-friendly) newline
function to_csv_headers(array $arr) {
    return substr(array_reduce(array_keys($arr), fn($prev, $curr) => $prev . $curr . ",", ""), 0, -1)."\r\n";
}

function to_csv_row(array $arr) {
    $values = array_values($arr);
    $csvString = array_reduce($values, fn($prev, $curr) => $prev . $curr . ",", "");
    return substr($csvString, 0, -1)."\r\n";
}

// source: https://stackoverflow.com/a/72550192/13422006
function print_rt($obj, $spaces="  ", $return=false) {
    /* © 2022 Peter Kionga-Kamau. Free for unrestricted use.
       Notes: - Not concerned about performance here since print_r is a debugging tool 
              - Single preg_replace() will substitute spaces in contents, hence the loop.
    */ 
    $out = explode("\n",print_r($obj,1));
    foreach ($out as $k=>$v) $out[$k] = preg_replace("/(( ){4})/", $spaces, substr($v,0,strlen($v)-strlen(ltrim($v)))).ltrim($v);
    if($return) return implode("\n",$out); echo implode("\n",$out);
}
?>