<?php

// Requires OPENAI_API_TOKEN OpenAI API secret key

// TODO: handle rate limit ? sleep ?

include './functions/gitlab_functions.php';

const gptModel = 'gpt-3.5-turbo-1106'; // supports parallels functions calling

function handleOpenAiRequest(string $path, string $method = 'GET', string $body = null): string
{
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

// TODO: get user input
$userInput = "Quelles sont les MR à relire concernant la branche s47 ?";

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
$secondResponse = handleOpenAiRequest("/chat/completions", "POST", $secondPayload);

// var_dump($secondResponse);

$data = json_decode($secondResponse, true);

echo $data['choices']['0']['message']['content'];

// var_dump($secondResponse);
