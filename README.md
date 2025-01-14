# README

Welcome to the “BPEJ Bonita Calculator” project. This utility fetches and calculates the base price of land parcels in Czech cadastral units (“katastrální území”) using both public APIs (from ČÚZK and VÚMOP) and a local cache file for BPEJ (Bonitovaná půdně ekologická jednotka) price data.

Below you’ll find information on setup, configuration, and basic usage examples.

---

## Table of Contents

- [Prerequisites](#prerequisites)  
- [Folder Structure and .gitignore](#folder-structure-and-gitignore)  
- [Files Overview](#files-overview)  
  - [apikey.secret.php](#apikeysecretphp)  
  - [parcels.php](#parcelsphp)  
  - [bpej.json](#bpejjson)  
  - [bpej_fetch.php](#bpej_fetchphp)  
  - [bonita_calculator.php](#bonita_calculatorphp)  
- [Setup](#setup)  
- [Usage](#usage)  
  - [Examples](#examples)  
- [License](#license)  

---

## Prerequisites

- PHP 8.2 or newer.  
- cURL extension for PHP (needed to make HTTP requests).  
- Basic command-line interface (CLI) usage knowledge.

---

## Folder Structure and .gitignore

The project consists of the following files (simplified):

```
.
├─ apikey.secret.php
├─ parcels.php
├─ bpej.json
├─ bpej_fetch.php
├─ bonita_calculator.php
├─ README.md
└─ .gitignore
```

Your `.gitignore` file should include:

```
apikey.secret.php
parcels.php
bpej.json
```

This ensures you don’t accidentally commit your sensitive API key or personal parcel data.

---

## Files Overview

### apikey.secret.php
This file defines your ČÚZK API key as a constant:

```php
<?php
const APIKEY = 'YOUR_ACTUAL_API_KEY';
```

Keep this file out of version control and private.

---

### parcels.php
This file is where you can define your parcel data outside version control. It contains either:
- $rawKNData: A multiline string automatically parsed for “Katastrální území” and “Parcelní číslo.”  
- $KodKatastralnihoUzemi: An integer code of the cadastral area.  
- $parcels: Either an array of parcel designations or a multiline string with one parcel per line.

Example (randomized data):
```php
<?php

$rawKNData = '
Seznam nemovitostí na LV
Číslo LV:       9999
Katastrální území:      RandomCity [123456]
Zobrazení v mapě
Vlastníci, jiní oprávnění
Vlastnické právo        Podíl
John Doe, SomeStreet 123, SomeCity
Pozemky
Parcelní číslo
100
205/1
';

$KodKatastralnihoUzemi = 741191;
$parcels = "304/2\n4501";
```

---

### bpej.json
A JSON cache file containing BPEJ codes and their prices. Example:

```json
{
    "00100": 16.77,
    "00110": 14.94,
    "00401": 7.32
    ...
}
```

This file is also excluded from version control. It can be refreshed by running `bpej_fetch.php`.

---

### bpej_fetch.php
A script that fetches/updates the entire BPEJ price list and stores it in `bpej.json`. Typically called by the main script (`bonita_calculator.php`) when needed (older than 24 hours, forced refresh, or missing file).

---

### bonita_calculator.php
Primary script to query the ČÚZK API for parcel data, compute total sq. meters, parse BPEJ codes, and calculate weighted BPEJ price.  
It can optionally load or refresh the local `bpej.json` cache and only do infinite-retry lookups from the official site if a BPEJ code is missing in the cache.

Key points:
1. It includes `apikey.secret.php` for the ČÚZK API key.  
2. It includes `parcels.php` to load your $rawKNData or $KodKatastralnihoUzemi / $parcels definitions.  
3. It shows interactive prompts about using or refreshing the BPEJ cache.  
4. You can pass a flag `--force-fresh-bpej-cache` to force an update of the local BPEJ file.

---

## Setup

1. **Clone or download** this repository.  
2. **Add your API key** in a new file called `apikey.secret.php`:
   ```php
   <?php
   const APIKEY = 'YOUR_ACTUAL_API_KEY';
   ```
3. **Create your default `parcels.php`** in the same folder. You can define how you want to provide the data—for instance, via `$rawKNData` or by `$KodKatastralnihoUzemi` and `$parcels`.  
4. **(Optional)** If you prefer caching BPEJ data, ensure `bpej.json` exists or allow the script to generate it. By default, it is excluded from Git.

---

## Usage

1. **Edit your** `parcels.php` file to specify cadastral data (e.g., `$KodKatastralnihoUzemi` and `$parcels`). Alternatively, provide `$rawKNData` with lines that mention “Katastrální území” and “Parcelní číslo.”  
2. **Run the calculator**:
   ```bash
   php bonita_calculator.php
   ```
   By default, it will ask if you want to use the `bpej.json` cache, potentially update it, etc.  

3. **Optional**: Force a fresh BPEJ cache update:
   ```bash
   php bonita_calculator.php --force-fresh-bpej-cache
   ```
   This will skip any prompt about refreshing or using the old cache file and will run `bpej_fetch.php` automatically if you choose to use the cache.

### Examples

• Simple:
```bash
php bonita_calculator.php
```
Prompts:
- “Do you want to use bpej.json cache file? [Y/n]”
- If the file is older than 24 hours (or if `--force-fresh-bpej-cache` is passed), it may ask “Update bpej.json? [Y/n]?”

• Force refresh:
```bash
php bonita_calculator.php --force-fresh-bpej-cache
```
Skips the prompt about updating if the cache is old and updates automatically if you choose to use the cache.

During execution, you’ll see:
- Parcel queries to ČÚZK. If no data is found, the script exits with an error.  
- BPEJ lookups from the local cache. Missing codes are fetched from the VÚMOP website with an infinite retry strategy.

At the end, you get:
- Total square meters  
- Breakdown of each BPEJ code with partial sums  
- Weighted average BPEJ price (rounded to two decimals)  
- Listing of parcels without BPEJ, if any  
- The Excel formula for manual calculation  
- Potentially, relevant messages about multiple LVs or a single LV

---

## License

This project is not distributed under a formal license. Use at your own discretion. Contributions and forks are welcome, but please omit any personal or sensitive data when sharing publicly.
