<?php
/**
 * This script fetches BPEJ codes and their prices from https://bpej.vumop.cz/
 * and saves them to bpej.json as a JSON object where the key is the BPEJ code (5 digits)
 * and the value is the price per square meter.
 */

// 1. Initialize cURL and set options
$ch = curl_init("https://bpej.vumop.cz/");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // follow redirects if any

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError !== '' || $httpCode !== 200) {
    die("Error fetching the page. cURL Error: $curlError. HTTP Code: $httpCode");
}

// 2. Load HTML into DOMDocument
libxml_use_internal_errors(true);
$dom = new DOMDocument();
$dom->loadHTML($response);
libxml_clear_errors(); // clear any parse errors

// 3. Find the table rows in the DOM
$rows = $dom->getElementsByTagName('tr');
$bpejData = [];

// 4. Loop through each row and extract BPEJ code and price
foreach ($rows as $row) {
    $cols = $row->getElementsByTagName('td');
    
    // We expect at least 5 columns based on the example
    if ($cols->length >= 5) {
        // 4.1 BPEJ Code: first column (strip non-digits if necessary)
        // The code typically appears inside an <a> element, e.g. "./43746"
        $anchorElements = $cols->item(0)->getElementsByTagName('a');
        if ($anchorElements->length > 0) {
            // Extract only the digits from the link or text
            $href = $anchorElements->item(0)->getAttribute('href');
            
            // Typically, the href is something like "./43746"
            // We can also get the textContent if needed
            // For reliability, we can parse the last part or use a regex
            preg_match('/(\d{5})/', $href, $matches);
            
            if (!empty($matches[1])) {
                $bpejCode = $matches[1];
            } else {
                // Fallback: try textContent of the <a> node
                $text = $anchorElements->item(0)->textContent;
                preg_match('/(\d{5})/', $text, $codeMatches);
                $bpejCode = !empty($codeMatches[1]) ? $codeMatches[1] : null;
            }
        } else {
            continue; // No anchor => skip row
        }
        
        if (!$bpejCode) {
            continue; // Couldn't parse BPEJ code => skip row
        }
        
        // 4.2 Price: 5th column => item(4)
        $priceText = trim($cols->item(4)->textContent);
        
        // Convert to float, if empty or invalid => 0
        $price = 0;
        if (is_numeric($priceText)) {
            // Some localities may use comma as decimal separator, so replace
            $priceText = str_replace(',', '.', $priceText);
            $price = (float)$priceText;
        }
        
        // Store it in our array with BPEJ code as key
        $bpejData[$bpejCode] = $price;
    }
}

// 5. Sort the array ascending by BPEJ code
ksort($bpejData);

// 6. Save to bpej.json
file_put_contents('bpej.json', json_encode($bpejData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// Done. You can now check the bpej.json file for results.
echo "BPEJ codes and prices have been saved to bpej.json\n";
