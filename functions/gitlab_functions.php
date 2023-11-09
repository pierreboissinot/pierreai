<?php

// Uses access token GITLAB_API_TOKEN declared in a personal profile. We can't use a group access token.
// This token requires scopes reada_api

// TODO: handle rate limit ? sleep ?
const GITLAB_GROUP_ID = '66590646';
function handleGitlabGroupRequest(string $path, string $method = 'GET', string $body = null, ?string $accessToken = null): string
{
    $url = sprintf('https://gitlab.com/api/v4%s', $path);
    $gitlabToken = $accessToken ?? getenv('GITLAB_API_TOKEN');
    //echo sprintf("Using %s token\n", $gitlabToken);
    $curl = curl_init();

    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => array(
            // TODO: use env variable
            sprintf('Authorization: Bearer %s', $gitlabToken),
            "Content-Type: application/json",
        ),
        CURLOPT_FAILONERROR => true,
    ));
    if ($body) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }

    $response = curl_exec($curl);

    if(curl_errno($curl)) {
        throw new Exception(sprintf("Error during %s %s with body \n%s\nError : %s", $method, $url, $body, curl_error($curl)));
    }

    curl_close($curl);
    if (!is_string($response)) {
        die(sprintf('failure %s %s', $method, $url));
    }
    return $response;
}

function gitlabGetMrToReview(string $branch): string {
    $openedMrResponse = handleGitlabGroupRequest(sprintf("/groups/%s/merge_requests?target_branch=%s&state=opened&draft=false&work_in_progress=false", GITLAB_GROUP_ID, $branch));
    $openedMr = json_decode($openedMrResponse, true);

// sort $openedMr by created_date
    usort($openedMr, function ($a, $b) {
        return strtotime($a['created_at']) - strtotime($b['created_at']);
    });



    if (empty($openedMr)) {
        return sprintf("Je n'ai trouvé aucune MR opened concernant la branche %s\n", $branch);
    } else {
        $openedMrFormatted = array_map(function ($mr) {
            return [
                "titre" => $mr['title'],
                "description" => $mr['description'],
                "url" => $mr['web_url']
                ];
        }, $openedMr);

        return json_encode($openedMrFormatted);
    }

// order by created_date asc
// TODO/ show has conflict, blocking_discussions_resolved, description
}
