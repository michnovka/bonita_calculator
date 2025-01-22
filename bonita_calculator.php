<?php

// SAMPLE usage: php bonita_calculator.php [--force-fresh-bpej-cache]

require_once(dirname(__FILE__).'/apikey.secret.php');

$rawKNData = null;
$KodKatastralnihoUzemi = 0;

/**
 * $parcels can be an array:
 *   $parcels = [ '1119/1', '1284' ];
 * or a string, with one parcel per line:
 *   $parcels = "1119/1\n1284\n1391/21";
 */
$parcels = [];

// this redefines the ku and parcels so its not pushed to git
require_once(dirname(__FILE__).'/parcels.php');

// ============== CLI ARGUMENT PARSING ==============
$forceFresh = in_array('--force-fresh-bpej-cache', $argv, true);





/**
 * cURL GET helper
 */
function curlGetContents(string $url, array $headers = [], ?string $userAgent = null): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 15,
    ]);

    if ($userAgent) {
        curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
    }
    if ($headers) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    return $error ? null : $response;
}

/**
 * Parse $rawKNData to get 'katastralniUzemiName', 'katastralniUzemiCode', 'parcels'
 */
function parseRawKNData(string $rawKNData): array
{
    $results = [
        'katastralniUzemiName' => null,
        'katastralniUzemiCode' => null,
        'parcels' => [],
    ];

    $lines = preg_split('/\r\n|\r|\n/', $rawKNData);
    if (!$lines) {
        return $results;
    }

    // match "Katastrální území: Something [123456]"
    foreach ($lines as $line) {
        $lineTrim = trim($line);
        if (preg_match('/Katastrální\s+území\s*:\s*(.+?)\[(\d+)\]/u', $lineTrim, $matches)) {
            $results['katastralniUzemiName'] = trim($matches[1]);
            $results['katastralniUzemiCode'] = (int)$matches[2];
            break;
        }
    }

    // find "Parcelní číslo" line, collect subsequent lines that are "digits" or "digits/digits"
    $collecting = false;
    foreach ($lines as $line) {
        $lineTrim = trim($line);
        if (!$collecting && stripos($lineTrim, 'Parcelní číslo') !== false) {
            $collecting = true;
            continue;
        }
        if ($collecting) {
            if (empty($lineTrim)) {
                break;
            }
            if (preg_match('/^\d+(\/\d+)?$/', $lineTrim)) {
                $results['parcels'][] = $lineTrim;
            } else {
                break;
            }
        }
    }

    return $results;
}

/**
 * Load BPEJ JSON cache from local file
 */
function loadBpejCacheFile(string $filePath): ?array
{
    if (!file_exists($filePath)) {
        return null;
    }
    $contents = file_get_contents($filePath);
    if (!$contents) {
        return null;
    }
    $arr = json_decode($contents, true);
    if (!is_array($arr)) {
        return null;
    }
    return $arr;
}

/**
 * Compute SHA256 sum of a file
 */
function sha256File(string $filePath): ?string
{
    if (!file_exists($filePath)) {
        return null;
    }
    return hash_file('sha256', $filePath);
}

// ============== PARSE $rawKNData IF PROVIDED ==============
if (!empty($rawKNData)) {
    $parsed = parseRawKNData($rawKNData);
    $knName = $parsed['katastralniUzemiName'];
    $knCode = $parsed['katastralniUzemiCode'];
    $knParcels = $parsed['parcels'];

    if ($knCode && $knParcels) {
        echo "Detected from raw KN Data:\n";
        echo " - Katastrální území: {$knName} [{$knCode}]\n";
        echo " - Parcels:\n";
        foreach ($knParcels as $p) {
            echo "    {$p}\n";
        }

        // ask user if to proceed
        while (true) {
            echo "Do you want to proceed? [Y/n] ";
            $answer = trim(fgets(STDIN));
            if ($answer === '' || strcasecmp($answer, 'y') === 0) {
                // proceed
                break;
            } elseif (strcasecmp($answer, 'n') === 0) {
                echo "Exiting.\n";
                exit(0);
            } else {
                echo "Please enter 'Y' or 'n'.\n";
            }
        }

        // override
        $KodKatastralnihoUzemi = $knCode;
        $parcels = $knParcels;
    } else {
        echo "WARNING: Could not parse a valid katastrální území or parcels from raw KN Data.\n";
        echo "Falling back to default vars.\n";
    }
}

// In case $parcels is still a string, parse line by line
if (is_string($parcels)) {
    $lines = explode("\n", $parcels);
    $filtered = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line !== '') {
            $filtered[] = $line;
        }
    }
    $parcels = $filtered;
}

// ============== BPEJ CACHE HANDLING ==============
// ask "Use local BPEJ cache file?" [Y/n]
echo "Do you want to use bpej.json cache file? [Y/n] ";
$useCacheAnswer = 'y'; // default
$readAnswer = trim(fgets(STDIN));
if ($readAnswer !== '') {
    $useCacheAnswer = $readAnswer;
}

$useLocalBpejCache = (strcasecmp($useCacheAnswer, 'n') !== 0);

$bpejCache = [];
if ($useLocalBpejCache) {
    $cacheFilePath = __DIR__ . '/bpej.json';
    $fileExists = file_exists($cacheFilePath);
    if ($fileExists) {
        $modifiedTime = filemtime($cacheFilePath);
        $formattedTime = date('Y-m-d H:i:s', $modifiedTime);
        echo "bpej.json last modified: {$formattedTime}\n";
    } else {
        echo "bpej.json file is missing.\n";
    }

    if ($fileExists) {
        $strNow = time();
        $isOlderThan24h = ($strNow - $modifiedTime) > (24 * 3600);
        if (!$forceFresh && !$isOlderThan24h) {
            // If not forcing AND not older than 24hr, do nothing
            echo "bpej.json is newer than 24 hours, no update needed.\n";
        } else {
            // Either forced or older than 24hr
            // ask user if not forced
            if (!$forceFresh && $isOlderThan24h) {
                echo "bpej.json is older than 24 hours. Update? [Y/n] ";
                $updAnswer = trim(fgets(STDIN));
                if ($updAnswer === '' || strcasecmp($updAnswer, 'y') === 0) {
                    // do update
                } else {
                    echo "Skipping refresh, continuing with old file.\n";
                    goto loadBpejFile;
                }
            }

            // do forced update or user accepted update
            // compute old hash
            $oldHash = sha256File($cacheFilePath) ?? '(none)';
            echo "Fetching new bpej.json...\n";
            // run "php bpej_fetch.php" from same dir
            $cmd = 'php "' . __DIR__ . '/bpej_fetch.php"';
            exec($cmd, $outLines, $retCode);
            if ($retCode !== 0) {
                echo "Error running bpej_fetch.php, code={$retCode}\n";
            } else {
                echo "bpej_fetch.php finished.\n";
                $newHash = sha256File($cacheFilePath) ?? '(none)';
                if ($oldHash === $newHash) {
                    echo "No changes in bpej.json file (hash is the same).\n";
                } else {
                    echo "bpej.json has changed (old sha256={$oldHash}, new sha256={$newHash}).\n";
                }
            }
        }
    } else {
        // file does not exist, must fetch
        echo "bpej.json file missing, fetching now...\n";
        $cmd = 'php "' . __DIR__ . '/bpej_fetch.php"';
        exec($cmd, $outLines, $retCode);
        if ($retCode !== 0) {
            echo "Error running bpej_fetch.php, code={$retCode}.\n";
        } else {
            echo "bpej_fetch.php finished.\n";
        }
    }

    loadBpejFile:
    // load the bpej.json file (if it exists now)
    $bpejCache = loadBpejCacheFile($cacheFilePath) ?? [];
    echo "Loaded " . count($bpejCache) . " BPEJ codes from local cache.\n";
}

// ============== PARCEL QUERIES ==============
$totalSqm = 0;
$bpejSums = [];
$lvs = [];
$parcelsWithoutBpej = [];

$index = 0;
$count = count($parcels);

foreach ($parcels as $parcel) {
    $index++;
    echo "Querying parcel {$index}/{$count}: {$parcel}\n";

    // parse "XX" or "XX/YY"
    $kmenove = 0;
    $poddeleni = null;
    if (strpos($parcel, '/') !== false) {
        list($kmenoveStr, $poddeleniStr) = explode('/', $parcel, 2);
        $kmenove = (int)$kmenoveStr;
        $poddeleni = (int)$poddeleniStr;
    } else {
        $kmenove = (int)$parcel;
    }

    $baseUrl = 'https://api-kn.cuzk.gov.cz/api/v1/Parcely/Vyhledani';
    $queryParams = [
        'KodKatastralnihoUzemi' => $KodKatastralnihoUzemi,
        'TypParcely' => 'PKN',
        'DruhCislovaniParcely' => 2,
        'KmenoveCisloParcely' => $kmenove
    ];
    if ($poddeleni > 0) {
        $queryParams['PoddeleniCislaParcely'] = $poddeleni;
    }
    $url = $baseUrl . '?' . http_build_query($queryParams);

    $response = curlGetContents($url, ['ApiKey: ' . APIKEY]);
    if (!$response) {
        echo "Fatal error: Could not fetch data from Katastr for parcel: {$parcel}\n";
        exit(1);
    }

    $json = json_decode($response);
    if (!isset($json->data) || !is_array($json->data) || count($json->data) === 0) {
        echo "Fatal error: No data found for parcel: {$parcel}\n";
        exit(1);
    }

    $parcelData = $json->data[0];
    $vymera = $parcelData->vymera ?? 0;
    $totalSqm += $vymera;

    if (isset($parcelData->lv->cislo)) {
        $lvs[] = $parcelData->lv->cislo;
    }

    if (!empty($parcelData->bpej)) {

        foreach ($parcelData->bpej as $bpejItem) {
            $bpejCode = str_pad($bpejItem->kod, 5, '0', STR_PAD_LEFT);
            $bpejArea = $bpejItem->vymera ?? 0;
            if (!isset($bpejSums[$bpejCode])) {
                $bpejSums[$bpejCode] = 0;
            }
            $bpejSums[$bpejCode] += $bpejArea;
        }
    } else {
        $druhPozemku = $parcelData->druhPozemku->nazev ?? 'Unknown type';
        $parcelsWithoutBpej[] = [
            'parcelName' => $parcel,
            'vymera' => $vymera,
            'druhPozemku' => $druhPozemku
        ];
    }
}

// ============== PART 2B: GET BPEJ PRICES ==============
$bpejPrices = []; // final code => price
$allBpejCodes = array_keys($bpejSums);
$index = 0;
$count = count($allBpejCodes);

foreach ($allBpejCodes as $bpejCode) {
    $index++;
    echo "Determining price for BPEJ code ({$index}/{$count}): {$bpejCode}\n";

    // If $bpejCode is in $bpejCache, use it
    // If not, infinite retry
    if ($useLocalBpejCache && array_key_exists($bpejCode, $bpejCache)) {
        $bpejPrices[$bpejCode] = (float)$bpejCache[$bpejCode];
        echo "  Using cached price: " . $bpejPrices[$bpejCode] . "\n";
        continue;
    }

    // fallback infinite retry from bpej.vumop.cz
    $attempt = 0;
    $price = 0.0;

    while (true) {
        $attempt++;
        echo "  Attempt #{$attempt} for BPEJ code {$bpejCode}\n";

        $bpejUrl = "https://bpej.vumop.cz/{$bpejCode}";
        $html = curlGetContents(
            $bpejUrl,
            [],
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36'
        );

        if (!$html) {
            echo "  Error fetching BPEJ data from website, sleeping 5 seconds and retrying...\n";
            sleep(5);
            continue;
        }

        $foundPrice = false;
        $lines = explode("\n", $html);
        foreach ($lines as $line) {
            if (strpos($line, '<b>Základní cena pozemků [Kč/m<sup>2</sup>]') !== false) {
                if (preg_match('/<span[^>]*>([0-9]+(\.[0-9]+)?)<\/span>/', $line, $matches)) {
                    $price = floatval($matches[1]);
                    $foundPrice = true;
                    break;
                }
            }
        }

        if (!$foundPrice) {
            echo "  Could not parse BPEJ price from HTML, sleeping 5 seconds and retrying...\n";
            sleep(5);
            continue;
        }

        break;
    }

    $bpejPrices[$bpejCode] = $price;
    echo "  Price found: {$price}\n";
}

// ============== PART 3: SUMMARIES ==============
echo "\n=== Final Results ===\n\n";
echo "Total square meters across all parcels: {$totalSqm}\n\n";

$weightedSum = 0.0;
echo "BPEJ breakdown:\n";
foreach ($bpejSums as $code => $sqm) {
    $price = $bpejPrices[$code] ?? 0;
    $partial = $sqm * $price;
    $weightedSum += $partial;
    echo " - BPEJ {$code}: {$sqm} sqm, price: {$price} Kč/m2, partial sum = {$partial}\n";
}

// average
$averageBpejPrice = 0.0;
if ($totalSqm > 0) {
    $averageBpejPrice = $weightedSum / $totalSqm;
}
$averageBpejPrice = round($averageBpejPrice, 2);
echo "\nAverage BPEJ price: {$averageBpejPrice} Kč/m2\n";

// parcels without BPEJ
if (!empty($parcelsWithoutBpej)) {
    echo "\nParcels without BPEJ:\n";
    $totalNoBpej = 0;
    foreach ($parcelsWithoutBpej as $pData) {
        $parcelName = $pData['parcelName'];
        $vymera = $pData['vymera'];
        $druh = $pData['druhPozemku'];
        $totalNoBpej += $vymera;
        echo " - {$parcelName}: {$vymera} sqm, {$druh}\n";
    }
    echo "Total area of parcels without BPEJ: {$totalNoBpej} sqm\n";
}

// LV info
$uniqueLvs = array_unique($lvs);
if (count($uniqueLvs) === 1) {
    echo "\nAll parcels have the same LV: " . reset($uniqueLvs) . "\n";
} elseif (count($uniqueLvs) > 1) {
    echo "\nMultiple LVs detected: " . implode(', ', $uniqueLvs) . "\n";
} else {
    echo "\nNo LV detected.\n";
}

// excel formula
$excelParts = [];
foreach ($bpejSums as $code => $sqm) {
    $price = $bpejPrices[$code] ?? 0;
    $excelParts[] = "({$sqm}*{$price})";
}
if ($totalSqm > 0 && !empty($excelParts)) {
    $excelFormula = "=(" . implode("+", $excelParts) . ")/{$totalSqm}";
    echo "\nExcel bonita calculation: {$excelFormula}\n";
} else {
    echo "\nExcel bonita calculation: (No BPEJ data)\n";
}

echo "\nDone.\n";
