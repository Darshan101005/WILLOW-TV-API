<?php
require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\RequestException;

define('WILLOW_API_URL', 'https://willowfeedsv2.willow.tv/willowds/home_page_US.json');
define('USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/134.0.0.0 Safari/537.36');
define('MAX_RETRIES', 5);
define('RETRY_DELAY', 3);

function fetchData() {
    $client = new Client();
    for ($attempt = 0; $attempt < MAX_RETRIES; $attempt++) {
        try {
            $response = $client->get(WILLOW_API_URL, [
                'headers' => ['User-Agent' => USER_AGENT],
                'timeout' => 10,
                'connect_timeout' => 5
            ]);
            $body = $response->getBody()->getContents();
            // Extract JSON from response (mimicking Python's extract_json)
            $start = strpos($body, '{');
            $end = strrpos($body, '}') + 1;
            if ($start === false || $end === false) {
                throw new Exception('Invalid JSON response');
            }
            $json = substr($body, $start, $end - $start);
            $data = json_decode($json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('JSON decode error: ' . json_last_error_msg());
            }
            return $data;
        } catch (Exception $e) {
            error_log("Fetch data attempt $attempt failed: " . $e->getMessage());
            if ($attempt < MAX_RETRIES - 1) {
                sleep(RETRY_DELAY);
            }
        }
    }
    return null;
}

function getFinalM3u8Url($initialUrl) {
    $client = new Client();
    for ($attempt = 0; $attempt < MAX_RETRIES; $attempt++) {
        try {
            $response = $client->get($initialUrl, [
                'headers' => ['User-Agent' => USER_AGENT],
                'timeout' => 10
            ]);
            $lines = explode("\n", $response->getBody()->getContents());
            $resolutions = [];
            for ($i = 0; $i < count($lines); $i++) {
                if (strpos($lines[$i], 'RESOLUTION=') !== false) {
                    try {
                        $resolution = (int)explode(',', explode('x', $lines[$i])[1])[0];
                        $path = trim($lines[$i + 1]);
                        $resolutions[] = ['resolution' => $resolution, 'path' => $path];
                    } catch (Exception $e) {
                        continue;
                    }
                }
            }
            if (empty($resolutions)) {
                error_log("No resolutions found in m3u8 (attempt $attempt)");
                if ($attempt < MAX_RETRIES - 1) {
                    sleep(RETRY_DELAY);
                }
                continue;
            }
            usort($resolutions, function($a, $b) {
                return $b['resolution'] - $a['resolution'];
            });
            $relPath = $resolutions[0]['path'];
            $parsed = parse_url($initialUrl);
            $pathParts = explode('/', $parsed['path']);
            if (count($pathParts) < 4) {
                error_log("Invalid URL path structure (attempt $attempt)");
                if ($attempt < MAX_RETRIES - 1) {
                    sleep(RETRY_DELAY);
                }
                continue;
            }
            $manifestPath = str_replace('../../../', '', $relPath);
            $finalPath = "/v1/$manifestPath";
            return $parsed['scheme'] . '://' . $parsed['host'] . $finalPath;
        } catch (Exception $e) {
            error_log("M3u8 URL processing attempt $attempt failed: " . $e->getMessage());
            if ($attempt < MAX_RETRIES - 1) {
                sleep(RETRY_DELAY);
            }
        }
    }
    return null;
}

function getM3u8Urls($eventId) {
    $client = new Client();
    $cookies = CookieJar::fromArray([
        'remember_token_30_days' => '2040826|ae3107d45738cce097dec06c78de24e64c11f486b94a068d021512b327133c03aeec9b40b80a2a65afcbcf2de6bad8d144913172d78ed6f7cbf68c3e333556d3',
        'authenticated' => 'True',
        'first_name' => 'User',
        'last_name' => '',
        'score_disabled' => 'False',
        'user_id' => '2040826',
    ], 'willow.tv');
    $headers = [
        'User-Agent' => USER_AGENT,
        'Accept' => 'text/javascript, application/javascript, application/ecmascript, application/x-ecmascript, */*; q=0.01',
        'X-Requested-With' => 'XMLHttpRequest'
    ];
    for ($attempt = 0; $attempt < MAX_RETRIES; $attempt++) {
        try {
            $response = $client->get('https://www.willow.tv/match_live_data_by_id', [
                'query' => ['matchid' => $eventId],
                'headers' => $headers,
                'cookies' => $cookies,
                'timeout' => 10
            ]);
            $body = $response->getBody()->getContents();
            // Extract JSON
            $start = strpos($body, '{');
            $end = strrpos($body, '}') + 1;
            if ($start === false || $end === false) {
                throw new Exception('Invalid JSON response');
            }
            $json = substr($body, $start, $end - $start);
            $data = json_decode($json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('JSON decode error: ' . json_last_error_msg());
            }
            $englishUrl = null;
            $hindiUrl = null;
            foreach ($data['result'] ?? [] as $stream) {
                if ($stream['title'] === 'LIVE SOURCE ENGLISH') {
                    $englishUrl = getFinalM3u8Url($stream['secureurl']);
                } elseif ($stream['title'] === 'LIVE SOURCE HINDI') {
                    $hindiUrl = getFinalM3u8Url($stream['secureurl']);
                }
            }
            return [$englishUrl, $hindiUrl];
        } catch (Exception $e) {
            error_log("M3u8 URLs attempt $attempt for event $eventId failed: " . $e->getMessage());
            if ($attempt < MAX_RETRIES - 1) {
                sleep(RETRY_DELAY);
            }
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
            "gmt_start_date" => $event["GMTStartDate"] ?? "",
            "gmt_end_date" => $event["GMTEndDate"] ?? "",
            "team_one_name" => $event["TeamOneName"],
            "team_two_name" => $event["TeamTwoName"],
            "team_one_image" => "https://aimages.willow.tv/teamLogos/" . $event['ImageTeamOne'] . ".png",
            "team_two_image" => "https://aimages.willow.tv/teamLogos/" . $event['ImageTeamTwo'] . ".png",
            "short_score" => $event["ShortScore"] ?? "",
            "type" => $event["Type"],
            "series_name" => $event["SeriesName"],
            "event_id" => $event["EventId"],
            "is_match_free" => (bool)$event["IsMatchFree"],
            "status" => $status,
            "country_codes" => $event["CC_CODE"] ?? [],
            "ist_start_time" => $event["ISTStartDateTime"] ?? "",
            "venue" => $event["Venue"] ?? "",
            "url" => $event["url"] ?? "",
            "user_agent" => $status === "LIVE" ? USER_AGENT : ""
        ];
        if ($status === "LIVE") {
            error_log("Processing live event ID: " . $event['Id']);
            list($englishUrl, $hindiUrl) = getM3u8Urls($event["Id"]);
            if ($englishUrl) {
                $result["m3u8_eng_url"] = $englishUrl;
                error_log("Added English URL for event " . $event['Id']);
            }
            if ($hindiUrl) {
                $result["m3u8_hin_url"] = $hindiUrl;
                error_log("Added Hindi URL for event " . $event['Id']);
            }
        }
        return $result;
    } catch (Exception $e) {
        error_log("Error processing event: " . $e->getMessage());
        return null;
    }
}

function getIndianTime() {
    $utc = new DateTime('now', new DateTimeZone('UTC'));
    $indian = new DateTimeZone('Asia/Kolkata');
    $utc->setTimezone($indian);
    $timeStr = $utc->format('h:i:s A');
    $dateStr = $utc->format('d-m-Y');
    return [$timeStr, $dateStr];
}

function generateJsonData($liveEvents, $upcomingEvents) {
    list($indianTime, $indianDate) = getIndianTime();
    $allMatches = array_merge($liveEvents, $upcomingEvents);
    return [
        "last_refresh_time" => $indianTime,
        "last_refresh_date" => $indianDate,
        "author" => "@Darshan_101005",
        "total_matches" => count($allMatches),
        "live_matches" => count($liveEvents),
        "upcoming_matches" => count($upcomingEvents),
        "matches" => $allMatches,
        "last_updated" => (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s \G\M\T')
    ];
}

function generateM3uPlaylist($liveEvents) {
    define('PROXY_API', 'https://willow-tv-darshaniptv.vercel.app/api/');
    $lines = ["#EXTM3U"];
    foreach ($liveEvents as $event) {
        $eventName = $event["name"] ?? "Unknown Event";
        if (isset($event["m3u8_eng_url"])) {
            $lines[] = '#EXTINF:-1 tvg-logo="https://img.willow.tv/apps/wtv_logo_new_200_200.jpg" group-title="WILLOW LIVE EVENTS @DARSHANIPTV",' . $eventName . ' (ENGLISH)';
            $lines[] = '#EXTVLCOPT:http-user-agent=' . USER_AGENT;
            $lines[] = PROXY_API . $event["m3u8_eng_url"];
            $lines[] = "";
        }
        if (isset($event["m3u8_hin_url"])) {
            $lines[] = '#EXTINF:-1 tvg-logo="https://img.willow.tv/apps/wtv_logo_new_200_200.jpg" group-title="WILLOW LIVE EVENTS @DARSHANIPTV",' . $eventName . ' (HINDI)';
            $lines[] = '#EXTVLCOPT:http-user-agent=' . USER_AGENT;
            $lines[] = PROXY_API . $event["m3u8_hin_url"];
            $lines[] = "";
        }
    }
    return implode("\n", $lines);
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$data = fetchData();
if (!$data) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Failed to fetch data']);
    exit;
}

$liveEvents = [];
foreach ($data['live'] ?? [] as $event) {
    if ($processed = processEvent($event)) {
        $liveEvents[] = $processed;
    }
}

$upcomingEvents = [];
foreach ($data['upcoming'] ?? [] as $event) {
    if ($processed = processEvent($event)) {
        $upcomingEvents[] = $processed;
    }
}

if (strpos($path, 'willow-tv.m3u') !== false) {
    header('Content-Type: audio/x-mpegurl');
    echo generateM3uPlaylist($liveEvents);
} else {
    header('Content-Type: application/json');
    echo json_encode(generateJsonData($liveEvents, $upcomingEvents), JSON_PRETTY_PRINT);
}
?>
