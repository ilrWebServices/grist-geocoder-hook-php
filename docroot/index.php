<?php

use Geocoder\Provider\Chain\Chain;
use Geocoder\Provider\GoogleMaps\GoogleMaps;
use Geocoder\Provider\Mapbox\Mapbox;
use Geocoder\Query\GeocodeQuery;
use GuzzleHttp\Client as GuzzleClient;
use Geocoder\Provider\Nominatim\Nominatim;
use Geocoder\ProviderAggregator;

require __DIR__ . '/../vendor/autoload.php';

$access_token = get_env_setting(name: 'ACCESS_TOKEN', required: TRUE);
$grist_base_url = get_env_setting(name: 'GRIST_BASE_URL', required: TRUE);
$grist_access_token = get_env_setting(name: 'GRIST_ACCESS_TOKEN', required: TRUE);
$grist_document = get_env_setting(name: 'GRIST_DOCUMENT', required: TRUE);
$grist_table = get_env_setting(name: 'GRIST_TABLE', required: TRUE, fallback: 'Locations');
$nominatim_user_agent = get_env_setting(name: 'NOMINATIM_USER_AGENT', required: TRUE);
$nominatim_referer = get_env_setting(name: 'NOMINATIM_REFERER', required: TRUE);
$mapbox_api_key = get_env_setting(name: 'MAPBOX_API_KEY', required: TRUE);
$google_maps_geocoder_api_key = get_env_setting(name: 'GOOGLE_MAPS_GEOCODER_API_KEY');
$headers = getallheaders();

if (empty($headers['Authorization'])) {
  http_response_code(500);
  error_log('Missing Authorization header.');
  return;
}

if ($headers['Authorization'] !== 'Bearer ' . $access_token) {
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

error_log(sprintf('Webhook sent %d record(s).', count($data)));

$guzzle_client = new GuzzleClient();
$geocoder = new ProviderAggregator();
$chain = new Chain([
  new Mapbox($guzzle_client, $mapbox_api_key),
  Nominatim::withOpenStreetMapServer($guzzle_client, $nominatim_user_agent, $nominatim_referer),
]);

if ($google_maps_geocoder_api_key) {
  $chain->add(new GoogleMaps($guzzle_client, null, $google_maps_geocoder_api_key));
}

$geocoder->registerProvider($chain);
$grist_client = new GuzzleClient([
  'base_uri' => $grist_base_url,
  'headers' => [
    'Authorization' => "Bearer " . $grist_access_token
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

function get_env_setting(string $name, bool $required = FALSE, string $fallback = ''): string {
  $env_val = getenv($name);

  if ($env_val === false) {
    $env_val = $fallback;
  }

  if ($required && $env_val === '') {
    error_log(sprintf('Missing required variable %s.', $name));
    exit;
  }

  return $env_val;
}
