<?php

require_once __DIR__.'/../vendor/autoload.php';

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

$app = new Application();
$app['debug'] = true;

$app->register(new Silex\Provider\MonologServiceProvider(), array(
    'monolog.logfile' => 'php://stderr',
    'monolog.level' => $app['debug'] == true
        ? \Monolog\Logger::DEBUG
        : \Monolog\Logger::INFO,
));

$app->register(new Silex\Provider\TwigServiceProvider(), array(
    'twig.path' => __DIR__.'/../views',
));

$app->before(function (Request $request, Application $app) {
    verifyRequestSignature($app, $request);
});

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
$app->post('/webhook/', function (Application $app, Request $request) {
    $data = json_decode($request->getContent(), true);
    $app['monolog']->addInfo('Request data: '.$request->getContent());

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
                receivedAuthentication($messagingEvent, $app);
            } elseif (isset($messagingEvent['message'])) {
                receivedMessage($messagingEvent, $app);
            } elseif (isset($messagingEvent['delivery'])) {
                receivedDeliveryConfirmation($messagingEvent, $app);
            } elseif (isset($messagingEvent['postback'])) {
                receivedPostback($messagingEvent, $app);
            } elseif (isset($messagingEvent['read'])) {
                receivedMessageRead($messagingEvent, $app);
            } elseif (isset($messagingEvent['account_linking'])) {
                receivedMessageRead($messagingEvent, $app);
            } else {
                $app['monolog']->addInfo(
                    'Webhook received unknown messagingEvent: '.
                    var_export($messagingEvent, true)
                );
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
 * Verify that the callback came from Facebook. Using the App Secret from
 * the App Dashboard, we can verify the signature that is sent with each
 * callback in the x-hub-signature field, located in the header.
 *
 * https://developers.facebook.com/docs/graph-api/webhooks#setup
 *
 */
function verifyRequestSignature(Application $app, Request $request) {
    $signature = $request->headers->get('x-hub-signature');
    $app['monolog']->addInfo('X-Hub-Signature: '.$signature);

    if (null === $signature) {
        // For testing, let's log an error. In production, you should throw an
        // error.
        $app['monolog']->addError("Couldn't validate the signature.");

        return;
    }

    $elements = explode('=', $signature);
    $method = $elements[0];
    $signatureHash = $elements[1];

    $expectedHash = hash_hmac(
        'sha1',
        (string) $request->getContent(),
        $app['app_secret']
    );

    if ($signatureHash != $expectedHash) {
        throw new Exception("Couldn't validate the request signature.");
    }

    $app['monolog']->addInfo('Request signature was successfully validated');
}

/*
 * Authorization Event
 *
 * The value for 'optin.ref' is defined in the entry point. For the "Send to
 * Messenger" plugin, it is the 'data-ref' field. Read more at
 * https://developers.facebook.com/docs/messenger-platform/webhook-reference/authentication
 *
 */
function receivedAuthentication($event, Application $app) {
    $senderId = $event['sender']['id'];
    $recipientId = $event['recipient']['id'];
    $timeOfAuth = $event['timestamp'];

    // The 'ref' field is set in the 'Send to Messenger' plugin, in the 'data-ref'
    // The developer can set this to an arbitrary value to associate the
    // authentication callback with the 'Send to Messenger' click event. This is
    // a way to do account linking when the user clicks the 'Send to Messenger'
    // plugin.
    $passTroughParam = $event['optin']['ref'];

    $app['monolog']->addInfo(
        sprintf(
            'Received authentication for user %s and page %s with pass '.
            'through param "%s" at %s',
            $senderId,
            $recipientId,
            $passThroughParam,
            $timeOfAuth
        )
    );

    // When an authentication is received, we'll send a message back to the sender
    // to let them know it was successful.
    sendTextMessage($senderId, 'Authentication successful', $app);
}

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
function receivedMessage($event, Application $app) {
    $senderId = $event['sender']['id'];
    $recipientId = $event['recipient']['id'];
    $timeOfMessage = $event['timestamp'];
    $message = $event['message'];

    $app['monolog']->addInfo(
        sprintf(
            'Received message for user %s and page %s at %s with message:',
            $senderId,
            $recipientId,
            $timeOfMessage
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
            sprintf(
                'Received echo for message %s and app %s with metadata %s',
                $messageId,
                $appId,
                $metadata
            )
        );
        return;
    }

    if ($quickReply) {
        $quickReplyPayload = isset($quickReply['payload']) ? $quickReply['payload'] : '';
        $app['monolog']->addInfo(
            sprintf(
                'Quick reply for message %s with payload %s',
                $messageId,
                $quickReplyPayload
            )
        );

        sendTextMessage($senderId, 'Quick reply tapped', $app);
        return;
    }

    if ($messageText) {
        // If we receive a text message, check to see if it matches any special
        // keywords and send back the corresponding example. Otherwise, just echo
        // the text we received.
        switch ($messageText) {
            case 'image':
                sendImageMessage($senderId, $app);
                break;

            case 'gif':
                sendGifMessage($senderId, $app);
                break;

            case 'audio':
                sendAudioMessage($senderId, $app);
                break;

            case 'video':
                sendVideoMessage($senderId, $app);
                break;

            case 'file':
                sendFileMessage($senderId, $app);
                break;

            case 'button':
                sendButtonMessage($senderId, $app);
                break;

            case 'generic':
                sendGenericMessage($senderId, $app);
                break;

            case 'receipt':
                // sendReceiptMessage($senderId, $app);
                // break;

            case 'quick reply':
                // sendQuickReply($senderId, $app);
                // break;

            case 'read receipt':
                // sendReadReceipt($senderId, $app);
                // break;

            case 'typing on':
                // sendTypingOn($senderId, $app);
                // break;

            case 'typing off':
                // sendTypingOff($senderId, $app);
                // break;

            case 'account linking':
                // sendAccountLinking($senderId, $app);
                // break;

            default:
                sendTextMessage($senderId, $messageText, $app);
        }
    } elseif ($messageAttachments) {
        sendTextMessage($senderId, 'Message with attachment received', $app);
    }
}

/*
 * Delivery Confirmation Event
 *
 * This event is sent to confirm the delivery of a message. Read more about
 * these fields at https://developers.facebook.com/docs/messenger-platform/webhook-reference/message-delivered
 *
 */
function receivedDeliveryConfirmation($event, Application $app) {
    $senderId = $event['sender']['id'];
    $recipientId = $event['recipient']['id'];
    $delivery = $event['delivery'];
    $messageIds = isset($delivery['mids']) ? $delivery['mids'] : array();
    $watermark = $delivery['watermark'];
    $sequenceNumber= $delivery['seq'];

    foreach ($messageIds as $messageId) {
        $app['monolog']->addInfo(
            sprintf(
                'Received delivery confirmation for message ID: %s',
                $messageId
            )
        );
    }

    $app['monolog']->addInfo(
        sprintf(
            'All message before %s were delivered.',
            $watermark
        )
    );
}

/*
 * Postback Event
 *
 * This event is called when a postback is tapped on a Structured Message.
 * https://developers.facebook.com/docs/messenger-platform/webhook-reference/postback-received
 *
 */
function receivedPostback($event, Application $app) {
    $senderId = $event['sender']['id'];
    $recipientId = $event['recipient']['id'];
    $timeOfPostback = $event['timestamp'];

    // The 'payload' param is a developer-defined field which is set in a postback
    // button for Structured Messages.
    $payload = $event['postback']['payload'];

    $app['monolog']->addInfo(
        sprintf(
            'Received postback for user %s and page %s with payload "%s" at %s',
            $senderId,
            $recipientId,
            $payload,
            $timeOfPostback
        )
    );

    // When a postback is called, we'll send a message back to the sender to
    // let them know it was successful
    sendTextMessage($senderId, 'Postback called', $app);
}

/*
 * Message Read Event
 *
 * This event is called when a previously-sent message has been read.
 * https://developers.facebook.com/docs/messenger-platform/webhook-reference/message-read
 *
 */
function receivedMessageRead($event, Application $app) {
    $senderId = $event['sender']['id'];
    $recipientId = $event['recipient']['id'];

    // All messages before watermark (a timestamp) or sequence have been seen.
    $watermark = $event['read']['watermark'];
    $sequenceNumber= $event['read']['seq'];

    $app['monolog']->addInfo(
        sprintf(
            'Received message read event for watermark %s and sequence '.
            'number %s',
            $watermark,
            $sequenceNumber
        )
    );
}

/*
 * Account Link Event
 *
 * This event is called when the Link Account or UnLink Account action has been
 * tapped.
 * https://developers.facebook.com/docs/messenger-platform/webhook-reference/account-linking
 *
 */
function receivedAccountLink($event, Application $app) {
    $senderId = $event['sender']['id'];
    $recipientId = $event['recipient']['id'];

    $status = $event['account_linking']['status'];
    $authCode = $event['account_linking']['authorization_code'];

    $app['monolog']->addInfo(
        sprintf(
            'Received account link event with for user %s with status %s '.
            'and auth code %s ',
            $senderId,
            $status,
            $authCode
        )
    );
}

/*
 * Send an image using the Send API.
 *
 */
function sendImageMessage($recipientId, Application $app) {
    $messageData = array(
        'recipient' => array(
            'id' => $recipientId
        ),
        'message' => array(
            'attachment' => array(
                'type' => 'image',
                'payload' => array(
                    'url' => $app['server_url'].'/assets/rift.png'
                )
            )
        )
    );

    callSendApi($messageData, $app);
}

/*
 * Send a Gif using the Send API.
 *
 */
function sendGifMessage($recipientId, Application $app) {
    $messageData = array(
        'recipient' => array(
            'id' => $recipientId
        ),
        'message' => array(
            'attachment' => array(
                'type' => 'image',
                'payload' => array(
                    'url' => $app['server_url'].'/assets/instagram_logo.gif'
                )
            )
        )
    );

    callSendApi($messageData, $app);
}

/*
 * Send audio using the Send API.
 *
 */
function sendAudioMessage($recipientId, Application $app) {
    $messageData = array(
        'recipient' => array(
            'id' => $recipientId
        ),
        'message' => array(
            'attachment' => array(
                'type' => 'audio',
                'payload' => array(
                    'url' => $app['server_url'].'/assets/sample.mp3'
                )
            )
        )
    );

    callSendApi($messageData, $app);
}

/*
 * Send a video using the Send API.
 *
 */
function sendVideoMessage($recipientId, Application $app) {
    $messageData = array(
        'recipient' => array(
            'id' => $recipientId
        ),
        'message' => array(
            'attachment' => array(
                'type' => 'video',
                'payload' => array(
                    'url' => $app['server_url'].'/assets/allofus480.mov'
                )
            )
        )
    );

    callSendApi($messageData, $app);
}

/*
 * Send a video using the Send API.
 *
 */
function sendFileMessage($recipientId, Application $app) {
    $messageData = array(
        'recipient' => array(
            'id' => $recipientId
        ),
        'message' => array(
            'attachment' => array(
                'type' => 'file',
                'payload' => array(
                    'url' => $app['server_url'].'/assets/text.txt'
                )
            )
        )
    );

    callSendApi($messageData, $app);
}

/*
 * Send a text message using the Send API.
 *
 */
function sendTextMessage($recipientId, $messageText, Application $app) {
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
 * Send a button message using the Send API.
 *
 */
function sendButtonMessage($recipientId, Application $app) {
    $messageData = array(
        'recipient' => array(
            'id' => $recipientId
        ),
        'message' => array(
            'attachment' => array(
                'type' => 'template',
                'payload' => array(
                    'template_type' => 'button',
                    'text' => 'This is a test text',
                    'buttons' => array(
                        array(
                            'type' => 'web_url',
                            'url' => 'https://www.oculus.com/en-us/rift/',
                            'title' => 'Open Web URL'
                        ),
                        array(
                            'type' => 'postback',
                            'title' => 'Trigger Postback',
                            'payload' => 'DEVELOPED_DEFINED_PAYLOAD'
                        ),
                        array(
                            'type' => 'phone_number',
                            'title' => 'Call Phone Number',
                            'payload' => '+16505551234'
                        )
                    )
                )
            )
        )
    );

    callSendApi($messageData, $app);
}


/*
 * Send a Structured Message (Generic Message type) using the Send API.
 *
 */
function sendGenericMessage($recipientId, Application $app) {
    $messageData = array(
        'recipient' => array(
            'id' => $recipientId
        ),
        'message' => array(
            'attachment' => array(
                'type' => 'template',
                'payload' => array(
                    'template_type' => 'generic',
                    'elements' => array(
                        array(
                            'title' => 'rift',
                            'subtitle' => 'Next-generation virtual reality',
                            'item_url' => 'https://www.oculus.com/en-us/rift/',
                            'image_url' => $app['server_url'].'/assets/rift.png',
                            'buttons' => array(
                                array(
                                    'type' => 'web_url',
                                    'url' => 'https://www.oculus.com/en-us/rift/',
                                    'title' => 'Open Web URL'
                                ),
                                array(
                                    'type' => 'postback',
                                    'title' => 'Call Postback',
                                    'payload' => 'Payload for first bubble'
                                ),
                            )
                        ),
                        array(
                            'title' => 'touch',
                            'subtitle' => 'Your Hands, Now in VR',
                            'item_url' => 'https://www.oculus.com/en-us/touch/',
                            'image_url' => $app['server_url'].'/assets/touch.png',
                            'buttons' => array(
                                array(
                                    'type' => 'web_url',
                                    'url' => 'https://www.oculus.com/en-us/touch/',
                                    'title' => 'Open Web URL'
                                ),
                                array(
                                    'type' => 'postback',
                                    'title' => 'Call Postback',
                                    'payload' => 'Payload for second bubble'
                                ),
                            )
                        )
                    )
                )
            )
        )
    );

    callSendApi($messageData, $app);
}

/*
 * Call the Send API. The message data goes in the body. If successful, we'll
 * get the message id in a response
 *
 */
 function callSendApi($messageData, Application $app) {

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
