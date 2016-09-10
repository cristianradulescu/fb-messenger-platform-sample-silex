<?php

require_once __DIR__.'/../vendor/autoload.php';

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

$app = new Application();
$app['debug'] = true;

// App Secret can be retrieved from the App Dashboard
$app['app_secret'] = getenv('APP_SECRET') ?
    getenv('APP_SECRET') : '';

// Arbitrary value used to validate a webhook
$app['page_access_token'] = getenv('PAGE_ACCESS_TOKEN') ?
    getenv('PAGE_ACCESS_TOKEN') : '';

// Generate a page access token for your page from the App Dashboard
$app['validation_token'] = getenv('VALIDATION_TOKEN') ?
    getenv('VALIDATION_TOKEN') : '';

// URL where the app is running (include protocol). Used to point to scripts and
// assets located at this address.
$app['server_url'] = getenv('SERVER_URL') ?
    getenv('SERVER_URL') : '';

/**
 * @todo What to do here?
 */
$app->get('/', function () {
    return 'It works!?!';
});

/*
 * Use your own validation token. Check that the token used in the Webhook
 * setup is the same token used here.
 *
 */
$app->get('/webhook/', function (Application $app, Request $request) {
    if ('subscribe' === $request->get('hub_mode') &&
        $app['validation_token'] === $request->get('hub_verify_token')) {
            return new Response($request->get('hub_challenge'));
    }

    return new AccessDeniedHttpException(
        'Failed validation. Make sure the validation tokens match.'
    );
});

/*
 * All callbacks for Messenger are POST-ed. They will be sent to the same
 * webhook. Be sure to subscribe your app to your page to receive callbacks
 * for your page.
 * https://developers.facebook.com/docs/messenger-platform/product-overview/setup#subscribe_app
 *
 */
$app->post('/webhook/', function (Request $request) {
    return new Response('TO DO');
});

$app->run();
