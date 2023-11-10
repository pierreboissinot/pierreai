<?php

require_once 'functions/gitlab_functions.php';

// Requires OPENAI_API_TOKEN OpenAI API secret key
// Requires SLACK_BOT_USER_OAUTH_TOKEN

// TODO: handle rate limit ? sleep ?
// response has to be less than 3 seconds, see headers like X-Slack-Retry-Num
foreach (getallheaders() as $name => $value) {
    if ('X-Slack-Retry-Num' === $name) {
        die('this long request is already running');
    }
}

$jsonData = file_get_contents('php://input');
//unlink('php://input');

//echo "jsonData" . $jsonData . "\n";

/*
echo json_encode([
    'challenge' => json_decode($jsonData, true)['challenge'],
]);
die();
*/
$slackEvent = json_decode($jsonData, true);
$userText = $slackEvent['event']['blocks'][0]['elements'][0]['elements'][1]['text'];
$botReplyMessage = $userText;
$channel = $slackEvent['event']['channel'];
$eventTs = $slackEvent['event']['ts'];


const gptModel = 'gpt-3.5-turbo-1106'; // supports parallels functions calling

function callbackSlack(string $responseUrl, string $content): void
{
    $url = $responseUrl;
    $curl = curl_init();

    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_FAILONERROR => false,
        CURLOPT_POSTFIELDS => $content,
    ));

    $response = curl_exec($curl);

    if (curl_errno($curl)) {
        throw new Exception(sprintf("Error during %s %s with body \n%s\nError : %s", 'POST', $url, $content, curl_error($curl)));
    }

    curl_close($curl);

    if (!is_string($response)) {
        die(sprintf('failure %s %s', 'POST', $url));
    }
}

// See https://api.slack.com/messaging/sending
function slackReplyInThread(string $channel, string $threadTs, string $message): void
{
    //echo "slackReplyInThread\n";
    $curl = curl_init();

    $url = 'https://slack.com/api/chat.postMessage';

    $body = [
        "channel" => $channel,
        "thread_ts" => $threadTs,
        "text" => $message,
    ];

    $payload = json_encode($body);

    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_FAILONERROR => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => array(
            sprintf("Authorization: Bearer %s", getenv('SLACK_BOT_USER_OAUTH_TOKEN')),
            "Content-Type: application/json",
        ),
    ));

    $response = curl_exec($curl);
    //var_dump($response);

    if (curl_errno($curl)) {
        throw new Exception(sprintf("Error during %s %s with body \n%s\nError : %s", 'POST', $url, $payload, curl_error($curl)));
    }

    curl_close($curl);

    if (!is_string($response)) {
        die(sprintf('failure %s %s', 'POST', $url));
    }
}

function handleOpenAiRequest(string $path, string $method = 'GET', string $body = null): string
{
    //echo "openAiRequest with payload: \n";
    //echo json_encode($body, JSON_PRETTY_PRINT);
    $url = sprintf("https://api.openai.com/v1%s", $path);
    $openAiApiToken = getenv('OPENAI_API_TOKEN');
    $curl = curl_init();

    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => $method,
        //CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        //CURLOPT_USERPWD => sprintf(":%s", $openAiApiToken),
        CURLOPT_HTTPHEADER => array(
            sprintf("Authorization: Bearer %s", $openAiApiToken),
            "Content-Type: application/json",
        ),
        CURLOPT_FAILONERROR => false,
    ));
    if ($body) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }

    $response = curl_exec($curl);

    if (curl_errno($curl)) {
        throw new Exception(sprintf("Error during %s %s with body \n%s\nError : %s", $method, $url, $body, curl_error($curl)));
    }

    curl_close($curl);

    if (!is_string($response)) {
        die(sprintf('failure %s %s', $method, $url));
    }

    return $response;
}

// See https://openai.com/blog/function-calling-and-other-api-updates
function buildPayload(array $messages): array {
    return
    [
        "model" => gptModel,
        "messages" => $messages,
        "functions" => [
            [
                "name" => "get_mr_to_review",
                "description" => "Liste les MR Gitlab à relire",
// par branche/lot de livraison ou projet ou auteur
                "parameters" => [
                    "type" => "object",
                    "properties" => [
                        "branch" => [
                            "type" => "string",
                            "description" => "Une branche git, par exemple s47"
                        ],
                        "author" => [
                            "type" => "string",
                            "description" => "L'auteur de la merge request, par exemple Pierre Boissinot"
                        ],
                        "projet" => [
                            "type" => "string",
                            "description" => "Le projet concerné par la merge request, par exemple espace-client"
                        ],
                    ],
                    "required" => [],
                ]
            ]
        ]
    ];
}


// Slack command timeout if the api does not respond in less than 3000ms
// See https://api.slack.com/interactivity/slash-commands#responding_basic_receipt

// TODO: get user input
//$userInput = $_POST['text'];// example: "Quelles sont les MR à relire concernant la branche s47 ?";
$userInput = $userText;

$payload = json_encode(buildPayload(
    [
        ["role" => "user", "content" => $userInput]
    ]
), JSON_PRETTY_PRINT);

// var_dump($payload);

// Call the model with functions and the user’s input
$response = handleOpenAiRequest("/chat/completions", "POST", $payload);

// var_dump($response);

// TODO: handle http response status, response body etc.

// TODO: check function name
$functionCall = json_decode($response, true)['choices'][0]['message']['function_call'];
$functionCalledByUser = $functionCall['name'];
$arguments= json_decode($functionCall['arguments'], true);


// var_dump($arguments['branch']);

// Send the response back to the model to summarize

// TODO: call the right gitlab function using magic

$gitlabReponse = gitlabGetMrToReview($arguments['branch']);

$secondPayload = json_encode(buildPayload(
    [
        ["role" => "user", "content" => $userInput],
        ["role" => "assistant", "content" => null, "function_call" => [
            "name" => $functionCalledByUser,
            "arguments" => json_encode($arguments),
            ]
        ],
        ["role" => "function", "name" => $functionCalledByUser, "content" =>  $gitlabReponse]
    ]
), JSON_PRETTY_PRINT);

// var_dump($secondPayload);

// Call the model with functions and the user’s input
try {
    $secondResponse = handleOpenAiRequest("/chat/completions", "POST", $secondPayload);
} catch (Exception $e) {
    die('erreur');
}

// var_dump($secondResponse);

$data = json_decode($secondResponse, true);

/*
$slackResponse = [
    "response_type" => "in_channel",
    "text" => $data['choices']['0']['message']['content'],
];
*/
$slackResponse = $data['choices']['0']['message']['content'];




// callbackSlack($_POST['response_url'], json_encode($slackResponse));

slackReplyInThread($channel, $eventTs, $slackResponse);


die('fin');

//echo json_encode($slackResponse);

// var_dump($secondResponse);
