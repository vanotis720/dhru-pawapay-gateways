<?php
//
// DHRU FUSION – PawaPay Mobile Money Webhook / Callback Handler
//
// PawaPay sends an HTTP POST with a JSON body to this URL whenever a deposit
// status changes (COMPLETED, FAILED, EXPIRED).
//
// Register this URL as the "Notifications URL" in your PawaPay dashboard:
//   https://{your-dhru-site}/modules/gateways/callback/pawapay.php
//
// Author  : Vander Otis
// Phone   : +243 974 944 879
// Email   : vanotis720@gmail.com
// YouTube : https://www.youtube.com/@vanderotis
//
define("DEFINE_MY_ACCESS", true);
define("DEFINE_DHRU_FILE", true);
include '../../../comm.php';
require '../../../includes/fun.inc.php';
include '../../../includes/gateway.fun.php';
include '../../../includes/invoice.fun.php';

// ---------------------------------------------------------------------------
// Debug file log – writes every incoming webhook hit to a plain text file.
// Check: {dhru-root}/modules/gateways/callback/webhook.log
// Remove or comment out this block once everything is working correctly.
// ---------------------------------------------------------------------------
$debugLog = __DIR__ . '/webhook.log';
$logEntry = '[' . date('Y-m-d H:i:s') . '] '
    . $_SERVER['REMOTE_ADDR']
    . ' | ' . ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN')
    . ' | ' . file_get_contents('php://input')
    . PHP_EOL;
file_put_contents($debugLog, $logEntry, FILE_APPEND | LOCK_EX);

// ---------------------------------------------------------------------------
// Load gateway configuration
// ---------------------------------------------------------------------------
$GATEWAY = loadGatewayModule('pawapay');

// ---------------------------------------------------------------------------
// Parse the incoming request body
// PawaPay sends JSON; support both raw body and $_POST for compatibility.
// ---------------------------------------------------------------------------
$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody, true);

if (!is_array($payload) || empty($payload)) {
    $payload = $_POST;
}

if (empty($payload)) {
    logTransaction('pawapay', array('raw' => $rawBody), 'Empty Payload');
    http_response_code(400);
    exit();
}

// ---------------------------------------------------------------------------
// Extract key fields from the PawaPay webhook payload
//
// PawaPay deposit notification shape:
// {
//   "depositId":         "uuid-36-chars",
//   "status":            "COMPLETED",   // or FAILED | EXPIRED
//   "amount":            "500.00",
//   "currency":          "CDF",
//   "payer":             { "type":"MMO", "accountDetails":{ "phoneNumber":"243xxxxxxxxx", "provider":"ORANGE_COD" } },
//   "correspondentIds":  { "TRANSACTION_REFERENCE":"ABC123", ... },
//   "clientReferenceId": "4966",
//   "failureReason":     { "failureCode": "...", "failureMessage": "..." }
// }
// ---------------------------------------------------------------------------
$depositId = (string) ($payload['depositId']  ?? '');
$status    = strtoupper((string) ($payload['status']   ?? ''));
$amount    = (string) ($payload['amount']   ?? '0');
$currency  = strtoupper((string) ($payload['currency'] ?? ''));

// Derive Dhru invoice ID from clientReferenceId (set by the gateway)
$invoiceId = '';
if (!empty($payload['clientReferenceId'])) {
    $invoiceId = (string) $payload['clientReferenceId'];
}

// Extract the correspondent (network) transaction reference as txn_id
$correspondentIds = $payload['correspondentIds'] ?? array();
$txnId = '';
foreach (array('TRANSACTION_REFERENCE', 'RECEIPT_NUMBER', 'MTN_REFERENCE', 'ORANGE_REFERENCE') as $key) {
    if (!empty($correspondentIds[$key])) {
        $txnId = (string) $correspondentIds[$key];
        break;
    }
}
// Final fallback: use the depositId itself as the txn reference
if (empty($txnId)) {
    $txnId = $depositId;
}

// ---------------------------------------------------------------------------
// Guard: require a valid invoice ID
// ---------------------------------------------------------------------------
if (empty($invoiceId) || !is_numeric($invoiceId)) {
    logTransaction('pawapay', $payload, 'Invalid Invoice ID');
    http_response_code(400);
    exit();
}

// ---------------------------------------------------------------------------
// Handle status
// ---------------------------------------------------------------------------

// COMPLETED → payment succeeded, credit the invoice
if ($status === 'COMPLETED') {

    // Prevent double-crediting for the same transaction reference
    if (!empty($txnId) && checkTransID($txnId)) {
        logTransaction('pawapay', $payload, 'Duplicate Transaction', 'invoice', $invoiceId);
        http_response_code(200);
        exit();
    }

    // Convert paid amount back to USD before crediting the Dhru invoice
    $creditAmountUsd = pawapay_convertToUsd((float) $amount, $currency, (string) ($GATEWAY['fx_rates'] ?? ''), $fxError);
    if ($creditAmountUsd === false) {
        logTransaction('pawapay', array(
            'invoiceid' => $invoiceId,
            'txnId'     => $txnId,
            'depositId' => $depositId,
            'currency'  => $currency,
            'amount'    => $amount,
            'fxError'   => $fxError,
            'payload'   => $payload,
        ), 'FX Conversion Error', 'invoice', $invoiceId);
        http_response_code(500);
        exit();
    }

    // addPayment($invoiceid, $transactionid, $amount, $fee, $gateway)
    addPayment($invoiceId, $txnId, (float) $creditAmountUsd, 0, 'pawapay');
    $payload['_credited_usd_amount'] = number_format((float) $creditAmountUsd, 2, '.', '');
    logTransaction('pawapay', $payload, 'Successful', 'invoice', $invoiceId);
    http_response_code(200);
    exit();
}

// FAILED / EXPIRED → log and return 200 so PawaPay stops retrying
if (in_array($status, array('FAILED', 'EXPIRED'))) {
    logTransaction('pawapay', $payload, ucfirst(strtolower($status)), 'invoice', $invoiceId);
    http_response_code(200);
    exit();
}

// Any other status (ACCEPTED, CREATED, ENQUEUED…) → still pending, log only
logTransaction('pawapay', $payload, 'Pending', 'invoice', $invoiceId);
http_response_code(200);
exit();

// ---------------------------------------------------------------------------
// Parse admin FX table and convert paid currency back to USD
// ---------------------------------------------------------------------------
function pawapay_parseFxRates($raw)
{
    $rates = array('USD' => 1.0);

    $raw = html_entity_decode((string) $raw, ENT_QUOTES, 'UTF-8');
    $raw = preg_replace('/<br\s*\/?>/i', "\n", $raw);
    $raw = strip_tags((string) $raw);
    $lines = preg_split('/\r\n|\r|\n/', (string) $raw);

    foreach ($lines as $line) {
        $line = str_replace(array("\xC2\xA0", "\xEF\xBB\xBF"), ' ', (string) $line);
        $line = trim((string) $line);
        if ($line === '' || strpos($line, '#') === 0 || strpos($line, ';') === 0) {
            continue;
        }

        $line = preg_replace('/\s*[#;].*$/', '', $line);
        $line = trim((string) $line);
        if ($line === '') {
            continue;
        }

        if (preg_match('/^([A-Za-z]{3})\s*(?:=|:|=>|->)?\s*([-+]?[0-9]+(?:[\.,][0-9]+)?)$/', $line, $m)) {
            $k = strtoupper((string) $m[1]);
            $v = (string) $m[2];
        } else {
            continue;
        }

        $num = preg_replace('/[^0-9,\.\-\+]/', '', (string) $v);
        if (strpos($num, ',') !== false && strpos($num, '.') !== false) {
            $num = str_replace(',', '', $num);
        } elseif (strpos($num, ',') !== false) {
            $num = str_replace(',', '.', $num);
        }

        $rate = (float) $num;
        if ($k !== '' && $rate > 0) {
            $rates[$k] = $rate;
        }
    }

    return $rates;
}

function pawapay_convertToUsd($amount, $currency, $fxRaw, &$error = '')
{
    $error = '';
    $amount = (float) $amount;
    $currency = strtoupper((string) $currency);

    if ($currency === '' || $currency === 'USD') {
        return round($amount, 2);
    }

    $rates = pawapay_parseFxRates($fxRaw);
    if (!isset($rates[$currency]) || (float) $rates[$currency] <= 0) {
        $error = 'Missing exchange rate for ' . $currency . ' in gateway admin settings.';
        return false;
    }

    return round($amount / (float) $rates[$currency], 2);
}
