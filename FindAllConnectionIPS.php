<?php
define('MMDB_FILE', 'dbip-asn-lite-2025-09.mmdb');
date_default_timezone_set('Asia/Tehran'); // sync it with your os ( timedatectl set-timezone Asia/Tehran ) && (x-ui restart)

$env=file_get_contents(".env");
$envs=explode("\n",$env);
foreach($envs as $env) {
    if(str_contains($env,'=')) {
        list($key,$value)=explode('=',$env,2);
        putenv(trim($key)."=".trim($value));
    }
}
$token = getenv('API_KEY_TOKEN');
$from_id = getenv('FROM_ID');
$maxAllowUser = getenv('MAX_ALLOW_USER');
define('API_KEY', $token);
if(!$token || !$from_id) {
    throw new Exception("API_KEY_TOKEN or FROM_ID not found in .env file.");
    exit();
}


include 'functions.php';


$baseAccesslogUrl = "/usr/local/x-ui/access.log";
if(!file_exists($baseAccesslogUrl)){
    throw new Exception("$baseAccesslogUrl not found.");
    exit();
}
$logReallySize = human_filesize(filesize($baseAccesslogUrl));
logmsg('INFO', "Size of access.log is: ".$logReallySize);


$firstLine = trim(fgets(fopen($baseAccesslogUrl, 'r')));
$lastLine  = getLastLine($baseAccesslogUrl);
list($firstDate, $firstTime) = parseLogDateTime($firstLine);
list($lastDate, $lastTime)   = parseLogDateTime($lastLine);

if ($firstDate && $lastDate) {
    if ($firstDate === $lastDate) {
        logmsg('INFO', "Log covers date $firstDate from $firstTime to $lastTime");
    } else {
        logmsg('INFO', "Log covers from $firstDate $firstTime to $lastDate $lastTime");
    }
} else {
    logmsg('WARN', "Could not parse timestamps from log file");
}


$IFS=' ';
$dataInTimes=[];
$ispCache=[];



$handle = fopen($baseAccesslogUrl, "r");
while (($line = fgets($handle)) !== false) {    
    $callmeAWK=explode($IFS,$line);
    if ($callmeAWK[4] !== "accepted") // if not accepted , we don't need to store it.
        continue;

    $ymd=$callmeAWK[0];
    list($h,$i,$s)=(function() use ($callmeAWK){
        $his=explode('.',$callmeAWK[1])[0];
        list($h,$i,$s)=explode(':',$his);
        return [$h,$i,$s];
    })();
    $ip = (function () use ($callmeAWK) {
        $src = $callmeAWK[3];
        $parts = explode(':', $src);
        if ($parts[0] === 'tcp' || $parts[0] === 'udp') {
            return $parts[1];
        }
        return $parts[0];
    })();
    $status=$callmeAWK[4];
    list($type, $domainOrIP, $port) = (function () use ($callmeAWK) {
        $distinationWithPort = explode(':', $callmeAWK[5]);
        
        if ($distinationWithPort[0] === 'tcp' || $distinationWithPort[0] === 'udp') {
            $type       = $distinationWithPort[0];
            $domainOrIP = $distinationWithPort[1];
            $port       = $distinationWithPort[2] ?? null;
        } else {
            $type       = 'tcp';
            $domainOrIP = $distinationWithPort[0];
            $port       = $distinationWithPort[1] ?? null;
        }

        return [$type, $domainOrIP, $port];
    })();

    if (str_contains($domainOrIP, '127.0.0.1') || str_contains($ip, '127.0.0.1')) {
        continue;
    }

    $username = trim(end($callmeAWK));
    $changed_i = str_pad(floor((int)$i / 10) * 10, 2, "0", STR_PAD_LEFT);

    $org = getOrg($ip, $ispCache);

    if(isset($dataInTimes[$ymd]["$h:$changed_i"][$username])) {
        foreach($dataInTimes[$ymd]["$h:$changed_i"][$username] as $key=>$IPS) {
            if($IPS['ip'] == $ip) {
                $dataInTimes[$ymd]["$h:$changed_i"][$username][$key]['last_time'] = "$h:$i:$s";
                continue 2;
            }
        }
        $dataInTimes[$ymd]["$h:$changed_i"][$username][] = [
            'ip'     => $ip,
            'time'   => "$h:$i:$s",
            'org'    => $org,
            'who_is' => mapOrgToISP($org),
            'last_time' => "$h:$i:$s"
        ];


    } else {
        $dataInTimes[$ymd]["$h:$changed_i"][$username][] = [
            'ip'     => $ip,
            'time'   => "$h:$i:$s",
            'org'    => $org,
            'who_is' => mapOrgToISP($org),
            'last_time' => "$h:$i:$s"
        ];

    }

}
fclose($handle);


file_put_contents('AllConnectionIPs.json', json_encode($dataInTimes));


$docPath=__DIR__.'/AllConnectionIPs.json';
$docsCaption="";
if ($firstDate && $lastDate) {
    if ($firstDate === $lastDate) {
        $docsCaption="*$firstDate* | from *$firstTime* to *$lastTime*\n";
        $docsCaption.=diffTimes($firstDate,$firstTime,$firstDate,$lastTime);
    } else {
        $docsCaption="*$firstDate $firstTime* to *$lastDate $lastTime*\n";
        $docsCaption.=diffTimes($firstDate,$firstTime,$lastDate,$lastTime);
    }
} else {
    $docsCaption="Could not parse timestamps from log file";
}
$docsCaption.="\nSize of access.log was: *".$logReallySize."*";
$getUniqueUsersCount=(int)getUniqueUsersCount($dataInTimes);
$docsCaption.="\nTotal Online Users in this time: *".$getUniqueUsersCount . "*";

$documentResult = bot('senddocument',['chat_id'=>$from_id,'caption'=>$docsCaption,'parse_mode'=>'markdown','document'=>new CURLFile(realpath($docPath))]);


if($documentResult->ok)
    logmsg('OK', "we Send json document to Telegram Bot API successfully.");
else {
    logmsg('ERROR', "Failed to send json document to Telegram Bot API.");
    exit();
}


$description="";
foreach($dataInTimes as $ymd=>$hises) {
    foreach($hises as $his=>$users) {
        foreach($users as $user=>$ips) {
            $logWarnIps = [];
            foreach ($ips as $iparray) {
                $logWarnIps[] = [
                    'ip'   => $iparray['ip'],
                    'isp'  => ($iparray['who_is'] ?: $iparray['org']),
                    'time' => $iparray['time'],
                    'last_time' => $iparray['last_time'],
                ];
            }
            if(count($ips) > $maxAllowUser) {
                $isSkipped = false;
                $clients = [];
                foreach($logWarnIps as $row) {
                    $prefix = ip_prefix($row['ip'], 16);
                    $clients[$prefix] = true;
                }
                $clientCount=count($clients);
                if($clientCount <= (int)$maxAllowUser) {
                    $isSkipped = true;
                }
                $realUniqueIPSCount = $clientCount;
                prettyLog($user,$ymd,$his,$logWarnIps,(int)$realUniqueIPSCount,$isSkipped);
            }
        }
    }
}

echo '-------------------------- START: Violation tracking & alerting in telegram --------------------------'.PHP_EOL;
$violationsFile = __DIR__ . '/violations.json';
$alertsCacheFile = __DIR__ . '/alerts_cache.json';
$alertThrottleSeconds = getenv('ALERT_THROTTLE_SECONDS') ? (int)getenv('ALERT_THROTTLE_SECONDS') : 3600; // default 1h
$reportChatId = $from_id; // using same from_id (private chat)

$violations = is_readable($violationsFile) ? json_decode(file_get_contents($violationsFile), true) : [];
$alertsCache = is_readable($alertsCacheFile) ? json_decode(file_get_contents($alertsCacheFile), true) : [];

// helper to persist
function save_json_file($path, $data) {
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// iterate and collect alerts to send (so we can send after updating DB)
$alertsToSend = [];

foreach($dataInTimes as $ymd=>$hises) {
    foreach($hises as $his=>$users) {
        foreach($users as $user=>$ips) {
            // build normalized ips array
            $rows = [];
            foreach ($ips as $iparray) {
                $rows[] = [
                    'ip' => $iparray['ip'] ?? '',
                    'isp' => ($iparray['who_is'] ?? $iparray['org'] ?? ''),
                    'time' => $iparray['time'] ?? ''
                ];
            }

            // if user exceeds threshold
            if (count($rows) > (int)$maxAllowUser) {
                // unique ISPs
                $uniqueIsps = array_values(array_filter(array_unique(array_map(fn($r)=>trim($r['isp']), $rows))));

                // foreach($rows as $ip) {
                //     $ip=$ip['ip'];
                //     $isPass=false;
                //     foreach($rows as $ip2) {
                //         if($isPass) {
                //             /// i am there
                //             // ok
                //         } else {
                //             // not pass
                //             if($ip == $ip2) {
                //                 $isPass=true;
                //             }
                //         }
                //     }
                // }
                $clients = [];

                foreach($rows as $row) {
                    $prefix = ip_prefix($row['ip'], 16);
                    $clients[$prefix] = true;
                }

                $clientCount = count($clients);
                if($clientCount <= (int)$maxAllowUser) {
                    // if the unique client count is within limit, skip alert
                    logmsg('INFO', "User $user has ".count($rows)." IPs but only $clientCount unique clients(there is in unique ip range), skipping alert.");
                    $userLinked = is_numeric($user) ? "[$user](tg://openmessage?user_id=$user)" : $user;
                    $ipsString = "";
                    foreach($rows as $r) { /// 1.1.1.1 | mci | 
                        $ipsString .= "- {$r['ip']} | " . ($r['isp'] ?: '-') . " | {$r['time']}\n";
                    }
                    $r=bot('sendMessage', [
                        'chat_id' => $reportChatId,
                        'text' => "User $userLinked has ".count($rows)." IPs but only $clientCount unique clients(there is in unique ip range), skipping alert.\n$ipsString",
                        'parse_mode' => 'Markdown',
                        ]
                    );

                    continue;
                }


                // build offense entry
                $offense = [
                    'date' => $ymd,
                    'time_block' => $his,
                    'count_ips' => count($rows),
                    'unique_isps' => $uniqueIsps,
                    'ips' => $rows,
                    'detected_at' => gmdate('c')
                ];
                // update violations DB for this user
                if (!isset($violations[$user])) {
                    $violations[$user] = [
                        'total_offenses' => 0,
                        'score' => 0,
                        'offenses' => [],
                        'first_seen' => $offense['detected_at'],
                        'last_seen' => $offense['detected_at']
                    ];
                }

                $violations[$user]['total_offenses'] += 1;
                // increment score (you can change weighting)
                $violations[$user]['score'] += 1;
                $violations[$user]['offenses'][] = $offense;
                $violations[$user]['last_seen'] = $offense['detected_at'];

                // Save an alert to send later if throttle allows
                $now = time();
                $lastAlertAt = $alertsCache[$user]['last_alert_at'] ?? 0;
                if (($now - $lastAlertAt) >= $alertThrottleSeconds) {
                    // prepare message for Telegram
                    $userLinked = is_numeric($user) ? "[$user](tg://openmessage?user_id=$user)" : $user;
                    // title, subtitle, table as code block
                    $title = "⚠️ [WARN] User {$userLinked} exceeded allowed IPs ({$offense['count_ips']})";
                    $subtitle = "Date: {$offense['date']}  Time-block: {$offense['time_block']}\nUnique ISPs: " . ($uniqueIsps ? implode(', ', $uniqueIsps) : '-');
                    // build table as code block (monospace)
                    $tableLines = [];
                    $tableLines[] = str_pad('IP', 17) . " | " . str_pad('ISP', 12) . " | TIME";
                    $tableLines[] = str_repeat('-', 17) . "-|-" . str_repeat('-', 12) . "-|-" . str_repeat('-', 8);
                    foreach ($rows as $r) {
                        $tableLines[] = str_pad($r['ip'], 17) . " | " . str_pad(($r['isp'] ?: '-'), 12) . " | " . $r['time'];
                    }
                    $tableText = "```\n" . implode("\n", $tableLines) . "\n```";

                    $message = $title . "\n" . $subtitle . "\n\n" . $tableText . "\n\n" .
                               "Total offenses for user: " . $violations[$user]['total_offenses'] .
                               "  |  Score: " . $violations[$user]['score'];

                    $alertsToSend[] = [
                        'user' => $user,
                        'ip_sample' => $rows[0]['ip'] ?? '',
                        'message' => $message
                    ];

                    // update alertsCache to throttle
                    $alertsCache[$user] = ['last_alert_at' => $now];
                } // end throttle check
            } // end threshold check
        }
    }
}

// persist violations DB and alerts cache
save_json_file($violationsFile, $violations);
save_json_file($alertsCacheFile, $alertsCache);



// send queued alerts to Telegram (do after saving)
foreach ($alertsToSend as $a) {
    // use bot() from functions.php
    $text = $a['message'];
    // send as markdown (code block) — ensure Telegram parse_mode
    $r=bot('sendMessage', [
        'chat_id' => $reportChatId,
        'text' => $text,
        'parse_mode' => 'Markdown'
    ]);
}

echo '--------------------------- END: Violation tracking & alerting in telegram ---------------------------';
