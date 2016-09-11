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

$app->register(new Silex\Provider\TwigServiceProvider(), array(
    'twig.path' => __DIR__.'/../views',
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
    $data = json_decode($request->getContent(), true);
    $app['monolog']->addInfo('Request data: '. var_export($data, true));

    // Make sure this is a page subscription
    if (!isset($data['object']) || 'page' != $data['object']) {
      return new Response();
    }

    // Iterate over each entry
    // There may be multiple if batched
    foreach ($data['entry'] as $entry) {
      $pageId = $entry['id'];
      $timeOfEvent = $entry['time'];

      foreach ($entry['messaging'] as $messagingEvent) {
          if (isset($messagingEvent['optin'])) {
              // receivedAuthentication($messagingEvent, $app);
          } elseif (isset($messagingEvent['message'])) {
              receivedMessage($messagingEvent, $app);
          }
      }
    }

    // Assume all went well.
    //
    // You must send back a 200, within 20 seconds, to let us know you've
    // successfully received the callback. Otherwise, the request will time out.
    return new Response();
});

/*
 * This path is used for account linking. The account linking call-to-action
 * (sendAccountLinking) is pointed to this URL.
 *
 */
 $app->get('/authorize/', function(Application $app, Request $request) {
    $accountLinkingToken = $request->get('account_linking');
    $redirectURI = $request->get('redirect_uri');

    // Authorization Code should be generated per user by the developer. This will
    // be passed to the Account Linking callback.
    $authCode = '1234567890';

    // Redirect users to this URI on successful login
    $redirectURISuccess = $redirectURI.'&authorization_code='.$authCode;

    return $app['twig']->render('authorize.twig', array(
        'redirectURISuccess' => $redirectURISuccess,
        'accountLinkingToken' => $accountLinkingToken,
        'redirectURI' => $redirectURI
    ));

 });

/*
 * Message Event
 *
 * This event is called when a message is sent to your page. The 'message'
 * object format can vary depending on the kind of message that was received.
 * Read more at https://developers.facebook.com/docs/messenger-platform/webhook-reference/message-received
 *
 * For this example, we're going to echo any text that we get. If we get some
 * special keywords ('button', 'generic', 'receipt'), then we'll send back
 * examples of those bubbles to illustrate the special message bubbles we've
 * created. If we receive a message with an attachment (image, video, audio),
 * then we'll simply confirm that we've received the attachment.
 *
 */
function receivedMessage($event, $app) {
    $senderId = $event['sender']['id'];
    $recipientId = $event['recipient']['id'];
    $timeOfMessage = $event['timestamp'];
    $message = $event['message'];

    $app['monolog']->addInfo(
        sprintf(
            'Received message for user %s and page %s at %s with message:',
            array($senderId, $recipientId, $timeOfMessage)
        )
    );
    $app['monolog']->addInfo(
        json_encode($message)
    );

    $isEcho = isset($message['is_echo']) ? $message['is_echo'] : '';
    $messageId = isset($message['mid']) ? $message['mid'] : '';
    $appId = isset($message['app_id']) ? $message['app_id'] : '' ;
    $metadata = isset($message['metadata']) ? $message['metadata'] : '';

    // You may get a text or attachment but not both
    $messageText = isset($message['text']) ? $message['text'] : '';
    $messageAttachments = isset($message['attachments']) ? $message['attachments'] : '';
    $quickReply = isset($message['quick_reply']) ? $message['quick_reply'] : '';

    if ($isEcho) {
        // Just logging message echoes to console
        $app['monolog']->addInfo(
            'Received echo for message %s and app %s with metadata %s',
            array($messageId, $appId, $metadata)
        );
        return;
    }

    if ($quickReply) {
        $quickReplyPayload = isset($quickReply['payload']) ? $quickReply['payload'] : '';
        $app['monolog']->addInfo(
            'Quick reply for message %s with payload %s',
            array($messageId, $quickReplyPayload)
        );

        sendTextMessage($senderId, 'Quick reply tapped');
        return;
    }

    if ($messageText) {
        // If we receive a text message, check to see if it matches any special
        // keywords and send back the corresponding example. Otherwise, just echo
        // the text we received.
        switch ($messageText) {
            case 'image':
                // sendImageMessage($senderId);
                break;

            // case...

            default:
                sendTextMessage($senderId, $messageText, $app);
        }
    } elseif ($messageAttachments) {
        sendTextMessage($senderId, 'Message with attachment received', $app);
    }
}

/*
 * Send a text message using the Send API.
 *
 */
function sendTextMessage($recipientId, $messageText, $app) {
    $messageData = array(
        'recipient' => array(
            'id' => $recipientId
        ),
        'message' => array(
            'text' => $messageText,
            'metadata' => 'DEVELOPER_DEFINED_METADATA'
        )
    );

    callSendApi($messageData, $app);
}

/*
 * Call the Send API. The message data goes in the body. If successful, we'll
 * get the message id in a response
 *
 */
 function callSendApi($messageData, $app) {

    $app['monolog']->addInfo('Sending: '.var_export($messageData, true));

    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_URL => 'https://graph.facebook.com/v2.6/me/messages/?access_token='.$app['page_access_token'],
        CURLOPT_POST => 1,
        CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
        CURLOPT_POSTFIELDS => json_encode($messageData)
    ));

    $response = curl_exec($curl);
    curl_close($curl);

    $app['monolog']->addInfo(var_export($response, true));
 }

$app->run();
