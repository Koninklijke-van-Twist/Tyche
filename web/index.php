<?php
/**
 * Includes/requires
 */
require __DIR__ . '/auth.php';
require __DIR__ . '/odata.php';

/**
 * Constants
 */
const ODATA_TTL_SECONDS = 3600;
const ENTITY_SALESPERSON = 'AppSalesPerson';
const ENTITY_SALESPERSON_CARD = 'SalespersonPurchaserCard';
const ENTITY_GENERAL_LEDGER_SETUP = 'GeneralLedgerSetup';
const ENTITY_DIMENSION_VALUES = 'DimensionValues';
const ENTITY_SALES_QUOTES = 'SalesQuotes';
const ENTITY_SALES_QUOTE_LINES = 'SalesQuoteSalesLines';
const ENTITY_SALES_DOCUMENTS = 'salesDocuments';
const ENTITY_SALES_OPPORTUNITIES = 'SalesOpportunitiesPage';

/**
 * Variabelen
 */
$errorMessage = '';
$selectedSalesperson = trim((string) ($_GET['salesperson'] ?? ''));
$selectedOfferType = trim((string) ($_GET['offer_type'] ?? 'all'));

$today = new DateTimeImmutable('today');
$defaultDateFrom = $today->modify('first day of this month')->format('Y-m-d');
$defaultDateTo = $today->modify('last day of this month')->format('Y-m-d');

$dateFrom = trim((string) ($_GET['date_from'] ?? $defaultDateFrom));
$dateTo = trim((string) ($_GET['date_to'] ?? $defaultDateTo));

if (!in_array($selectedOfferType, ['all', 'direct', 'project'], true)) {
    $selectedOfferType = 'all';
}

$salespersons = [];
$selectedSalespersonName = '';
$selectedDepartmentCode = '';
$selectedDepartmentName = '';
$selectedDepartmentDimensionCode = '';
$rows = [];
$directCustomerSummary = [];
$projectTypeSummary = [];
$totals = [
    'count' => 0,
    'revenue' => 0.0,
    'cost' => 0.0,
    'profit' => 0.0,
];

/**
 * Functies
 */
function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function parse_date_ymd(string $value): ?DateTimeImmutable
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    if (!($date instanceof DateTimeImmutable)) {
        return null;
    }

    return $date->format('Y-m-d') === $value ? $date : null;
}

function q(float $value): string
{
    return number_format($value, 2, ',', '.');
}

function odata_url(string $baseUrl, string $entity, array $query = []): string
{
    $url = rtrim($baseUrl, '/') . '/' . $entity;
    if ($query === []) {
        return $url;
    }

    return $url . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}

function safe_odata_get_all(string $url, array $auth, int $ttlSeconds, string $entityLabel): array
{
    try {
        return odata_get_all($url, $auth, $ttlSeconds);
    } catch (Throwable $e) {
        throw new RuntimeException('Kon ' . $entityLabel . ' niet ophalen: ' . $e->getMessage(), 0, $e);
    }
}

function as_float($value): float
{
    if (is_int($value) || is_float($value)) {
        return (float) $value;
    }

    if (!is_string($value)) {
        return 0.0;
    }

    $text = trim($value);
    if ($text === '') {
        return 0.0;
    }

    $text = str_replace([' ', "\xC2\xA0"], '', $text);

    if (strpos($text, ',') !== false && strpos($text, '.') !== false) {
        $text = str_replace('.', '', $text);
        $text = str_replace(',', '.', $text);
    } elseif (strpos($text, ',') !== false) {
        $text = str_replace(',', '.', $text);
    }

    return is_numeric($text) ? (float) $text : 0.0;
}

function normalize_text(string $value): string
{
    return strtolower(trim($value));
}

function odata_quote_string_value(string $value): string
{
    return str_replace("'", "''", $value);
}

function build_or_filter(string $field, array $values): string
{
    $parts = [];
    foreach ($values as $value) {
        $parts[] = $field . " eq '" . odata_quote_string_value((string) $value) . "'";
    }

    if ($parts === []) {
        return '';
    }

    if (count($parts) === 1) {
        return $parts[0];
    }

    return '(' . implode(' or ', $parts) . ')';
}

function detect_offer_type(array $quote): string
{
    $opportunityNo = trim((string) ($quote['Opportunity_No'] ?? ''));
    $jobType = trim((string) ($quote['LVS_Job_Type'] ?? ''));

    if ($opportunityNo !== '' || $jobType !== '') {
        return 'Project';
    }

    return 'Direct Sales';
}

function detect_result_status(array $quote, array $salesDocumentsByQuote, array $opportunitiesByNo): string
{
    // Primair: LVS_Document_Status bevat een statuscode met patroon NN_LABEL (bijv. 05_WON, 01_OPEN).
    // De numerieke prefix is stabiel; de tekst na de underscore is leidend voor won/verloren.
    $docStatus = strtolower(trim((string) ($quote['LVS_Document_Status'] ?? '')));
    if ($docStatus !== '') {
        // Extraheer het label-deel na de eerste underscore (bijv. "won" uit "05_won")
        $labelPart = preg_match('/^\d+_(.+)$/', $docStatus, $m) ? trim($m[1]) : $docStatus;

        if (strpos($labelPart, 'won') !== false) {
            return 'Gewonnen';
        }
        if (strpos($labelPart, 'lost') !== false || strpos($labelPart, 'verlor') !== false) {
            return 'Verloren';
        }
        if (strpos($labelPart, 'cancel') !== false || strpos($labelPart, 'annul') !== false) {
            return 'Verloren';
        }
        if (strpos($labelPart, 'open') !== false || strpos($labelPart, 'released') !== false) {
            return 'Open';
        }
    }

    // Fallback: quoteAccepted uit salesDocuments
    $quoteNo = trim((string) ($quote['No'] ?? ''));
    if ($quoteNo !== '' && isset($salesDocumentsByQuote[$quoteNo])) {
        $accepted = $salesDocumentsByQuote[$quoteNo]['quoteAccepted'] ?? null;
        if ($accepted === true || $accepted === 1 || $accepted === '1') {
            return 'Gewonnen';
        }
    }

    // Fallback: opportunity status/close code
    $opportunityNo = trim((string) ($quote['Opportunity_No'] ?? ''));
    if ($opportunityNo !== '' && isset($opportunitiesByNo[$opportunityNo])) {
        $opp = $opportunitiesByNo[$opportunityNo];
        $closed = $opp['Closed'] ?? false;
        $oppStatus = normalize_text((string) ($opp['Status'] ?? ''));
        $closeCode = normalize_text((string) ($opp['KVT_Close_Opportunity_Code'] ?? ''));
        $closeDescription = normalize_text((string) ($opp['KVT_Close_Opp_Code_Description'] ?? ''));

        $isClosed = ($closed === true || $closed === 1 || $closed === '1' || $oppStatus === 'closed');
        if ($isClosed) {
            $combined = $oppStatus . ' ' . $closeCode . ' ' . $closeDescription;
            if (strpos($combined, 'lost') !== false || strpos($combined, 'verlor') !== false) {
                return 'Verloren';
            }
            if (strpos($combined, 'won') !== false || strpos($combined, 'gewonn') !== false || strpos($combined, 'succes') !== false) {
                return 'Gewonnen';
            }
            return 'Gesloten';
        }
    }

    return 'Open';
}

/**
 * Page load
 */
$dateFromObj = parse_date_ymd($dateFrom) ?? parse_date_ymd($defaultDateFrom) ?? new DateTimeImmutable($defaultDateFrom);
$dateToObj = parse_date_ymd($dateTo) ?? parse_date_ymd($defaultDateTo) ?? new DateTimeImmutable($defaultDateTo);

if ($dateToObj < $dateFromObj) {
    $tmp = $dateFromObj;
    $dateFromObj = $dateToObj;
    $dateToObj = $tmp;
}

$dateFrom = $dateFromObj->format('Y-m-d');
$dateTo = $dateToObj->format('Y-m-d');

try {
    $salespersonUrl = odata_url($base, ENTITY_SALESPERSON, [
        '$select' => 'Code,Name',
    ]);
    $salespersons = safe_odata_get_all($salespersonUrl, $auth, ODATA_TTL_SECONDS, 'accountmanagers');

    usort($salespersons, static function (array $a, array $b): int {
        return strcmp((string) ($a['Name'] ?? ''), (string) ($b['Name'] ?? ''));
    });

    if ($selectedSalesperson !== '') {
        foreach ($salespersons as $sp) {
            $spCode = trim((string) ($sp['Code'] ?? ''));
            if ($spCode === $selectedSalesperson) {
                $selectedSalespersonName = trim((string) ($sp['Name'] ?? $spCode));
                break;
            }
        }
    }

    if ($selectedSalesperson !== '') {
        $escapedSalesperson = odata_quote_string_value($selectedSalesperson);

        $ledgerSetupRows = safe_odata_get_all(
            odata_url($base, ENTITY_GENERAL_LEDGER_SETUP, [
                '$select' => 'Global_Dimension_1_Code,Global_Dimension_2_Code',
                '$top' => '1',
            ]),
            $auth,
            ODATA_TTL_SECONDS,
            'general ledger setup'
        );
        $ledgerSetup = $ledgerSetupRows[0] ?? [];
        $globalDim1 = trim((string) ($ledgerSetup['Global_Dimension_1_Code'] ?? ''));
        $globalDim2 = trim((string) ($ledgerSetup['Global_Dimension_2_Code'] ?? ''));

        $salespersonCardRows = safe_odata_get_all(
            odata_url($base, ENTITY_SALESPERSON_CARD, [
                '$select' => 'Code,Global_Dimension_1_Code,Global_Dimension_2_Code',
                '$filter' => "Code eq '" . $escapedSalesperson . "'",
                '$top' => '1',
            ]),
            $auth,
            ODATA_TTL_SECONDS,
            'accountmanager kaart'
        );
        $salespersonCard = $salespersonCardRows[0] ?? [];

        $spDim1Value = trim((string) ($salespersonCard['Global_Dimension_1_Code'] ?? ''));
        $spDim2Value = trim((string) ($salespersonCard['Global_Dimension_2_Code'] ?? ''));

        if ($spDim1Value !== '') {
            $selectedDepartmentCode = $spDim1Value;
            $selectedDepartmentDimensionCode = $globalDim1;
        } elseif ($spDim2Value !== '') {
            $selectedDepartmentCode = $spDim2Value;
            $selectedDepartmentDimensionCode = $globalDim2;
        }

        if ($selectedDepartmentCode !== '') {
            $dimensionFilterParts = [];
            if ($selectedDepartmentDimensionCode !== '') {
                $dimensionFilterParts[] = "Dimension_Code eq '" . odata_quote_string_value($selectedDepartmentDimensionCode) . "'";
            }
            $dimensionFilterParts[] = "Code eq '" . odata_quote_string_value($selectedDepartmentCode) . "'";

            $dimensionRows = safe_odata_get_all(
                odata_url($base, ENTITY_DIMENSION_VALUES, [
                    '$select' => 'Dimension_Code,Code,Name',
                    '$filter' => implode(' and ', $dimensionFilterParts),
                    '$top' => '1',
                ]),
                $auth,
                ODATA_TTL_SECONDS,
                'dimensiewaarden'
            );

            if ($dimensionRows === [] && $selectedDepartmentDimensionCode !== '') {
                $dimensionRows = safe_odata_get_all(
                    odata_url($base, ENTITY_DIMENSION_VALUES, [
                        '$select' => 'Dimension_Code,Code,Name',
                        '$filter' => "Code eq '" . odata_quote_string_value($selectedDepartmentCode) . "'",
                        '$top' => '1',
                    ]),
                    $auth,
                    ODATA_TTL_SECONDS,
                    'dimensiewaarden'
                );
            }

            if ($dimensionRows !== []) {
                $selectedDepartmentName = trim((string) ($dimensionRows[0]['Name'] ?? ''));
                if ($selectedDepartmentDimensionCode === '') {
                    $selectedDepartmentDimensionCode = trim((string) ($dimensionRows[0]['Dimension_Code'] ?? ''));
                }
            }
        }

        $quoteFilterParts = [
            "Salesperson_Code eq '" . $escapedSalesperson . "'",
            'Quote_Valid_Until_Date ge ' . $dateFrom,
            'Quote_Valid_Until_Date le ' . $dateTo,
        ];

        $quotesUrl = odata_url($base, ENTITY_SALES_QUOTES, [
            '$select' => 'No,Sell_to_Customer_No,Sell_to_Customer_Name,Salesperson_Code,Opportunity_No,Status,LVS_Document_Status,LVS_Job_Type,Amount,Quote_Valid_Until_Date',
            '$filter' => implode(' and ', $quoteFilterParts),
            '$orderby' => 'Quote_Valid_Until_Date desc',
        ]);
        $quotes = safe_odata_get_all($quotesUrl, $auth, ODATA_TTL_SECONDS, 'offertes');

        $quoteNos = [];
        $opportunityNos = [];
        foreach ($quotes as $quote) {
            $quoteNo = trim((string) ($quote['No'] ?? ''));
            if ($quoteNo !== '') {
                $quoteNos[$quoteNo] = true;
            }

            $opportunityNo = trim((string) ($quote['Opportunity_No'] ?? ''));
            if ($opportunityNo !== '') {
                $opportunityNos[$opportunityNo] = true;
            }
        }

        $allQuoteLines = [];
        foreach (array_chunk(array_keys($quoteNos), 20) as $quoteNoChunk) {
            $chunkFilter = build_or_filter('Document_No', $quoteNoChunk);
            if ($chunkFilter === '') {
                continue;
            }

            $linesUrl = odata_url($base, ENTITY_SALES_QUOTE_LINES, [
                '$select' => 'Document_No,Document_Type,Line_Amount,Total_Amount_Excl_VAT,KVT_Total_Costs_Line_LCY',
                '$filter' => "Document_Type eq 'Quote' and " . $chunkFilter,
            ]);
            $allQuoteLines = array_merge(
                $allQuoteLines,
                safe_odata_get_all($linesUrl, $auth, ODATA_TTL_SECONDS, 'offertelines')
            );
        }

        $salesDocuments = [];
        foreach (array_chunk(array_keys($quoteNos), 20) as $quoteNoChunk) {
            $chunkFilter = build_or_filter('quoteNumber', $quoteNoChunk);
            if ($chunkFilter === '') {
                continue;
            }

            $documentsUrl = odata_url($base, ENTITY_SALES_DOCUMENTS, [
                '$select' => 'quoteNumber,quoteAccepted,quoteAcceptedDate,salespersonCode,documentType,opportunityNumber',
                '$filter' => $chunkFilter,
            ]);
            $salesDocuments = array_merge(
                $salesDocuments,
                safe_odata_get_all($documentsUrl, $auth, ODATA_TTL_SECONDS, 'sales documenten')
            );
        }

        $opportunities = [];
        foreach (array_chunk(array_keys($opportunityNos), 20) as $opportunityChunk) {
            $chunkFilter = build_or_filter('No', $opportunityChunk);
            if ($chunkFilter === '') {
                continue;
            }

            $opportunitiesUrl = odata_url($base, ENTITY_SALES_OPPORTUNITIES, [
                '$select' => 'No,Closed,Status,KVT_Close_Opportunity_Code,KVT_Close_Opp_Code_Description,Salesperson_Code',
                '$filter' => $chunkFilter,
            ]);
            $opportunities = array_merge(
                $opportunities,
                safe_odata_get_all($opportunitiesUrl, $auth, ODATA_TTL_SECONDS, 'opportunities')
            );
        }

        $quoteNoSet = $quoteNos;

        $lineTotalsByQuote = [];
        foreach ($allQuoteLines as $line) {
            $docNo = trim((string) ($line['Document_No'] ?? ''));
            if ($docNo === '' || !isset($quoteNoSet[$docNo])) {
                continue;
            }

            if (!isset($lineTotalsByQuote[$docNo])) {
                $lineTotalsByQuote[$docNo] = ['revenue' => 0.0, 'cost' => 0.0];
            }

            $lineRevenue = as_float($line['Total_Amount_Excl_VAT'] ?? 0);
            if ($lineRevenue === 0.0) {
                $lineRevenue = as_float($line['Line_Amount'] ?? 0);
            }

            $lineCost = as_float($line['KVT_Total_Costs_Line_LCY'] ?? 0);

            $lineTotalsByQuote[$docNo]['revenue'] += $lineRevenue;
            $lineTotalsByQuote[$docNo]['cost'] += $lineCost;
        }

        $salesDocumentsByQuote = [];
        foreach ($salesDocuments as $doc) {
            $quoteNo = trim((string) ($doc['quoteNumber'] ?? ''));
            if ($quoteNo === '') {
                continue;
            }
            $salesDocumentsByQuote[$quoteNo] = $doc;
        }

        $opportunitiesByNo = [];
        foreach ($opportunities as $opp) {
            $oppNo = trim((string) ($opp['No'] ?? ''));
            if ($oppNo === '') {
                continue;
            }
            $opportunitiesByNo[$oppNo] = $opp;
        }

        foreach ($quotes as $quote) {
            $quoteNo = trim((string) ($quote['No'] ?? ''));
            if ($quoteNo === '') {
                continue;
            }

            $offerType = detect_offer_type($quote);
            if ($selectedOfferType === 'direct' && $offerType !== 'Direct Sales') {
                continue;
            }
            if ($selectedOfferType === 'project' && $offerType !== 'Project') {
                continue;
            }

            $revenue = as_float($quote['Amount'] ?? 0);
            $cost = 0.0;
            if (isset($lineTotalsByQuote[$quoteNo])) {
                if ($lineTotalsByQuote[$quoteNo]['revenue'] > 0) {
                    $revenue = $lineTotalsByQuote[$quoteNo]['revenue'];
                }
                $cost = $lineTotalsByQuote[$quoteNo]['cost'];
            }
            $profit = $revenue - $cost;

            $projectType = trim((string) ($quote['LVS_Job_Type'] ?? ''));
            if ($offerType === 'Project' && $projectType === '') {
                $projectType = 'Onbekend';
            }

            $result = detect_result_status($quote, $salesDocumentsByQuote, $opportunitiesByNo);

            $row = [
                'quote_no' => $quoteNo,
                'customer_no' => trim((string) ($quote['Sell_to_Customer_No'] ?? '')),
                'customer_name' => trim((string) ($quote['Sell_to_Customer_Name'] ?? '')),
                'salesperson_code' => trim((string) ($quote['Salesperson_Code'] ?? '')),
                'opportunity_no' => trim((string) ($quote['Opportunity_No'] ?? '')),
                'status' => trim((string) ($quote['Status'] ?? '')),
                'document_status' => trim((string) ($quote['LVS_Document_Status'] ?? '')),
                'project_type' => $projectType,
                'offer_type' => $offerType,
                'quote_valid_until' => trim((string) ($quote['Quote_Valid_Until_Date'] ?? '')),
                'result' => $result,
                'revenue' => $revenue,
                'cost' => $cost,
                'profit' => $profit,
            ];

            $rows[] = $row;

            $totals['count']++;
            $totals['revenue'] += $revenue;
            $totals['cost'] += $cost;
            $totals['profit'] += $profit;

            if ($offerType === 'Direct Sales') {
                $customerKey = $row['customer_no'] !== '' ? $row['customer_no'] : $row['customer_name'];
                if ($customerKey === '') {
                    $customerKey = '(onbekende klant)';
                }

                if (!isset($directCustomerSummary[$customerKey])) {
                    $directCustomerSummary[$customerKey] = [
                        'customer_no' => $row['customer_no'],
                        'customer_name' => $row['customer_name'] !== '' ? $row['customer_name'] : '(onbekende klant)',
                        'quotes' => 0,
                        'revenue' => 0.0,
                        'cost' => 0.0,
                        'profit' => 0.0,
                    ];
                }

                $directCustomerSummary[$customerKey]['quotes']++;
                $directCustomerSummary[$customerKey]['revenue'] += $revenue;
                $directCustomerSummary[$customerKey]['cost'] += $cost;
                $directCustomerSummary[$customerKey]['profit'] += $profit;
            }

            if ($offerType === 'Project') {
                $projectKey = $projectType !== '' ? $projectType : 'Onbekend';
                if (!isset($projectTypeSummary[$projectKey])) {
                    $projectTypeSummary[$projectKey] = [
                        'project_type' => $projectKey,
                        'quotes' => 0,
                        'revenue' => 0.0,
                    ];
                }

                $projectTypeSummary[$projectKey]['quotes']++;
                $projectTypeSummary[$projectKey]['revenue'] += $revenue;
            }
        }

        uasort($directCustomerSummary, static function (array $a, array $b): int {
            return $b['revenue'] <=> $a['revenue'];
        });

        uasort($projectTypeSummary, static function (array $a, array $b): int {
            return $b['quotes'] <=> $a['quotes'];
        });
    }
} catch (Throwable $e) {
    $errorMessage = $e->getMessage();
}
?>
<!doctype html>
<html lang="nl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Offertes per accountmanager</title>
    <link rel="manifest" href="site.webmanifest">
    <style>
        :root {
            --bg-a: #f4f6f1;
            --bg-b: #dbe8d8;
            --card: #ffffff;
            --ink: #152019;
            --ink-soft: #4b5f50;
            --ok: #0e8a53;
            --warn: #8a650e;
            --bad: #9f2f2f;
            --line: #d3dfd2;
            --accent: #1f6f4a;
            --accent-2: #114c32;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: "Segoe UI", "Trebuchet MS", sans-serif;
            color: var(--ink);
            background:
                radial-gradient(circle at 20% 10%, #ffffff 0%, transparent 40%),
                linear-gradient(145deg, var(--bg-a), var(--bg-b));
            min-height: 100vh;
        }

        .page {
            width: min(1550px, 94vw);
            margin: 2rem auto 3rem;
        }

        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 14px;
            box-shadow: 0 8px 22px rgba(20, 40, 24, 0.08);
        }

        .header {
            padding: 1.2rem 1.3rem;
            margin-bottom: 1rem;
        }

        h1 {
            margin: 0;
            font-size: clamp(1.3rem, 2.8vw, 2rem);
            letter-spacing: 0.01em;
        }

        .subtitle {
            margin: 0.4rem 0 0;
            color: var(--ink-soft);
        }

        form.filters {
            display: grid;
            grid-template-columns: minmax(240px, 1fr) 220px 170px 170px auto;
            gap: 0.7rem;
            padding: 1rem;
            margin-bottom: 1rem;
            align-items: end;
        }

        label {
            display: grid;
            gap: 0.3rem;
            font-size: 0.95rem;
            font-weight: 600;
        }

        select,
        input,
        button {
            width: 100%;
            border: 1px solid #a7c1af;
            border-radius: 10px;
            min-height: 42px;
            padding: 0.45rem 0.65rem;
            font-size: 0.95rem;
        }

        button {
            background: linear-gradient(180deg, var(--accent), var(--accent-2));
            color: #fff;
            border: none;
            font-weight: 700;
            cursor: pointer;
            transition: transform 120ms ease, box-shadow 120ms ease;
        }

        button:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 14px rgba(17, 76, 50, 0.25);
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 0.8rem;
            margin-bottom: 1rem;
        }

        .stat {
            padding: 1rem;
        }

        .stat .k {
            margin: 0;
            color: var(--ink-soft);
            font-size: 0.85rem;
        }

        .stat .v {
            margin: 0.3rem 0 0;
            font-size: 1.25rem;
            font-weight: 700;
        }

        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .section {
            padding: 1rem;
        }

        .section h2 {
            margin: 0 0 0.8rem;
            font-size: 1.05rem;
        }

        .table-wrap {
            overflow: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.91rem;
        }

        th,
        td {
            padding: 0.55rem 0.5rem;
            border-bottom: 1px solid var(--line);
            text-align: left;
            vertical-align: top;
            white-space: nowrap;
        }

        th {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: var(--ink-soft);
            position: sticky;
            top: 0;
            background: #f8fbf8;
        }

        .chip {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 0.16rem 0.52rem;
            font-size: 0.78rem;
            font-weight: 700;
            border: 1px solid #c0d3c4;
            background: #edf4ee;
        }

        .chip.ok {
            border-color: #9cd8bb;
            color: #0b6f41;
            background: #e8f7ef;
        }

        .chip.bad {
            border-color: #e0adad;
            color: #922f2f;
            background: #faecec;
        }

        .chip.warn {
            border-color: #e1d2aa;
            color: #7b5b0d;
            background: #f7f2e3;
        }

        tr.row-ok {
            background: #f3fcf7;
        }

        tr.row-bad {
            background: #fdf4f4;
        }

        tr.row-warn {
            background: #fdf9f0;
        }

        .chip.direct {
            border-color: #a8c8f0;
            color: #1a4f8a;
            background: #e8f2fc;
        }

        .chip.project {
            border-color: #8ecfa8;
            color: #0b6f41;
            background: #e8f7ef;
        }

        th.sortable {
            cursor: pointer;
            user-select: none;
        }

        th.sortable::after {
            content: ' ⇅';
            opacity: 0.35;
            font-size: 0.75em;
        }

        th.sortable.asc::after {
            content: ' ↑';
            opacity: 0.8;
        }

        th.sortable.desc::after {
            content: ' ↓';
            opacity: 0.8;
        }

        .summary-clickable {
            cursor: pointer;
            transition: background 140ms ease;
        }

        .summary-clickable:hover {
            background: #eef6ef;
        }

        #offers-table tbody tr>td {
            transition: filter 2200ms cubic-bezier(0.16, 1, 0.3, 1), opacity 2200ms cubic-bezier(0.16, 1, 0.3, 1);
            will-change: filter, opacity;
        }

        #offers-table tbody tr {
            transition: filter 2200ms cubic-bezier(0.16, 1, 0.3, 1);
            will-change: filter;
        }

        #offers-table tbody tr.dimmed-temp>td {
            opacity: 0.52;
        }

        #offers-table tbody tr.dimmed-temp {
            filter: grayscale(1);
        }

        .muted {
            color: var(--ink-soft);
        }

        .error {
            border: 1px solid #e3a5a5;
            background: #fff1f1;
            color: #812020;
            border-radius: 10px;
            padding: 0.8rem 0.9rem;
            margin-bottom: 1rem;
        }

        @media (max-width: 1080px) {
            .stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .grid-2 {
                grid-template-columns: 1fr;
            }

            form.filters {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <main class="page">
        <section class="card header">
            <h1>Offertes per accountmanager</h1>
            <p class="subtitle">Kies een accountmanager om offertes op te halen, inclusief Direct Sales vs Project,
                omzet/kosten/opbrengst en won/lost status.</p>
        </section>

        <?php if ($errorMessage !== ''): ?>
            <div class="error"><?php echo h($errorMessage); ?></div>
        <?php endif; ?>

        <form class="filters card" method="get" action="index.php">
            <label>
                Accountmanager
                <select name="salesperson" required>
                    <option value="">Selecteer accountmanager...</option>
                    <?php foreach ($salespersons as $sp): ?>
                        <?php $code = trim((string) ($sp['Code'] ?? '')); ?>
                        <?php $name = trim((string) ($sp['Name'] ?? $code)); ?>
                        <option value="<?php echo h($code); ?>" <?php echo $selectedSalesperson === $code ? 'selected' : ''; ?>>
                            <?php echo h($name . ($code !== '' ? ' (' . $code . ')' : '')); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Type offerte
                <select name="offer_type">
                    <option value="all" <?php echo $selectedOfferType === 'all' ? 'selected' : ''; ?>>Alles</option>
                    <option value="direct" <?php echo $selectedOfferType === 'direct' ? 'selected' : ''; ?>>Direct Sales
                    </option>
                    <option value="project" <?php echo $selectedOfferType === 'project' ? 'selected' : ''; ?>>Projecten
                    </option>
                </select>
            </label>

            <label>
                Vanaf
                <input type="date" name="date_from" value="<?php echo h($dateFrom); ?>">
            </label>

            <label>
                Tot
                <input type="date" name="date_to" value="<?php echo h($dateTo); ?>">
            </label>

            <button type="submit">Offertes ophalen</button>
        </form>

        <?php if ($selectedSalesperson !== '' && $errorMessage === ''): ?>
            <section class="stats">
                <article class="card stat">
                    <p class="k">Aantal offertes</p>
                    <p class="v"><?php echo h((string) $totals['count']); ?></p>
                </article>
                <article class="card stat">
                    <p class="k">Omzet (opbrengst)</p>
                    <p class="v">EUR <?php echo h(q($totals['revenue'])); ?></p>
                </article>
                <article class="card stat">
                    <p class="k">Kosten</p>
                    <p class="v">EUR <?php echo h(q($totals['cost'])); ?></p>
                </article>
                <article class="card stat">
                    <p class="k">Marge</p>
                    <p class="v">EUR <?php echo h(q($totals['profit'])); ?></p>
                </article>
            </section>

            <section class="card section" style="margin-bottom: 1rem;">
                <h2>Geselecteerde accountmanager</h2>
                <p style="margin: 0.2rem 0 0.6rem;">
                    <?php echo h($selectedSalespersonName !== '' ? $selectedSalespersonName : $selectedSalesperson); ?>
                    (<?php echo h($selectedSalesperson); ?>)
                </p>
                <p class="muted" style="margin: 0;">
                    Afdeling:
                    <?php if ($selectedDepartmentCode !== ''): ?>
                        <?php
                        $departmentLabel = $selectedDepartmentCode;
                        if ($selectedDepartmentName !== '') {
                            $departmentLabel .= ' - ' . $selectedDepartmentName;
                        }
                        if ($selectedDepartmentDimensionCode !== '') {
                            $departmentLabel .= ' (' . $selectedDepartmentDimensionCode . ')';
                        }
                        echo h($departmentLabel);
                        ?>
                    <?php else: ?>
                        onbekend
                    <?php endif; ?>
                </p>
            </section>

            <section class="grid-2">
                <article class="card section">
                    <h2>Direct Sales per klant</h2>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Klant</th>
                                    <th>Offertes</th>
                                    <th>Omzet</th>
                                    <th>Kosten</th>
                                    <th>Marge</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($directCustomerSummary === []): ?>
                                    <tr>
                                        <td colspan="5" class="muted">Geen Direct Sales gevonden voor deze selectie.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($directCustomerSummary as $summary): ?>
                                        <tr class="summary-clickable"
                                            data-jump-customer-no="<?php echo h((string) $summary['customer_no']); ?>"
                                            data-jump-customer-name="<?php echo h((string) $summary['customer_name']); ?>">
                                            <td><?php echo h($summary['customer_name']); ?></td>
                                            <td><?php echo h((string) $summary['quotes']); ?></td>
                                            <td>EUR <?php echo h(q($summary['revenue'])); ?></td>
                                            <td>EUR <?php echo h(q($summary['cost'])); ?></td>
                                            <td>EUR <?php echo h(q($summary['profit'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </article>

                <article class="card section">
                    <h2>Projecten per projectsoort</h2>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Projectsoort</th>
                                    <th>Offertes</th>
                                    <th>Omzet</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($projectTypeSummary === []): ?>
                                    <tr>
                                        <td colspan="3" class="muted">Geen projectoffertes gevonden voor deze selectie.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($projectTypeSummary as $summary): ?>
                                        <tr class="summary-clickable"
                                            data-jump-project-type="<?php echo h((string) $summary['project_type']); ?>">
                                            <td><?php echo h($summary['project_type']); ?></td>
                                            <td><?php echo h((string) $summary['quotes']); ?></td>
                                            <td>EUR <?php echo h(q($summary['revenue'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
            </section>

            <section class="card section">
                <h2>Offertes</h2>
                <p class="muted" style="margin-top: -0.2rem; margin-bottom: 0.7rem;">Klik op een regel in de twee
                    samenvattingen hierboven om die klant of projectsoort bovenaan te zetten.</p>
                <div class="table-wrap">
                    <table id="offers-table">
                        <thead>
                            <tr>
                                <th class="sortable" data-col="0">Offerte</th>
                                <th class="sortable" data-col="1">Type</th>
                                <th class="sortable" data-col="2">Klant</th>
                                <th class="sortable" data-col="3">Opportunity #</th>
                                <th class="sortable" data-col="4">Projectsoort</th>
                                <th class="sortable" data-col="5">Resultaat</th>
                                <th class="sortable" data-col="6">Status</th>
                                <th class="sortable" data-col="7" data-type="date">Geldig t/m</th>
                                <th class="sortable" data-col="8" data-type="num">Omzet</th>
                                <th class="sortable" data-col="9" data-type="num">Kosten</th>
                                <th class="sortable" data-col="10" data-type="num">Marge</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($rows === []): ?>
                                <tr>
                                    <td colspan="11" class="muted">Geen offertes gevonden met deze filters.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($rows as $row): ?>
                                    <?php
                                    $resultClass = 'warn';
                                    if ($row['result'] === 'Gewonnen') {
                                        $resultClass = 'ok';
                                    } elseif ($row['result'] === 'Verloren') {
                                        $resultClass = 'bad';
                                    }
                                    $typeClass = $row['offer_type'] === 'Project' ? 'project' : 'direct';
                                    ?>
                                    <tr class="row-<?php echo h($resultClass); ?>"
                                        data-offer-type="<?php echo h($row['offer_type']); ?>"
                                        data-customer-no="<?php echo h($row['customer_no']); ?>"
                                        data-customer-name="<?php echo h($row['customer_name']); ?>"
                                        data-project-type="<?php echo h($row['project_type']); ?>">
                                        <td><?php echo h($row['quote_no']); ?></td>
                                        <td><span
                                                class="chip <?php echo h($typeClass); ?>"><?php echo h($row['offer_type']); ?></span>
                                        </td>
                                        <td><?php echo h($row['customer_name']); ?></td>
                                        <td><?php echo h($row['offer_type'] === 'Project' ? ($row['opportunity_no'] !== '' ? $row['opportunity_no'] : '-') : '-'); ?>
                                        </td>
                                        <td><?php echo h($row['offer_type'] === 'Project' ? ($row['project_type'] !== '' ? $row['project_type'] : 'Onbekend') : '-'); ?>
                                        </td>
                                        <td><span
                                                class="chip <?php echo h($resultClass); ?>"><?php echo h($row['result']); ?></span>
                                        </td>
                                        <td><?php echo h(trim($row['status'] . ' ' . $row['document_status'])); ?></td>
                                        <td data-sort="<?php echo h($row['quote_valid_until']); ?>">
                                            <?php echo h($row['quote_valid_until'] !== '' ? $row['quote_valid_until'] : '-'); ?>
                                        </td>
                                        <td data-sort="<?php echo h((string) $row['revenue']); ?>">EUR
                                            <?php echo h(q($row['revenue'])); ?>
                                        </td>
                                        <td data-sort="<?php echo h((string) $row['cost']); ?>">EUR
                                            <?php echo h(q($row['cost'])); ?>
                                        </td>
                                        <td data-sort="<?php echo h((string) $row['profit']); ?>">EUR
                                            <?php echo h(q($row['profit'])); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>
    </main>

    <script>
        (function ()
        {
            function animateRowReorder (tbody, orderedRows)
            {
                var firstTops = new Map();
                orderedRows.forEach(function (row)
                {
                    firstTops.set(row, row.getBoundingClientRect().top);
                });

                orderedRows.forEach(function (row)
                {
                    tbody.appendChild(row);
                });

                orderedRows.forEach(function (row)
                {
                    var firstTop = firstTops.get(row);
                    var lastTop = row.getBoundingClientRect().top;
                    var deltaY = firstTop - lastTop;

                    row.style.transition = 'none';
                    row.style.transform = 'translateY(' + deltaY + 'px)';
                });

                void tbody.offsetHeight;

                orderedRows.forEach(function (row)
                {
                    row.style.transition = 'transform 420ms cubic-bezier(0.22, 1, 0.36, 1)';
                    row.style.transform = 'translateY(0)';
                    row.addEventListener('transitionend', function ()
                    {
                        row.style.transition = '';
                        row.style.transform = '';
                    }, { once: true });
                });
            }

            function setTemporaryDimmed (rows, durationMs, indexOffset)
            {
                rows.forEach(function (row, index)
                {
                    row.classList.add('dimmed-temp');
                    if (row._dimmedTimer)
                    {
                        clearTimeout(row._dimmedTimer);
                    }
                    var delayMs = durationMs + ((indexOffset + index) * 100);
                    row._dimmedTimer = setTimeout(function ()
                    {
                        row.classList.remove('dimmed-temp');
                        row._dimmedTimer = null;
                    }, delayMs);
                });
            }

            function moveMatchesToTop (matchFn)
            {
                var offersTable = document.getElementById('offers-table');
                if (!offersTable)
                {
                    return;
                }

                var tbody = offersTable.querySelector('tbody');
                if (!tbody)
                {
                    return;
                }

                var rows = Array.from(tbody.querySelectorAll('tr'));
                var validRows = rows.filter(function (row)
                {
                    var onlyCell = row.cells.length === 1 ? row.cells[0] : null;
                    return !(onlyCell && onlyCell.hasAttribute('colspan'));
                });

                var matched = [];
                var rest = [];
                validRows.forEach(function (row)
                {
                    if (matchFn(row))
                    {
                        matched.push(row);
                    } else
                    {
                        rest.push(row);
                    }
                });

                matched.forEach(function (row)
                {
                    row.classList.remove('dimmed-temp');
                    if (row._dimmedTimer)
                    {
                        clearTimeout(row._dimmedTimer);
                        row._dimmedTimer = null;
                    }
                });

                animateRowReorder(tbody, matched.concat(rest));
                setTemporaryDimmed(rest, 3000, matched.length);
            }

            document.querySelectorAll('tr[data-jump-customer-name]').forEach(function (row)
            {
                row.addEventListener('click', function ()
                {
                    var customerNo = (row.getAttribute('data-jump-customer-no') || '').trim();
                    var customerName = (row.getAttribute('data-jump-customer-name') || '').trim().toLowerCase();

                    moveMatchesToTop(function (offerRow)
                    {
                        var offerType = (offerRow.getAttribute('data-offer-type') || '').trim();
                        if (offerType !== 'Direct Sales')
                        {
                            return false;
                        }

                        var offerCustomerNo = (offerRow.getAttribute('data-customer-no') || '').trim();
                        var offerCustomerName = (offerRow.getAttribute('data-customer-name') || '').trim().toLowerCase();

                        if (customerNo !== '')
                        {
                            return offerCustomerNo === customerNo;
                        }
                        return offerCustomerName === customerName;
                    });
                });
            });

            document.querySelectorAll('tr[data-jump-project-type]').forEach(function (row)
            {
                row.addEventListener('click', function ()
                {
                    var projectType = (row.getAttribute('data-jump-project-type') || '').trim().toLowerCase();

                    moveMatchesToTop(function (offerRow)
                    {
                        var offerType = (offerRow.getAttribute('data-offer-type') || '').trim();
                        if (offerType !== 'Project')
                        {
                            return false;
                        }

                        var offerProjectType = (offerRow.getAttribute('data-project-type') || '').trim().toLowerCase();
                        return offerProjectType === projectType;
                    });
                });
            });

            document.querySelectorAll('table').forEach(function (table)
            {
                var ths = table.querySelectorAll('th.sortable');
                ths.forEach(function (th)
                {
                    th.addEventListener('click', function ()
                    {
                        var col = parseInt(th.getAttribute('data-col'), 10);
                        var type = th.getAttribute('data-type') || 'text';
                        var asc = !th.classList.contains('asc');

                        ths.forEach(function (h) { h.classList.remove('asc', 'desc'); });
                        th.classList.add(asc ? 'asc' : 'desc');

                        var tbody = table.querySelector('tbody');
                        var rows = Array.from(tbody.querySelectorAll('tr'));

                        rows.sort(function (a, b)
                        {
                            var cellA = a.cells[col];
                            var cellB = b.cells[col];
                            if (!cellA || !cellB) return 0;

                            var va = (cellA.getAttribute('data-sort') || cellA.textContent).trim();
                            var vb = (cellB.getAttribute('data-sort') || cellB.textContent).trim();

                            var result;
                            if (type === 'num')
                            {
                                result = (parseFloat(va) || 0) - (parseFloat(vb) || 0);
                            } else if (type === 'date')
                            {
                                result = va.localeCompare(vb);
                            } else
                            {
                                result = va.localeCompare(vb, 'nl', { sensitivity: 'base' });
                            }

                            return asc ? result : -result;
                        });

                        animateRowReorder(tbody, rows);
                    });
                });
            });
        }());
    </script>
</body>

</html>