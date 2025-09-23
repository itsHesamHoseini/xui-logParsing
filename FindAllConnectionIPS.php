<?php

$baseAccesslogUrl = "/usr/local/x-ui/access.log";
if(!file_exists($baseAccesslogUrl)){
    throw new Exception("$baseAccesslogUrl not found.");
    exit();
}

$IFS=' ';
$dataInTimes=[];
$ispCache=[]; // cache برای اینکه هر آی‌پی فقط یک بار resolve بشه

// تابع گرفتن org (ISP/Organization)
function getOrg($ip, &$ispCache) {
    if (isset($ispCache[$ip])) {
        return $ispCache[$ip];
    }
    $url = "https://ipinfo.io/{$ip}/json";
    $json = @file_get_contents($url);
    if ($json === false) {
        $ispCache[$ip] = "Unknown";
        return "Unknown";
    }
    $data = json_decode($json, true);
    $org = $data['org'] ?? "Unknown";
    $ispCache[$ip] = $org;
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
    ];

    foreach ($rules as $isp => $patterns) {
        foreach ($patterns as $pat) {
            if (preg_match($pat, $orgLower)) {
                return $isp;
            }
        }
    }

    return ""; // اگر match نشد
}


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
        foreach($dataInTimes[$ymd]["$h:$changed_i"][$username] as $IPS) {
            if($IPS['ip'] == $ip)
                continue 2;
        }
        $dataInTimes[$ymd]["$h:$changed_i"][$username][]=[
            'ip'   => $ip,
            'time' => "$h:$i:$s",
            'org'  => $org,
            'who_is'=>mapOrgToISP($org)
        ];

    } else {
        $dataInTimes[$ymd]["$h:$changed_i"][$username][]=[
            'ip'   => $ip,
            'time' => "$h:$i:$s",
            'org'  => $org,
            'who_is'=>mapOrgToISP($org)
        ];
    }

}
fclose($handle);

file_put_contents('log.json', json_encode($dataInTimes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
