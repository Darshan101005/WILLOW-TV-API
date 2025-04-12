<?php
require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;

define('WILLOW_API_URL', 'https://willowfeedsv2.willow.tv/willowds/home_page_US.json');
define('USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/134.0.0.0 Safari/537.36');
define('MAX_RETRIES', 5);
define('RETRY_DELAY', 3);

function fetchData() {
    $client = new Client();
    for ($attempt = 0; $attempt < MAX_RETRIES; $attempt++) {
        try {
            $response = $client->get(WILLOW_API_URL, ['headers' => ['User-Agent' => USER_AGENT]]);
            return json_decode($response->getBody(), true);
        } catch (Exception $e) {
            if ($attempt < MAX_RETRIES - 1) sleep(RETRY_DELAY);
        }
    }
    return null;
}

function getFinalM3u8Url($initialUrl) {
    $client = new Client();
    for ($attempt = 0; $attempt < MAX_RETRIES; $attempt++) {
        try {
            $response = $client->get($initialUrl, ['headers' => ['User-Agent' => USER_AGENT], 'timeout' => 10]);
            $lines = explode("\n", (string)$response->getBody());
            $resolutions = [];
            for ($i = 0; $i < count($lines); $i++) {
                if (strpos($lines[$i], 'RESOLUTION=') !== false) {
                    $resolution = (int)explode(',', explode('x', $lines[$i])[1])[0];
                    $resolutions[] = ['resolution' => $resolution, 'path' => trim($lines[$i + 1])];
                }
            }
            usort($resolutions, function($a, $b) { return $b['resolution'] - $a['resolution']; });
            $parsed = parse_url($initialUrl);
            $pathParts = explode('/', $parsed['path']);
            $basePath = implode('/', array_slice($pathParts, 0, 3));
            $manifestPath = str_replace('../../../', '', $resolutions[0]['path']);
            return $parsed['scheme'] . '://' . $parsed['host'] . '/v1/' . $manifestPath;
        } catch (Exception $e) {
            if ($attempt < MAX_RETRIES - 1) sleep(RETRY_DELAY);
        }
    }
    return null;
}

function getM3u8Urls($eventId) {
    $client = new Client();
    $cookies = [
        'remember_token_30_days' => '2040826|ae3107d45738cce097dec06c78de24e64c11f486b94a068d021512b327133c03aeec9b40b80a2a65afcbcf2de6bad8d144913172d78ed6f7cbf68c3e333556d3',
        'authenticated' => 'True',
        'user_id' => '2040826',
    ];
    $headers = ['User-Agent' => USER_AGENT, 'x-requested-with' => 'XMLHttpRequest'];
    for ($attempt = 0; $attempt < MAX_RETRIES; $attempt++) {
        try {
            $response = $client->get('https://www.willow.tv/match_live_data_by_id', [
                'query' => ['matchid' => $eventId],
                'headers' => $headers,
                'cookies' => $cookies,
                'timeout' => 10
            ]);
            $data = json_decode($response->getBody(), true);
            $englishUrl = $hindiUrl = null;
            foreach ($data['result'] as $stream) {
                if ($stream['title'] == 'LIVE SOURCE ENGLISH') $englishUrl = getFinalM3u8Url($stream['secureurl']);
                if ($stream['title'] == 'LIVE SOURCE HINDI') $hindiUrl = getFinalM3u8Url($stream['secureurl']);
            }
            return [$englishUrl, $hindiUrl];
        } catch (Exception $e) {
            if ($attempt < MAX_RETRIES - 1) sleep(RETRY_DELAY);
        }
    }
    return [null, null];
}

function processEvent($event) {
    try {
        $status = ($event['IsMatchLive'] == 1 && $event['MatchStarted'] == "1") ? "LIVE" : "UPCOMING";
        $result = [
            "id" => $event["Id"],
            "name" => $event["Name"],
            "team_one_name" => $event["TeamOneName"],
            "team_two_name" => $event["TeamTwoName"],
            "team_one_image" => "https://aimages.willow.tv/teamLogos/" . $event['ImageTeamOne'] . ".png",
            "team_two_image" => "https://aimages.willow.tv/teamLogos/" . $event['ImageTeamTwo'] . ".png",
            "short_score" => $event["ShortScore"] ?? "",
            "status" => $status,
            "user_agent" => $status == "LIVE" ? USER_AGENT : ""
        ];
        if ($status == "LIVE") {
            list($englishUrl, $hindiUrl) = getM3u8Urls($event["Id"]);
            if ($englishUrl) $result["m3u8_eng_url"] = $englishUrl;
            if ($hindiUrl) $result["m3u8_hin_url"] = $hindiUrl;
        }
        return $result;
    } catch (Exception $e) {
        return null;
    }
}

function generateM3uPlaylist($liveEvents) {
    $playlist = ["#EXTM3U"];
    foreach ($liveEvents as $event) {
        if (isset($event["m3u8_eng_url"])) {
            $playlist[] = '#EXTINF:-1 tvg-logo="https://img.willow.tv/apps/wtv_logo_new_200_200.jpg",' . $event["name"] . ' (ENGLISH)';
            $playlist[] = '#EXTVLCOPT:http-user-agent=' . USER_AGENT;
            $playlist[] = $event["m3u8_eng_url"];
            $playlist[] = "";
        }
        if (isset($event["m3u8_hin_url"])) {
            $playlist[] = '#EXTINF:-1 tvg-logo="https://img.willow.tv/apps/wtv_logo_new_200_200.jpg",' . $event["name"] . ' (HINDI)';
            $playlist[] = '#EXTVLCOPT:http-user-agent=' . USER_AGENT;
            $playlist[] = $event["m3u8_hin_url"];
            $playlist[] = "";
        }
    }
    return implode("\n", $playlist);
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$data = fetchData();
if (!$data) {
    http_response_code(500);
    die(json_encode(['error' => 'Failed to fetch data']));
}

$liveEvents = [];
foreach ($data['live'] ?? [] as $event) {
    if ($processed = processEvent($event)) $liveEvents[] = $processed;
}

$upcomingEvents = [];
foreach ($data['upcoming'] ?? [] as $event) {
    if ($processed = processEvent($event)) $upcomingEvents[] = $processed;
}

$responseData = [
    "matches" => array_merge($liveEvents, $upcomingEvents),
    "last_updated" => (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s \G\M\T')
];

if (strpos($path, 'willow-tv.m3u') !== false) {
    header('Content-Type: audio/x-mpegurl');
    echo generateM3uPlaylist($liveEvents);
} elseif (strpos($path, 'willow-tv-fixtures.json') !== false) {
    header('Content-Type: application/json');
    echo json_encode($responseData, JSON_PRETTY_PRINT);
} else {
    header('Content-Type: application/json');
    echo json_encode($responseData, JSON_PRETTY_PRINT);
}
?>