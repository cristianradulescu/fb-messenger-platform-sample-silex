<?php

require_once __DIR__.'/../vendor/autoload.php';

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

$app = new Application();
$app['debug'] = false;

$app->register(new Silex\Provider\MonologServiceProvider(), array(
    'monolog.logfile' => 'php://stderr',
    'monolog.level' => $app['debug'] == true
        ? \Monolog\Logger::DEBUG
        : \Monolog\Logger::INFO,
));

// App Secret can be retrieved from the App Dashboard
$app['app_secret'] = getenv('APP_SECRET')
    ? getenv('APP_SECRET')
    : '';

// Arbitrary value used to validate a webhook
$app['page_access_token'] = getenv('PAGE_ACCESS_TOKEN')
    ? getenv('PAGE_ACCESS_TOKEN')
    : '';

// Generate a page access token for your page from the App Dashboard
$app['validation_token'] = getenv('VALIDATION_TOKEN')
    ? getenv('VALIDATION_TOKEN')
    : '';

// URL where the app is running (include protocol). Used to point to scripts and
// assets located at this address.
$app['server_url'] = getenv('SERVER_URL')
    ? getenv('SERVER_URL')
    : '';

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
$app->post('/webhook', function (Application $app, Request $request) {
    $data = $request->request->all();

    // payload
/**
{
  "object": "page",
  "entry": [
    {
      "id": "280849715632589",
      "time": 1473529956066,
      "messaging": [
        {
          "sender": {
            "id": "1139262466132185"
          },
          "recipient": {
            "id": "280849715632589"
          },
          "timestamp": 1473529955951,
          "message": {
            "mid": "mid.1473529955943:f3fc08bd26f6a28680",
            "seq": 165,
            "text": "test-20:52"
          }
        }
      ]
    }
  ]
}
**/

    $app['monolog']->addInfo('====== REQUEST ===== ');
    $app['monolog']->addInfo((string) $request);
    $app['monolog']->addInfo('==================== ');
    $app['monolog']->addInfo('====== REQUEST->content ===== ');
    $app['monolog']->addInfo(var_export($request->getContent(), true));
    $app['monolog']->addInfo('==================== ');
    $app['monolog']->addInfo('====== POST params ===== ');
    $app['monolog']->addInfo(var_export($data, true));
    $app['monolog']->addInfo('======================== ');

    // Make sure this is a page subscription
    if (!isset($data['object']) || 'page' != $data['object']) {
      return new Response();
    }

    // Assume all went well.
    //
    // You must send back a 200, within 20 seconds, to let us know you've
    // successfully received the callback. Otherwise, the request will time out.
    return new Response();
});

$app->run();
