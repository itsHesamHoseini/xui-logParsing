<?php
function logmsg($level, $message) {
    $time = date('H:i:s');
    $colors = [
        'INFO'  => "\033[32m", // green
        'WARN'  => "\033[33m", // yellow
        'ERROR' => "\033[31m", // red
        'OK'    => "\033[36m", // light blue
        'DONE'  => "\033[35m", // purple
        'DEBUG' => "\033[34m", // blue
    ];
    $reset = "\033[0m";
    $color = $colors[$level] ?? "\033[37m"; // white default
    echo "[$time] [$color$level$reset] $message" . PHP_EOL;
}
function human_filesize(int $bytes, int $decimals = 2): string {
    $sizes = ['B','KB','MB','GB','TB'];
    if ($bytes <= 0) return '0 B';
    $i = (int) floor(log($bytes, 1024));
    $p = pow(1024, $i);
    $s = round($bytes / $p, $decimals);
    return $s . ' ' . $sizes[$i];
}


/**
 * Resolve org using local mmdblookup (preferred).
 * Uses $ispCache (by-reference) to avoid repeated work.
 * Returns cleaned org string or "Unknown".
 */
function getOrgFromMMDB($ip, array &$ispCache) {
    if (isset($ispCache[$ip])) return $ispCache[$ip];

    if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
        $ispCache[$ip] = "Unknown";
        return "Unknown";
    }
    if (!file_exists(MMDB_FILE)) {
        $ispCache[$ip] = "Unknown";
        return "Unknown";
    }
    $mmdb = escapeshellarg(MMDB_FILE);
    $ipArg = escapeshellarg($ip);
    $cmd = "mmdblookup --file $mmdb --ip $ipArg 2>&1";
    $descriptors = [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"]
    ];
    $proc = proc_open($cmd, $descriptors, $pipes, null, null);
    if (!is_resource($proc)) {
        $ispCache[$ip] = "Unknown";
        return "Unknown";
    }
    $output = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($proc);

    if ($status !== 0 && trim($output) === '' && trim($err) === '') {
        $ispCache[$ip] = "Unknown";
        return "Unknown";
    }

    $combined = $output . "\n" . $err;

    // Try to extract autonomous_system_organization value
    // mmdblookup example shows lines like:
    // "autonomous_system_organization":
    //   "Cloudflare, Inc." <utf8_string>
    // We'll capture the "..." content.
    $org = null;
    if (preg_match('/autonomous_system_organization["\']?\s*:\s*["\']([^"\']+)["\']/i', $combined, $m)) {
        $org = trim($m[1]);
    } else {
        // alternative pattern if output is two-line style
        if (preg_match('/autonomous_system_organization\s*[\r\n]+\s*["\']([^"\']+)["\']/i', $combined, $m2)) {
            $org = trim($m2[1]);
        }
    }

    if (!$org) {
        // As a fallback try autonomous_system_number + organization on same line
        if (preg_match('/"autonomous_system_organization"\s*:\s*([^\n]+)/i', $combined, $m3)) {
            $org = trim(strip_tags($m3[1]));
            // remove trailing types like <utf8_string>
            $org = preg_replace('/<.*?>/', '', $org);
            $org = trim($org, " \t\n\r\"'");
        }
    }

    if (!$org) {
        $ispCache[$ip] = "Unknown";
        return "Unknown";
    }

    // remove leading AS number if present (e.g. AS58224 ...)
    $org = preg_replace('/^AS\d+\s*/i', '', $org);
    $org = trim($org);

    if ($org === '') $org = "Unknown";
    $ispCache[$ip] = $org;
    return $org;
}

 /*
  * Wrapper used by your main code: tries mmdb first, then optional fallback to ip-api.
  */

function getOrg($ip, array &$ispCache) {
    $org = getOrgFromMMDB($ip, $ispCache);
    if ($org && $org !== "Unknown") return $org;
    return $org;
}

function mapOrgToISP($org) {
    if (!$org || $org === "Unknown") return "";

    $orgLower = strtolower($org);

    $rules = [
        'mci' => ['/mobile communication company of iran/i', '/mci/i', '/hamrah/i'],
        'irancell' => ['/iran cell service/i', '/irancell/i', '/mtn/i'],
        'tci' => ['/telecommunication company of iran/i', '/mokhaberat/i', '/tci/i','/Iran Telecommunication Company PJS/i'],
        'pishgaman' => ['/pishgaman/i'],
        'shatel' => ['/shatel/i'],
        'asiatech' => ['/asiatech/i'],
        'rightel' => ['/rightel/i'],
        'pars online' => ['/pars online/i'],
        'hiweb' => ['/hi.?web/i'],
        'ShahroodUniNetwork' => ['/Shahrood University of Technology/i']
    ];

    foreach ($rules as $isp => $patterns) {
        foreach ($patterns as $pat) {
            if (preg_match($pat, $orgLower)) {
                return $isp;
            }
        }
    }

    return "";
}

function parseLogDateTime($line) {
    $line = trim($line);
    if (preg_match('/^\s*(\d{4}\/\d{2}\/\d{2})\s+(\d{2}:\d{2}:\d{2})/', $line, $m)) {
        return [$m[1], $m[2]];
    }
    return [null, null];
}
function getLastLine($file) {
    $line = '';
    $f = fopen($file, 'r');
    if ($f === false) return null;

    fseek($f, -1, SEEK_END);
    $pos = ftell($f);

    if (fgetc($f) === "\n" && $pos > 0) {
        fseek($f, -1, SEEK_CUR);
        $pos--;
    }

    while ($pos >= 0) {
        fseek($f, $pos, SEEK_SET);
        $char = fgetc($f);
        if ($char === "\n" && $line !== '') {
            break;
        }
        $line = $char . $line;
        $pos--;
    }
    fclose($f);
    return trim($line);
}
function bot($method,$datas=[]){
    $url = "https://api.telegram.org/bot".API_KEY."/".$method;
    $ch = curl_init();
    curl_setopt($ch,CURLOPT_URL,$url);
    curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
    curl_setopt($ch,CURLOPT_POSTFIELDS,$datas);
    $res = curl_exec($ch);
    if(curl_error($ch)){
        var_dump(curl_error($ch));
    }else{
        return json_decode($res);
    }
}
function prettyLog($userId, $date, $time, $ips, $realUniqueIPSCount, $isSkipped=false) {
    $red    = "\033[31m";
    $yellow = "\033[33m";
    $reset  = "\033[0m";
    $green = "\033[32m";
    $blue  = "\033[34m";
    $white = "\033[37m";
    $boldRed = "\033[1;31m";
    $boldGreen = "\033[1;32m";
    $boldYellow = "\033[1;33m";
    $boldBlue = "\033[1;34m";
    $boldWhite = "\033[1;37m";
    $blackBg = "\033[40m";
    $redBg = "\033[41m";
    $greenBg = "\033[42m";
    $yellowBg = "\033[43m";
    $blueBg = "\033[44m";
    $magentaBg = "\033[45m";
    $cyanBg = "\033[46m";
    $whiteBg = "\033[47m";
    $highIntensityRed = "\033[91m";
    $highIntensityGreen = "\033[92m";
    $highIntensityYellow = "\033[93m";
    $highIntensityBlue = "\033[94m";
    $highIntensityMagenta = "\033[95m";
    $highIntensityCyan = "\033[96m";
    $highIntensityWhite = "\033[97m";
    $boldHighIntensityRed = "\033[1;91m";
    $boldHighIntensityGreen = "\033[1;92m";
    $boldHighIntensityYellow = "\033[1;93m";
    $boldHighIntensityBlue = "\033[1;94m";
    $boldHighIntensityMagenta = "\033[1;95m";
    $boldHighIntensityCyan = "\033[1;96m";
    $boldHighIntensityWhite = "\033[1;97m";
    $maxAllowUser=$GLOBALS['maxAllowUser'] ?? 2;
    $countText = count($ips);
    if($realUniqueIPSCount < count($ips)) {
        if($realUniqueIPSCount > $maxAllowUser) {
            $countText = count($ips) ." ({$red}$realUniqueIPSCount{$reset} in unique range)";
        } else {
            $countText = count($ips) ." ({$green}$realUniqueIPSCount{$reset} in unique range {$yellow}*Skipped*{$reset})";
        }
    }
    echo str_repeat("─", 100) . "\n";
    echo "⚠️  {$yellow}[WARN]{$reset} User {$red}$userId{$reset} connected with "
       . $countText . " different IPs\n";
    echo "     Date: $date ";
    echo "Time: $time\n";

    renderTable($ips);


    $intervals = [];
    foreach ($ips as $r) {
        if (!empty($r['time']) && !empty($r['last_time'])) {
            $intervals[] = ['start'=>$r['time'], 'end'=>$r['last_time']];
        }
    }
    $maxOverlapInterval = maxOverlapInterval($intervals, 3);

    if ($maxOverlapInterval['ok']) {
        $duration = $maxOverlapInterval['duration'];
        $h = intdiv($duration, 3600); 
        $duration %= 3600;
        $m = intdiv($duration, 60);   
        $s = $duration % 60;

        $durationStr = 
            ($h > 0 ? "{$h}h " : "") . 
            ($m > 0 ? "{$m}min " : "") . 
            ($s > 0 ? "{$s}s" : "");

        echo "     Max Overlap Interval (≥3 IPs): {$boldRed}"
        . "{$maxOverlapInterval['start']} - {$maxOverlapInterval['end']} "
        . "({$durationStr}){$reset}\n";
    } else {
        echo "     Max Overlap Interval (≥3 IPs): {$boldGreen}No suspicious overlap found 🎉{$reset}\n";
    }

    // if()
}

function renderTable($rows) {
    $cyan    = "\033[36m";  // IP
    $magenta = "\033[35m";  // ISP
    $green   = "\033[32m";  // TIME
    $purple  = "\033[35m";  // L_TIME
    $gray    = "\033[90m";  // Unknown
    $reset   = "\033[0m";

    $maxIp      = max(array_map(fn($r) => strlen($r['ip']), $rows));
    $maxIsp     = max(array_map(fn($r) => strlen($r['isp']), $rows));
    $maxTime    = max(array_map(fn($r) => strlen($r['time']), $rows));
    $maxLTime   = max(array_map(fn($r) => strlen($r['last_time'] ?? ''), $rows));

    $top    = "┌" . str_repeat("─", $maxIp+2) . "┬" . str_repeat("─", $maxIsp+2) . "┬" . str_repeat("─", $maxTime+2) . "┬" . str_repeat("─", $maxLTime+2) . "┐";
    $header = "│ " . str_pad("IP", $maxIp) . " │ " . str_pad("ISP", $maxIsp) . " │ " . str_pad("TIME", $maxTime) . " │ " . str_pad("L_TIME", $maxLTime) . " │";
    $sep    = "├" . str_repeat("─", $maxIp+2) . "┼" . str_repeat("─", $maxIsp+2) . "┼" . str_repeat("─", $maxTime+2) . "┼" . str_repeat("─", $maxLTime+2) . "┤";
    $bottom = "└" . str_repeat("─", $maxIp+2) . "┴" . str_repeat("─", $maxIsp+2) . "┴" . str_repeat("─", $maxTime+2) . "┴" . str_repeat("─", $maxLTime+2) . "┘";

    echo "   $top\n";
    echo "   $header\n";
    echo "   $sep\n";

    foreach ($rows as $row) {
        $isp     = $row['isp'] ?: "{$gray}Unknown{$reset}";
        $time    = $row['time'] ?: "{$gray}-{$reset}";
        $lastRaw = $row['last_time'] ?? '';
        $last = $lastRaw !== '' ? $lastRaw : "{$gray}-{$reset}";

        if ($lastRaw !== '') {
            $now   = time();
            $today = date('Y-m-d');
            $ts    = @strtotime("$today $lastRaw");

            if ($ts !== false) {
                $diff = $now - $ts;
                if ($diff >= 0 && $diff <= 30) {
                    $last = "Now(-".abs($diff).")";
                }
                elseif ($diff > 30) {
                    $hour=$diff/3600 >= 1 ? floor($diff/3600) . "h " : "";
                    $min=$diff%3600/60 >= 1 ? floor($diff%3600/60) . "m " : "";
                    $sec=$diff%60 > 0 ? ($diff%60) . "s" : "";
                    $last = trim($hour . $min . $sec);
                }
            }
        }

        
        printf(
            "   │ %s%-{$maxIp}s%s │ %s%-{$maxIsp}s%s │ %s%-{$maxTime}s%s │ %s%-{$maxLTime}s%s │\n",
            $cyan, $row['ip'], $reset,
            $magenta, $isp, $reset,
            $green, $time, $reset,
            $purple, $last, $reset
        );
    }

    echo "   $bottom\n";
}


function ip_prefix($ip, $maskBits = 16) {
    $long = ip2long($ip);
    $mask = -1 << (32 - $maskBits);
    $network = $long & $mask;
    return long2ip($network) . "/$maskBits";
}

function diffTimes($date1,$time1,$date2,$time2) {
    $start = new DateTime("$date1 $time1");
    $end   = new DateTime("$date2 $time2");

    if ($end < $start) {
        $end->modify('+1 day');
    }

    $diffSeconds = $end->getTimestamp() - $start->getTimestamp();

    $minutes = intdiv($diffSeconds, 60);
    $seconds = $diffSeconds % 60;
    $minutesDecimal = $diffSeconds / 60.0;
    $docsCaption = "";
    $docsCaption .= "{$minutes} min";
    if ($seconds > 0) $docsCaption .= " {$seconds} sec";
    return $docsCaption;

}

function getUniqueUsers($dataInTimes) {
    $users = [];

    foreach ($dataInTimes as $date => $times) {
        foreach ($times as $time => $usernames) {
            foreach ($usernames as $username => $records) {
                $users[$username] = true;
            }
        }
    }

    return array_keys($users);
}
function getUniqueUsersCount($dataInTimes) {
    getUniqueUsers($dataInTimes);
    return count(getUniqueUsers($dataInTimes));
}

function hms_to_sec(string $t): int {
    [$h, $m, $s] = array_map('intval', explode(':', $t));
    return $h * 3600 + $m * 60 + $s;
}

function sec_to_hms(int $sec): string {
    $sec = max(0, $sec);
    $h = intdiv($sec, 3600); $sec %= 3600;
    $m = intdiv($sec, 60);   $s   = $sec % 60;
    return sprintf('%02d:%02d:%02d', $h, $m, $s);
}


function maxOverlapInterval(array $intervals, int $minOverlapCount = 2): array {
    $fail = ['ok'=>false, 'start'=>null, 'end'=>null, 'duration'=>0];

    if ($minOverlapCount < 2 || count($intervals) < $minOverlapCount) {
        return $fail;
    }

    $events = [];
    foreach ($intervals as $r) {
        if (!isset($r['start'], $r['end'])) continue;
        $s = hms_to_sec($r['start']);
        $e = hms_to_sec($r['end']);
        if ($e <= $s) continue;

        $events[] = ['t'=>$s, 'delta'=>+1, 'type'=>0];
        $events[] = ['t'=>$e, 'delta'=>-1, 'type'=>1];
    }

    if (empty($events)) return $fail;

    usort($events, function($a, $b){
        if ($a['t'] === $b['t']) return $a['type'] <=> $b['type'];
        return $a['t'] <=> $b['t'];
    });

    $active = 0;
    $lastT  = null;
    $best   = ['len'=>0, 's'=>null, 'e'=>null];

    foreach ($events as $ev) {
        $t = $ev['t'];

        if ($lastT !== null && $active >= $minOverlapCount && $t > $lastT) {
            $len = $t - $lastT;
            if ($len > $best['len']) {
                $best = ['len'=>$len, 's'=>$lastT, 'e'=>$t];
            }
        }

        $active += $ev['delta'];
        $lastT = $t;
    }

    if ($best['len'] > 0) {
        return [
            'ok'       => true,
            'start'    => sec_to_hms($best['s']),
            'end'      => sec_to_hms($best['e']),
            'duration' => $best['len'],
        ];
    }
    return $fail;
}
