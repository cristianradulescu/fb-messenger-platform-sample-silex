<?php

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../config/default.php';

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

$app = new Silex\Application();
$app['debug'] = true;

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
$app->get('/webhook/', function (Request $request) {
    if ('subscribe' === $request->get('hub_mode') &&
        VALIDATION_TOKEN === $request->get('hub_verify_token')) {
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
