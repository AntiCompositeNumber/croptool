<?php

require_once('../bootstrap.php');

if (isset($_GET['title'])) {
    // Store the title, so we can retrieve if after
    // having having authenticated at the OAuth endpoint
    $_SESSION['title'] = $_GET['title'];
}

if ($_SERVER['REQUEST_METHOD'] == 'POST')
{
    $input = json_decode(file_get_contents("php://input"));
    $site = isset($input->site)
        ? $input->site
        : 'en.wikipedia.org'; // use enwp as default to force re-authorization for 1.1 users
} else {
    $site = isset($_REQUEST['site'])
        ? $_REQUEST['site']
        : 'en.wikipedia.org'; // use enwp as default to force re-authorization for 1.1 users
}

$apiClient = new MwApiClient($site, $oauth, null, $log, $config);
$controller = new CropToolController($apiClient, $log);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $controller->handleRequest('POST', json_decode(file_get_contents('php://input')));
} else {
    $controller->handleRequest('GET', $_GET);
}
