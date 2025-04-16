<?php

use Geocoder\Provider\Chain\Chain;
use Geocoder\Provider\GoogleMaps\GoogleMaps;
use Geocoder\Provider\Mapbox\Mapbox;
use Geocoder\Query\GeocodeQuery;
use GuzzleHttp\Client as GuzzleClient;
use Geocoder\Provider\Nominatim\Nominatim;
use Geocoder\ProviderAggregator;

require __DIR__ . '/../vendor/autoload.php';

$headers = getallheaders();

if (empty($headers['Authorization'])) {
  http_response_code(500);
  error_log('Missing Authorization header.');
  return;
}

if ($headers['Authorization'] !== 'Bearer ' . getenv('ACCESS_TOKEN')) {
  http_response_code(500);
  error_log('Incorrect access token.');
  return;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!is_array($data)) {
  http_response_code(500);
  error_log('No json data array found.');
  return;
}

$grist_document = preg_replace('/[^A-Za-z0-9]+/', '', $_GET['grist_document'] ?? '');
$grist_table = preg_replace('/[^A-Za-z0-9]+/', '', $_GET['grist_table'] ?? 'Locations');

error_log(sprintf('Webhook sent %d record(s).', count($data)));

$guzzle_client = new GuzzleClient();
$geocoder = new ProviderAggregator();
$chain = new Chain([
  Nominatim::withOpenStreetMapServer($guzzle_client, 'ilrweb@cornell.edu'),
  new Mapbox($guzzle_client, getenv('MAPBOX_API_KEY')),
  new GoogleMaps($guzzle_client, null, getenv('GOOGLE_MAPS_GEOCODER_API_KEY')),
]);
$geocoder->registerProvider($chain);
$grist_client = new GuzzleClient([
  'base_uri' => getenv('GRIST_BASE_URL'),
  'headers' => [
    'Authorization' => "Bearer " . getenv('GRIST_ACCESS_TOKEN')
  ]
]);

foreach ($data as $record) {
  if (empty($record['id']) || empty($record['Address'])) {
    error_log(sprintf('Missing id or Address column in record %d.', $record['id']));
    continue;
  }
  error_log(sprintf('Attempting to geocode "%s" for record %d.', $record['Address'], $record['id']));
  $result = $geocoder->geocodeQuery(GeocodeQuery::create($record['Address']));

  if ($result->isEmpty()) {
    $latitude = '';
    $longitude = '';
    error_log(sprintf('No coordinates found.'));
  }
  else {
    $latitude = $result->first()->getCoordinates()->getLatitude();
    $longitude = $result->first()->getCoordinates()->getLongitude();
    error_log(sprintf('Found coordinates "%s" latitude and "%s" longitude via %s.', $latitude, $longitude, $result->first()->getProvidedBy()));
  }

  try {
    $update_data['records'][] = [
      'id' => $record['id'],
      'fields' => [
        'Latitude' => $latitude,
        'Longitude' => $longitude,
      ],
    ];
    $grist_api_path = sprintf('o/docs/api/docs/%s/tables/%s/records', $grist_document, $grist_table);
    $response = $grist_client->request('PATCH', $grist_api_path, ['json' => $update_data]);
    error_log(sprintf('Updated record %d.', $record['id']));
  }
  catch (\Exception $e) {
    http_response_code(500);
    error_log($e->getMessage());
  }
}
