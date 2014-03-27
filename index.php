<?php

require "livestatus_client.php";

ini_set('memory_limit', -1);
#ini_set('always_populate_raw_post_data', 1);

header('Content-Type: application/json');

$path_parts = explode('/', $_SERVER['PATH_INFO']);


$client = new LiveStatusClient('/var/nagios/var/rw/live');
$client->pretty_print = true;

/*
$commands = [
    'acknowledege_problem'
    'schedule_downtime',
    'enable_notifications',
    'disable_notifications'
];
*/

$method = $path_parts[1];

try {
    switch ($method) {

    case 'acknowledge_problem':
        $args = json_decode($HTTP_RAW_POST_DATA,true);
        $client->acknowledgeProblem($args);
        echo json_encode(['OK']);
        break;
       
    case 'schedule_downtime':
        echo json_encode(['OK']);
        break;

    case 'enable_notifications':
        echo json_encode(['OK']);
        break;

    case 'disable_notifications':
        echo json_encode(['OK']);
        break;

    default:
        echo $client->getQuery($method, $_GET);

    }
} catch (LiveStatusException $e) {
    http_response_code($e->getCode());
    echo $e->toJson();
}

?>
