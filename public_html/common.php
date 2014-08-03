<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('memory_limit', '512M');

require('../vendor/autoload.php');
#require('../oauth.php');
#require('../BorderLocator.php');


/*************************************************
 * Routing
 *************************************************/

$hostname = isset($_SERVER['HTTP_X_FORWARDED_SERVER'])
                ? $_SERVER['HTTP_X_FORWARDED_SERVER']
                : $_SERVER['SERVER_NAME'];

if ($hostname == 'tools.wmflabs.org, tools-eqiad.wmflabs.org' || $hostname == 'tools-eqiad.wmflabs.org') {
    $hostname = 'tools.wmflabs.org';
}

$basepath = dirname( $_SERVER['SCRIPT_NAME'] );
$testingEnv = ($hostname !== 'tools.wmflabs.org');

session_name('croptool');
session_set_cookie_params(0, $basepath, $hostname);
session_start();

if (isset($_GET['title'])) {
    // Store the title, so we can retrieve if after
    // having having authenticated at the OAuth endpoint
    $_SESSION['title'] = $_GET['title'];
}

/**
 * A file containing the following keys:
 * - consumerKey: The "consumer token" given to you when registering your app
 * - consumerSecret: The "secret token" given to you when registering your app
 * - localPassphrase: The (base64 encoded) key used for encrypting cookie content
 * - jpegtranPath: Path to jpegtran
 */
$configFile = '../config.ini';
$config = parse_ini_file($configFile);

if ( $config === false ) {
    header( "HTTP/1.1 500 Internal Server Error" );
    echo 'The ini file could not be read';
    exit(0);
}

if (!isset( $config['consumerKey'] ) || !isset( $config['consumerSecret'] )) {
    header( "HTTP/1.1 500 Internal Server Error" );
    echo 'Required configuration directives not found in ini file';
    exit(0);
}

Image::$pathToJpegTran = $config['jpegtranPath'];

$site = isset($_GET['site']) ? $_GET['site'] : 'no.wikipedia.org';

$oauth = new OAuthConsumer($site, $hostname, $basepath, $testingEnv, $config['consumerKey'], $config['consumerSecret'], $config['localPassphrase']);
