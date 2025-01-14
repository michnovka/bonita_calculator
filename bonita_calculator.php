<?php

require_once(dirname(__FILE__).'/apikey.secret.php');

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


/**
 * Handle $parcels, which can be string or array:
 * - if string, explode by newlines, trim, discard empties.
 * - if array, just keep it as-is.
 */
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
} elseif (!is_array($parcels)) {
    // Fall back to empty array if it's neither string nor array
    $parcels = [];
}

// Summaries
$totalSqm = 0;                // overall summed area
$bpejSums = [];               // BPEJ code -> total sqm
$lvs = [];                    // track all LV numbers
$bpejPrices = [];             // BPEJ code -> price per sqm
$parcelsWithoutBpej = [];     // track parcels that have no BPEJ

/**
 * Simple cURL GET helper
 *
 * @param string $url
 * @param string[] $headers
 * @param string|null $userAgent
 * @return string|null
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

    if ($error) {
        return null;
    }
    return $response;
}

// 1) Query each parcel from the KN API
$index = 0;
$count = count($parcels);

foreach ($parcels as $parcel) {
    $index++;
    echo "Querying parcel {$index}/{$count}: {$parcel}\n";

    // parse "kmenoveCislo/poddeleni" or "kmenoveCislo"
    $kmenove = 0;
    $poddeleni = null;
    if (strpos($parcel, '/') !== false) {
        list($kmenoveString, $poddeleniString) = explode('/', $parcel, 2);
        $kmenove = (int)$kmenoveString;
        $poddeleni = (int)$poddeleniString;
    } else {
        $kmenove = (int)$parcel;
    }

    // Build URL
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

    // cURL request
    $response = curlGetContents($url, ['ApiKey: ' . APIKEY]);
    if (!$response) {
        echo "Error fetching data for parcel: {$parcel}\n";
        continue;
    }

    $json = json_decode($response);
    if (!isset($json->data) || !is_array($json->data) || count($json->data) === 0) {
        echo "No data found for parcel: {$parcel}\n";
        continue;
    }

    // Generally expect a single item in "data"
    $parcelData = $json->data[0];
    $vymera = $parcelData->vymera ?? 0;
    $totalSqm += $vymera;

    // store LV
    if (isset($parcelData->lv->cislo)) {
        $lvs[] = $parcelData->lv->cislo;
    }

    // handle BPEJ
    if (!empty($parcelData->bpej)) {
        foreach ($parcelData->bpej as $bpejItem) {
            $bpejCode = $bpejItem->kod;
            $bpejArea = $bpejItem->vymera ?? 0;
            if (!isset($bpejSums[$bpejCode])) {
                $bpejSums[$bpejCode] = 0;
            }
            $bpejSums[$bpejCode] += $bpejArea;
        }
    } else {
        // parcels with no BPEJ
        $druhPozemku = $parcelData->druhPozemku->nazev ?? 'Unknown type';
        $parcelsWithoutBpej[] = [
            'parcelName' => $parcel,   // e.g. "1391/21"
            'vymera' => $vymera,
            'druhPozemku' => $druhPozemku
        ];
    }
}

// 2) Fetch BPEJ prices
$bpejCodes = array_keys($bpejSums);
$index = 0;
$count = count($bpejCodes);

foreach ($bpejCodes as $code) {
    $index++;
    echo "Fetching BPEJ price ({$index}/{$count}): {$code}\n";
    $bpejUrl = "https://bpej.vumop.cz/{$code}";

    // Spoof user agent
    $html = curlGetContents($bpejUrl, [], 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36');
    if (!$html) {
        echo "Error fetching BPEJ price for code: {$code}\n";
        $bpejPrices[$code] = 0;
        continue;
    }

    // We'll capture the line with "Základní cena pozemků [Kč/m<sup>2</sup>]" and then parse out:
    // <span class="badge cenaXkat bvBadge">PRICE</span>
    $price = 0.0;
    $lines = explode("\n", $html);
    foreach ($lines as $line) {
        if (strpos($line, '<b>Základní cena pozemků [Kč/m<sup>2</sup>]') !== false) {
            // Pattern: <span ...>15.60</span>
            if (preg_match('/<span[^>]*>([0-9]+(\.[0-9]+)?)<\/span>/', $line, $matches)) {
                $price = floatval($matches[1]);
                break;
            }
        }
    }

    $bpejPrices[$code] = $price;
}

// 3) Summaries
echo "\n=== Final Results ===\n\n";
echo "Total square meters across all parcels: {$totalSqm}\n\n";

$weightedSum = 0.0;
echo "BPEJ breakdown:\n";
foreach ($bpejSums as $bpejCode => $sqm) {
    $price = $bpejPrices[$bpejCode] ?? 0;
    $partial = $sqm * $price;
    $weightedSum += $partial;
    echo " - BPEJ {$bpejCode}: {$sqm} sqm, price: {$price} Kč/m2, partial sum = {$partial}\n";
}

// 4) Average BPEJ Price (rounded to 2 decimals)
$averageBpejPrice = 0;
if ($totalSqm > 0) {
    $averageBpejPrice = $weightedSum / $totalSqm;
}
$averageBpejPrice = round($averageBpejPrice, 2);
echo "\nAverage BPEJ price: {$averageBpejPrice} Kč/m2\n";

// 5) Parcels without BPEJ
if (!empty($parcelsWithoutBpej)) {
    echo "\nParcels without BPEJ:\n";
    $totalNoBpej = 0;
    foreach ($parcelsWithoutBpej as $pData) {
        $parcelName = $pData['parcelName'];
        $vymera = $pData['vymera'];
        $druhPozemku = $pData['druhPozemku'];
        $totalNoBpej += $vymera;
        echo " - {$parcelName}: {$vymera} sqm, {$druhPozemku}\n";
    }
    echo "Total area of parcels without BPEJ: {$totalNoBpej} sqm\n";
}

// 6) LV info
$uniqueLvs = array_unique($lvs);
if (count($uniqueLvs) === 1) {
    echo "\nAll parcels have the same LV: " . reset($uniqueLvs) . "\n";
} elseif (count($uniqueLvs) > 1) {
    echo "\nMultiple LVs detected: " . implode(', ', $uniqueLvs) . "\n";
} else {
    echo "\nNo LV detected.\n";
}

// 7) Excel bonita calculation: =((area1*price1)+(area2*price2)+...)/totalArea
// Using BPEJ sums. Example: =((1204*13.3)+(1293*5.50))/97623
$excelParts = [];
foreach ($bpejSums as $bpejCode => $sqm) {
    $price = $bpejPrices[$bpejCode] ?? 0;
    // format at least for decimal conversion
    $excelParts[] = "({$sqm}*{$price})";
}
if ($totalSqm > 0 && !empty($excelParts)) {
    $excelFormula = "=(" . implode("+", $excelParts) . ")/{$totalSqm}";
    echo "\nExcel bonita calculation: {$excelFormula}\n";
} else {
    echo "\nExcel bonita calculation: (No BPEJ data)\n";
}
