<?php

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../config/default.php';

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

$app = new Silex\Application();
$app['debug'] = true;

$app->get('/', function () {
    return 'URL list here';
});

$app->get('/webhook/', function (Request $request) {
    if ('subscribe' === $request->get('hub_mode') &&
      VALIDATION_TOKEN === $request->get('hub_verify_token')) {
        return new Response($request->get('hub_challenge'));
    }

    return new AccessDeniedHttpException(
        'Failed validation. Make sure the validation tokens match.'
    );
});

$app->run();
