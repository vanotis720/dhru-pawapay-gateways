<?php
//
// DHRU FUSION – PawaPay Mobile Money Payment Gateway
// Supports: Orange Money, Airtel Money, M-Pesa, MTN Mobile Money & more
// Compatible with PawaPay REST API v2  (https://docs.pawapay.io)
//
// Author  : Vander Otis
// Phone   : +243 974 944 879
// Email   : vanotis720@gmail.com
// YouTube : https://www.youtube.com/@vanderotis
//
defined("DEFINE_MY_ACCESS") or die('<h1 style="color: #C00; text-align: center;"><strong>Restricted Access</strong></h1>');

// ---------------------------------------------------------------------------
// Gateway configuration fields shown in Dhru Fusion admin
// ---------------------------------------------------------------------------
function pawapay_config()
{
    $configarray = array(
        'name' => array(
            'Type'  => 'System',
            'Value' => 'Mobile Money',
        ),
        'api_token' => array(
            'Name'        => 'API Bearer Token',
            'Type'        => 'text',
            'Size'        => '80',
            'Description' => 'PawaPay Bearer token from the merchant dashboard.',
        ),
        'api_url' => array(
            'Name'        => 'API Base URL',
            'Type'        => 'text',
            'Size'        => '60',
            'Description' => 'Production: https://api.pawapay.io &nbsp;|&nbsp; Sandbox: https://api.sandbox.pawapay.io',
        ),
        'sandbox' => array(
            'Name'        => 'Sandbox / Test Mode',
            'Type'        => 'yesno',
            'Description' => 'Enable to use the PawaPay sandbox environment.',
        ),
        'fx_rates' => array(
            'Name'        => 'USD Exchange Rates',
            'Type'        => 'textarea',
            'Cols'        => '70',
            'Rows'        => '10',
            'Description' => 'One per line: CURRENCY=RATE_FROM_USD (example: CDF=2800). USD=1 is always assumed.',
        ),
        'info' => array(
            'Name' => 'Notes',
            'Type' => 'textarea',
            'Cols' => '5',
            'Rows' => '5',
        ),
    );
    return $configarray;
}

// ---------------------------------------------------------------------------
// Build the payment widget shown on the Dhru invoice page
// ---------------------------------------------------------------------------
function pawapay_link($PARAMS)
{
    $invoiceId = (string) $PARAMS['invoiceid'];
    $amountUsd = (float) formatCurrency2($PARAMS['amount']);
    $currency  = strtoupper((string) $PARAMS['currency']);
    $firstName = htmlspecialchars((string) $PARAMS['clientdetails']['firstname']);
    $lastName  = htmlspecialchars((string) $PARAMS['clientdetails']['lastname']);
    $returnUrl = (string) $PARAMS['returnurl'];
    $systemUrl = rtrim((string) $PARAMS['systemurl'], '/');
    $apiToken  = (string) $PARAMS['api_token'];
    $apiUrl    = rtrim(
        $PARAMS['sandbox']
            ? 'https://api.sandbox.pawapay.io'
            : ((string) $PARAMS['api_url'] ?: 'https://api.pawapay.io'),
        '/'
    );
    $fxRates = pawapay_parseFxRates((string) ($PARAMS['fx_rates'] ?? ''));

    // Load active configuration (countries/providers/currencies)
    $activeConfig    = pawapay_apiGet($apiUrl . '/v2/active-conf', $apiToken);
    $paymentOptions  = pawapay_buildPaymentOptions($activeConfig);

    // Default phone pre-filled from client profile
    $profilePhone = pawapay_normalizePhone((string) $PARAMS['clientdetails']['phonenumber']);

    // -----------------------------------------------------------------------
    // Compute deterministic depositId (UUID v4-shaped, derived from invoiceId).
    // The same ID is reused across page loads for idempotency. Computed here
    // so both STEP 1 (status check) and STEP 2 (submission) share it.
    // -----------------------------------------------------------------------
    $hash      = md5('dhru-pawapay-' . $invoiceId);
    $depositId = sprintf(
        '%s-%s-4%s-%04x-%s',
        substr($hash, 0, 8),
        substr($hash, 8, 4),
        substr($hash, 13, 3),
        hexdec(substr($hash, 16, 4)) & 0x3fff | 0x8000,
        substr($hash, 20, 12)
    );

    // -----------------------------------------------------------------------
    // STEP 1 – No form submitted (user visiting / returning to invoice page).
    // Query PawaPay to see if a deposit was already initiated for this invoice.
    // -----------------------------------------------------------------------
    $formSubmitted = isset($_POST['pawapay_invoice_id']) && (string) $_POST['pawapay_invoice_id'] === $invoiceId;

    if (!$formSubmitted) {
        $existing = pawapay_apiGet($apiUrl . '/v2/deposits/' . $depositId, $apiToken);

        // Response shape: { "status": "FOUND", "data": { "depositId": "...", "status": "COMPLETED", ... } }
        if (is_array($existing) && ($existing['status'] ?? '') === 'FOUND' && isset($existing['data'])) {
            $existingDeposit  = $existing['data'];
            $existingStatus   = strtoupper((string) ($existingDeposit['status'] ?? ''));
            $existingPhone    = (string) ($existingDeposit['payer']['accountDetails']['phoneNumber'] ?? '');
            $existingProvider = (string) ($existingDeposit['payer']['accountDetails']['provider']    ?? '');

            // -----------------------------------------------------------------
            // Still waiting for user to approve on their phone
            // -----------------------------------------------------------------
            if (in_array($existingStatus, array('ACCEPTED', 'CREATED', 'PENDING'))) {
                $providerLabel = pawapay_providerLabel($existingProvider);
                $existingAmount   = (string) ($existingDeposit['requestedAmount'] ?? $existingDeposit['amount'] ?? '');
                $existingCurrency = strtoupper((string) ($existingDeposit['currency'] ?? ''));
                $c  = '<div style="padding:20px;border:1px solid #c3e6cb;background:#d4edda;border-radius:6px;max-width:480px;margin:0 auto;text-align:center;font-family:Arial,sans-serif;">';
                $c .= '<h3 style="margin-top:0;color:#155724;">&#128242; Awaiting Your Approval</h3>';
                $displayCurrency = $existingCurrency ?: $currency;
                $displayAmount   = $existingAmount !== '' ? (float) $existingAmount : (float) $amountUsd;
                $c .= '<p style="color:#155724;">A payment prompt for <strong>' . htmlspecialchars($displayCurrency) . ' ' . htmlspecialchars(number_format($displayAmount, 2)) . '</strong>';
                if ($existingPhone) {
                    $c .= ' was sent to <strong>' . htmlspecialchars($existingPhone) . '</strong>';
                }
                if ($existingProvider) {
                    $c .= ' via <strong>' . htmlspecialchars($providerLabel) . '</strong>';
                }
                $c .= '.</p>';
                $c .= '<p style="color:#155724;margin-top:10px;">Please check your phone and <strong>enter your PIN</strong> to approve the payment.</p>';
                $c .= '<hr style="border:none;border-top:1px solid #b1dfbb;margin:15px 0;">';
                $c .= '<a href="' . htmlspecialchars($returnUrl) . '" style="display:inline-block;background:#28a745;color:#fff;text-decoration:none;padding:12px 28px;border-radius:4px;font-size:15px;font-weight:bold;">&#10003; Return to invoice</a>';
                $c .= '</div>';
                return $c;
            }

            // -----------------------------------------------------------------
            // Payment completed — webhook should have already credited the invoice
            // -----------------------------------------------------------------
            if ($existingStatus === 'COMPLETED') {
                $c  = '<div style="padding:20px;border:1px solid #c3e6cb;background:#d4edda;border-radius:6px;max-width:480px;margin:0 auto;text-align:center;font-family:Arial,sans-serif;">';
                $c .= '<h3 style="margin-top:0;color:#155724;">&#10003; Payment Completed</h3>';
                $c .= '<p style="color:#155724;">Your payment has been received. Your invoice will be updated shortly.</p>';
                $c .= '<a href="' . htmlspecialchars($returnUrl) . '" style="display:inline-block;background:#28a745;color:#fff;text-decoration:none;padding:12px 28px;border-radius:4px;font-size:15px;font-weight:bold;">Return to invoice</a>';
                $c .= '</div>';
                return $c;
            }

            // -----------------------------------------------------------------
            // Payment failed or expired — log it and ask user to create a new invoice
            // -----------------------------------------------------------------
            if (in_array($existingStatus, array('FAILED', 'EXPIRED'))) {
                $failCode = (string) ($existingDeposit['failureReason']['failureCode'] ?? '');
                $failMsg  = (string) ($existingDeposit['failureReason']['failureMessage'] ?? '');
                $reason   = $failCode ? $failCode . ': ' . $failMsg : ($failMsg ?: $existingStatus);

                logTransaction('pawapay', array(
                    'invoiceid'   => $invoiceId,
                    'depositId'   => $depositId,
                    'status'      => $existingStatus,
                    'failureCode' => $failCode ?: 'n/a',
                    'failureMsg'  => $failMsg  ?: 'n/a',
                    'source'      => 'status-check-on-return',
                ), ucfirst(strtolower($existingStatus)), 'invoice', $invoiceId);

                $c  = '<div style="padding:20px;border:1px solid #f5c6cb;background:#f8d7da;border-radius:6px;max-width:480px;margin:0 auto;text-align:center;font-family:Arial,sans-serif;">';
                $c .= '<h3 style="margin-top:0;color:#721c24;">&#10060; Payment ' . ucfirst(strtolower($existingStatus)) . '</h3>';
                $c .= '<p style="color:#721c24;">Your Mobile Money payment could not be completed.</p>';
                if ($reason) {
                    $c .= '<p style="color:#721c24;font-size:13px;">Reason: <strong>' . htmlspecialchars($reason) . '</strong></p>';
                }
                $c .= '<hr style="border:none;border-top:1px solid #f5c6cb;margin:15px 0;">';
                $c .= '<p style="color:#555;font-size:13px;">This payment has been marked as failed. To pay, please <strong>create a new invoice</strong> and try again.</p>';
                $c .= '</div>';
                return $c;
            }
        }

        // No existing deposit found (first visit) → show the payment form
        return pawapay_renderForm($invoiceId, $amountUsd, $currency, $profilePhone, '', '', '', $returnUrl, $paymentOptions, $fxRates);
    }

    // -----------------------------------------------------------------------
    // STEP 2 – Form was submitted, use user-provided phone + provider
    // -----------------------------------------------------------------------
    $phone    = pawapay_normalizePhone((string) ($_POST['pawapay_phone']    ?? $profilePhone));
    $country  = strtoupper(trim((string) ($_POST['pawapay_country']  ?? '')));
    $provider = strtoupper(trim((string) ($_POST['pawapay_provider'] ?? '')));
    $payCurrency = strtoupper(trim((string) ($_POST['pawapay_currency'] ?? '')));

    $countries = $paymentOptions['countries'] ?? array();
    $targetCurrency = $payCurrency;
    if (!empty($country) && isset($countries[$country])) {
        $countryCurrencies = $countries[$country]['currencies'] ?? array();
        if (empty($targetCurrency) && !empty($countryCurrencies)) {
            $targetCurrency = strtoupper((string) $countryCurrencies[0]);
        }
    }

    if (empty($phone) || empty($country) || empty($provider) || empty($targetCurrency)) {
        return pawapay_renderForm(
            $invoiceId,
            $amountUsd,
            $currency,
            $profilePhone,
            $country,
            $provider,
            $targetCurrency,
            $returnUrl,
            $paymentOptions,
            $fxRates,
            'Please enter your phone number, select a country, and select a provider.'
        );
    }

    if (!isset($countries[$country]['providers'][$provider])) {
        return pawapay_renderForm(
            $invoiceId,
            $amountUsd,
            $currency,
            $phone,
            $country,
            '',
            $targetCurrency,
            $returnUrl,
            $paymentOptions,
            $fxRates,
            'Selected provider is not available for the selected country.'
        );
    }

    if (empty($countries[$country]['currencies']) || !in_array($targetCurrency, $countries[$country]['currencies'])) {
        return pawapay_renderForm(
            $invoiceId,
            $amountUsd,
            $currency,
            $phone,
            $country,
            $provider,
            $targetCurrency,
            $returnUrl,
            $paymentOptions,
            $fxRates,
            'Selected currency is not available for the selected country.'
        );
    }

    $convertedAmount = pawapay_convertUsdAmount($amountUsd, $targetCurrency, $fxRates, $conversionError);
    if ($convertedAmount === false) {
        return pawapay_renderForm(
            $invoiceId,
            $amountUsd,
            $currency,
            $phone,
            $country,
            $provider,
            $targetCurrency,
            $returnUrl,
            $paymentOptions,
            $fxRates,
            $conversionError
        );
    }

    // Strict deposit limit validation (from active-conf)
    $limitInfo = pawapay_getProviderLimitInfo($countries, $country, $provider, $targetCurrency);
    if (($limitInfo['valid'] ?? false) !== true) {
        return pawapay_renderForm(
            $invoiceId,
            $amountUsd,
            $currency,
            $phone,
            $country,
            $provider,
            $targetCurrency,
            $returnUrl,
            $paymentOptions,
            $fxRates,
            (string) ($limitInfo['message'] ?? 'Unable to validate provider transaction limits.')
        );
    }

    if (($limitInfo['enforced'] ?? true) !== true) {
        logTransaction('pawapay', array(
            'invoiceid' => $invoiceId,
            'country'   => $country,
            'provider'  => $provider,
            'currency'  => $targetCurrency,
            'note'      => (string) ($limitInfo['message'] ?? 'Provider limits unavailable; proceeding without min/max enforcement.'),
        ), 'Limit Check Skipped', 'invoice', $invoiceId);
    }

    $payload = array(
        'depositId'          => $depositId,
        'amount'             => number_format((float) $convertedAmount, 2, '.', ''),
        'currency'           => $targetCurrency,
        'payer'              => array(
            'type'           => 'MMO',
            'accountDetails' => array(
                'phoneNumber' => $phone,
                'provider'    => $provider,
            ),
        ),
        'clientReferenceId'  => $invoiceId,
        'customerMessage'    => substr('Invoice ' . $invoiceId, 0, 22),
        'metadata'           => array(
            array('invoiceId' => $invoiceId),
            array('invoiceAmountUsd' => number_format((float) $amountUsd, 2, '.', ''), 'isPII' => false),
            array('chargedAmount' => number_format((float) $convertedAmount, 2, '.', ''), 'chargedCurrency' => $targetCurrency, 'isPII' => false),
            array('customer'  => $firstName . ' ' . $lastName, 'isPII' => true),
            array('source'    => 'dhru-fusion'),
        ),
    );

    $minLimit = (float) ($limitInfo['min'] ?? 0);
    $maxLimit = (float) ($limitInfo['max'] ?? 0);
    if (($limitInfo['enforced'] ?? true) === true && ((float) $convertedAmount < $minLimit || (float) $convertedAmount > $maxLimit)) {
        $limitMsg = 'Amount out of allowed range for this provider. Allowed range: '
            . $targetCurrency . ' '
            . number_format($minLimit, 2)
            . ' to '
            . $targetCurrency . ' '
            . number_format($maxLimit, 2)
            . '.';
        return pawapay_renderForm(
            $invoiceId,
            $amountUsd,
            $currency,
            $phone,
            $country,
            $provider,
            $targetCurrency,
            $returnUrl,
            $paymentOptions,
            $fxRates,
            $limitMsg
        );
    }

    $response = pawapay_apiPost($apiUrl . '/v2/deposits', $apiToken, $payload, $depositId);

    $code = '';

    if ($response === false) {
        logTransaction('pawapay', array(
            'invoiceid' => $invoiceId,
            'endpoint'  => $apiUrl . '/v2/deposits',
            'payload'   => $payload,
            'error'     => 'cURL failed or non-JSON response',
        ), 'Connection Error', 'invoice', $invoiceId);
        return pawapay_renderForm(
            $invoiceId,
            $amountUsd,
            $currency,
            $phone,
            $country,
            $provider,
            $targetCurrency,
            $returnUrl,
            $paymentOptions,
            $fxRates,
            'Unable to connect to the payment service. Please try again.'
        );
    }

    $deposit = isset($response[0]) ? $response[0] : $response;
    $status  = strtoupper((string) ($deposit['status'] ?? ''));

    // ACCEPTED / PENDING → prompt sent to phone
    if (in_array($status, array('ACCEPTED', 'CREATED', 'PENDING'))) {
        logTransaction('pawapay', array(
            'invoiceid' => $invoiceId,
            'depositId' => $depositId,
            'country'   => $country,
            'provider'  => $provider,
            'phone'     => $phone,
            'usdAmount' => number_format((float) $amountUsd, 2, '.', ''),
            'payAmount' => number_format((float) $convertedAmount, 2, '.', ''),
            'currency'  => $targetCurrency,
            'status'    => $status,
            'response'  => $deposit,
        ), 'Pending', 'invoice', $invoiceId);

        $providerLabel = pawapay_providerLabel($provider);
        $code .= '<div style="padding:20px;border:1px solid #c3e6cb;background:#d4edda;border-radius:6px;max-width:480px;margin:0 auto;text-align:center;font-family:Arial,sans-serif;">';
        $code .= '<h3 style="margin-top:0;color:#155724;">&#128242; Mobile Money Payment Requested</h3>';
        $code .= '<p style="color:#155724;">A payment prompt for <strong>' . htmlspecialchars($targetCurrency) . ' ' . htmlspecialchars(number_format((float) $convertedAmount, 2)) . '</strong>';
        $code .= ' has been sent to <strong>' . htmlspecialchars($phone) . '</strong></p>';
        $code .= '<p style="color:#155724;">via <strong>' . htmlspecialchars($providerLabel) . '</strong></p>';
        $code .= '<p style="color:#155724;margin-top:10px;">Please check your phone and <strong>enter your Mobile Money PIN</strong> to approve.</p>';
        $code .= '<hr style="border:none;border-top:1px solid #b1dfbb;margin:15px 0;">';
        $code .= '<p style="font-size:12px;color:#666;margin-top:0;">Invoice amount: ' . htmlspecialchars($currency) . ' ' . htmlspecialchars(number_format((float) $amountUsd, 2)) . '</p>';
        $code .= '<p style="font-size:13px;color:#555;">Once you have approved on your phone, click below:</p>';
        $code .= '<a href="' . htmlspecialchars($returnUrl) . '" ';
        $code .= 'style="display:inline-block;background:#28a745;color:#fff;text-decoration:none;padding:12px 28px;border-radius:4px;font-size:15px;margin-top:5px;font-weight:bold;">';
        $code .= '&#10003; Return to invoice</a>';
        $code .= '</div>';

        // DUPLICATE → already sent
    } elseif ($status === 'DUPLICATE_IGNORED') {
        $code .= '<div style="padding:15px;background:#fff3cd;border:1px solid #ffc107;border-radius:5px;max-width:480px;margin:0 auto;text-align:center;">';
        $code .= '<p style="color:#856404;margin:0;">A payment request was already sent to <strong>' . htmlspecialchars($phone) . '</strong>.</p>';
        $code .= '<p style="margin-top:10px;"><a href="' . htmlspecialchars($returnUrl) . '" style="color:#856404;font-weight:bold;">&#10003; Return to invoice</a></p>';
        $code .= '</div>';

        // Any failure
    } else {
        $rejectCode = (string) ($deposit['failureReason']['failureCode'] ?? '');
        $rejectMsg  = (string) ($deposit['failureReason']['failureMessage'] ?? '');
        $errorText  = $rejectCode ? $rejectCode . ': ' . $rejectMsg : ($rejectMsg ?: 'Status: ' . ($status ?: 'empty'));

        logTransaction('pawapay', array(
            'invoiceid'        => $invoiceId,
            'depositId'        => $depositId,
            'status'           => $status,
            'rejection_code'   => $rejectCode ?: 'n/a',
            'rejection_reason' => $rejectMsg  ?: 'n/a',
            'provider'         => $provider,
            'phone'            => $phone,
            'currency'         => $targetCurrency,
            'amount'           => number_format((float) $convertedAmount, 2, '.', ''),
            'usdAmount'        => number_format((float) $amountUsd, 2, '.', ''),
            'api_url'          => $apiUrl,
            'full_response'    => json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        ), 'Failed', 'invoice', $invoiceId);

        // Re-show form with error so user can correct phone/provider
        return pawapay_renderForm(
            $invoiceId,
            $amountUsd,
            $currency,
            $phone,
            $country,
            $provider,
            $targetCurrency,
            $returnUrl,
            $paymentOptions,
            $fxRates,
            'Payment failed: ' . $errorText . ' — please check your phone number and provider.'
        );
    }

    return $code;
}

// ---------------------------------------------------------------------------
// Render the phone number + provider selection form
// ---------------------------------------------------------------------------
function pawapay_renderForm($invoiceId, $amountUsd, $currency, $prePhone, $preCountry, $preProvider, $prePayCurrency, $returnUrl, array $paymentOptions, array $fxRates, $errorMsg = '')
{
    $countries = $paymentOptions['countries'] ?? array();
    if (empty($countries)) {
        $paymentOptions = pawapay_defaultPaymentOptions();
        $countries      = $paymentOptions['countries'];
    }

    if (empty($preCountry) || !isset($countries[$preCountry])) {
        $countryKeys = array_keys($countries);
        $preCountry = $countryKeys ? $countryKeys[0] : '';
    }

    $countryCurrency = '';
    if (isset($countries[$preCountry])) {
        $cc = $countries[$preCountry]['currencies'] ?? array();
        if (!empty($prePayCurrency) && in_array($prePayCurrency, $cc)) {
            $countryCurrency = $prePayCurrency;
        } elseif (!empty($cc)) {
            $countryCurrency = (string) $cc[0];
        }
    }
    $previewText = '';
    if ($countryCurrency !== '') {
        $previewAmount = pawapay_convertUsdAmount((float) $amountUsd, $countryCurrency, $fxRates, $previewError);
        if ($previewAmount !== false) {
            $previewText = htmlspecialchars($countryCurrency) . ' ' . htmlspecialchars(number_format((float) $previewAmount, 2));
        }
    }
    $limitText = pawapay_buildLimitDisplayText($countries, $preCountry, $preProvider, $countryCurrency);

    $pageUrl = htmlspecialchars($_SERVER['REQUEST_URI'] ?? '');
    $s = '<div style="max-width:460px;margin:0 auto;font-family:Arial,sans-serif;border:1px solid #ddd;border-radius:8px;padding:24px;background:#fafafa;">';
    $s .= '<h3 style="margin-top:0;color:#333;text-align:center;">&#128242; Pay with Mobile Money</h3>';
    $s .= '<p style="text-align:center;color:#555;margin-bottom:8px;">Invoice Amount: <strong>' . htmlspecialchars($currency) . ' ' . htmlspecialchars(number_format((float) $amountUsd, 2)) . '</strong></p>';
    if ($previewText) {
        $s .= '<p id="pawapay-preview" style="text-align:center;color:#333;margin-top:0;margin-bottom:18px;font-size:13px;">Estimated charge currency: <strong>' . $previewText . '</strong></p>';
    } else {
        $s .= '<p id="pawapay-preview" style="text-align:center;color:#777;margin-top:0;margin-bottom:18px;font-size:13px;">Estimated charge will be shown after country selection.</p>';
    }
    if ($limitText) {
        $s .= '<p id="pawapay-limits" style="text-align:center;color:#444;margin-top:-10px;margin-bottom:18px;font-size:12px;">' . htmlspecialchars($limitText) . '</p>';
    } else {
        $s .= '<p id="pawapay-limits" style="text-align:center;color:#777;margin-top:-10px;margin-bottom:18px;font-size:12px;">Select provider to see allowed min/max amount.</p>';
    }

    if ($errorMsg) {
        $s .= '<div style="background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;border-radius:4px;padding:10px 14px;margin-bottom:14px;font-size:13px;">';
        $s .= '&#9888; ' . htmlspecialchars($errorMsg);
        $s .= '</div>';
    }

    $s .= '<form method="post" action="' . $pageUrl . '" style="margin:0;">';
    $s .= '<input type="hidden" name="pawapay_invoice_id" value="' . htmlspecialchars($invoiceId) . '">';

    // Country field
    $s .= '<div style="margin-bottom:16px;">';
    $s .= '<label style="display:block;font-size:13px;font-weight:bold;color:#444;margin-bottom:6px;">Country</label>';
    $s .= '<select id="pawapay-country" name="pawapay_country" required style="width:100%;padding:10px 12px;font-size:14px;border:1px solid #ccc;border-radius:4px;">';
    $s .= '<option value="">— Select your country —</option>';
    foreach ($countries as $countryCode => $countryData) {
        $selectedCountry = ($countryCode === $preCountry) ? ' selected' : '';
        $countryName = (string) ($countryData['name'] ?? $countryCode);
        $countryCur  = '';
        if (!empty($countryData['currencies']) && is_array($countryData['currencies'])) {
            $countryCur = implode(', ', array_map('strtoupper', $countryData['currencies']));
        }
        $label = $countryName . ($countryCur ? ' (' . $countryCur . ')' : '');
        $s .= '<option value="' . htmlspecialchars($countryCode) . '"' . $selectedCountry . '>' . htmlspecialchars($label) . '</option>';
    }
    $s .= '</select>';
    $s .= '</div>';

    // Currency field
    $s .= '<div style="margin-bottom:16px;">';
    $s .= '<label style="display:block;font-size:13px;font-weight:bold;color:#444;margin-bottom:6px;">Payment Currency</label>';
    $s .= '<select id="pawapay-currency" name="pawapay_currency" required style="width:100%;padding:10px 12px;font-size:14px;border:1px solid #ccc;border-radius:4px;">';
    $s .= '<option value="">— Select currency —</option>';
    if (!empty($preCountry) && isset($countries[$preCountry]['currencies']) && is_array($countries[$preCountry]['currencies'])) {
        foreach ($countries[$preCountry]['currencies'] as $currencyCode) {
            $curCode = strtoupper((string) $currencyCode);
            $selectedCur = ($curCode === $countryCurrency) ? ' selected' : '';
            $s .= '<option value="' . htmlspecialchars($curCode) . '"' . $selectedCur . '>' . htmlspecialchars($curCode) . '</option>';
        }
    }
    $s .= '</select>';
    $s .= '</div>';

    // Phone number field
    $s .= '<div style="margin-bottom:16px;">';
    $s .= '<label style="display:block;font-size:13px;font-weight:bold;color:#444;margin-bottom:6px;">Mobile Phone Number</label>';
    $s .= '<input type="tel" name="pawapay_phone" value="' . htmlspecialchars($prePhone) . '" required ';
    $s .= 'placeholder="e.g. 243812345678" ';
    $s .= 'style="width:100%;box-sizing:border-box;padding:10px 12px;font-size:14px;border:1px solid #ccc;border-radius:4px;">';
    $s .= '<small style="color:#888;font-size:11px;">Enter full number with country code, no + or spaces (e.g. 243812345678)</small>';
    $s .= '</div>';

    // Provider dropdown grouped by country
    $s .= '<div style="margin-bottom:20px;">';
    $s .= '<label style="display:block;font-size:13px;font-weight:bold;color:#444;margin-bottom:6px;">Mobile Money Provider</label>';
    $s .= '<select id="pawapay-provider" name="pawapay_provider" required style="width:100%;padding:10px 12px;font-size:14px;border:1px solid #ccc;border-radius:4px;">';
    $s .= '<option value="">— Select your provider —</option>';
    if (!empty($preCountry) && isset($countries[$preCountry]['providers'])) {
        foreach ($countries[$preCountry]['providers'] as $code => $label) {
            $selected = ($code === $preProvider) ? ' selected' : '';
            $s .= '<option value="' . htmlspecialchars($code) . '"' . $selected . '>' . htmlspecialchars($label) . '</option>';
        }
    }
    $s .= '</select>';
    $s .= '</div>';

    $s .= '<button type="submit" style="width:100%;padding:12px;background:#28a745;color:#fff;font-size:15px;font-weight:bold;border:none;border-radius:4px;cursor:pointer;">';
    $s .= '&#128242; Send Payment Request</button>';

    $s .= '<p style="text-align:center;margin-top:14px;margin-bottom:0;">';
    $s .= '<a href="' . htmlspecialchars($returnUrl) . '" style="font-size:12px;color:#888;">Cancel — return to invoice</a>';
    $s .= '</p>';
    $s .= '</form>';
    $s .= '<script>(function(){'
        . 'var countrySelect=document.getElementById("pawapay-country");'
        . 'var providerSelect=document.getElementById("pawapay-provider");'
        . 'var currencySelect=document.getElementById("pawapay-currency");'
        . 'var preview=document.getElementById("pawapay-preview");'
        . 'var limitsLabel=document.getElementById("pawapay-limits");'
        . 'var countries=' . json_encode($countries) . ';'
        . 'var rates=' . json_encode($fxRates) . ';'
        . 'var usdAmount=' . json_encode((float) $amountUsd) . ';'
        . 'var selectedProvider=' . json_encode((string) $preProvider) . ';'
        . 'var selectedCurrency=' . json_encode((string) $countryCurrency) . ';'
        . 'function renderProviders(country){'
        . 'providerSelect.innerHTML="<option value=\"\">— Select your provider —</option>";'
        . 'if(!country||!countries[country]||!countries[country].providers){return;}'
        . 'Object.keys(countries[country].providers).forEach(function(code){'
        . 'var opt=document.createElement("option");'
        . 'opt.value=code;'
        . 'opt.textContent=countries[country].providers[code];'
        . 'if(selectedProvider&&selectedProvider===code){opt.selected=true;}'
        . 'providerSelect.appendChild(opt);'
        . '});'
        . 'selectedProvider="";'
        . '}'
        . 'function renderCurrencies(country){'
        . 'currencySelect.innerHTML="<option value=\"\">— Select currency —</option>";'
        . 'if(!country||!countries[country]||!countries[country].currencies){return;}'
        . 'countries[country].currencies.forEach(function(cur){'
        . 'var c=(cur||"").toUpperCase();if(!c){return;}'
        . 'var opt=document.createElement("option");opt.value=c;opt.textContent=c;'
        . 'if(selectedCurrency&&selectedCurrency===c){opt.selected=true;}'
        . 'currencySelect.appendChild(opt);'
        . '});'
        . 'if(!currencySelect.value&&currencySelect.options.length>1){currencySelect.selectedIndex=1;}'
        . 'selectedCurrency="";'
        . '}'
        . 'function updatePreview(country,currency){'
        . 'if(!country||!countries[country]){preview.textContent="Estimated charge will be shown after country selection.";return;}'
        . 'var cur=(currency||"").toUpperCase();'
        . 'if(!cur){preview.textContent="No payout currency configured for this country.";return;}'
        . 'var rate=(rates[cur]!==undefined)?parseFloat(rates[cur]):NaN;'
        . 'if(cur!=="USD"&&(!isFinite(rate)||rate<=0)){preview.textContent="Missing admin exchange rate for "+cur+".";return;}'
        . 'var amount=(cur==="USD")?usdAmount:(usdAmount*rate);'
        . 'preview.innerHTML="Estimated charge currency: <strong>"+cur+" "+amount.toFixed(2)+"</strong>";'
        . '}'
        . 'function updateLimits(country,provider,currency){'
        . 'if(!country||!countries[country]){limitsLabel.textContent="Select provider to see allowed min/max amount.";return;}'
        . 'var cur=(currency||"").toUpperCase();'
        . 'if(!cur){limitsLabel.textContent="Select currency to see allowed min/max amount.";return;}'
        . 'var limits=(countries[country].limits||{});'
        . 'var byProvider=provider&&limits[provider]?limits[provider]:null;'
        . 'var item=byProvider&&byProvider[cur]?byProvider[cur]:null;'
        . 'if(!item||item.min===null||item.max===null){limitsLabel.textContent="Provider limits unavailable for this selection.";return;}'
        . 'var min=parseFloat(item.min);var max=parseFloat(item.max);'
        . 'if(!isFinite(min)||!isFinite(max)){limitsLabel.textContent="Provider limits unavailable for this selection.";return;}'
        . 'limitsLabel.textContent="Allowed range: "+cur+" "+min.toFixed(2)+" to "+cur+" "+max.toFixed(2);'
        . '}'
        . 'countrySelect.addEventListener("change",function(){renderCurrencies(this.value);renderProviders(this.value);updatePreview(this.value,currencySelect.value);updateLimits(this.value,providerSelect.value,currencySelect.value);});'
        . 'currencySelect.addEventListener("change",function(){updatePreview(countrySelect.value,this.value);updateLimits(countrySelect.value,providerSelect.value,this.value);});'
        . 'providerSelect.addEventListener("change",function(){updateLimits(countrySelect.value,this.value,currencySelect.value);});'
        . 'if(countrySelect.value){renderCurrencies(countrySelect.value);renderProviders(countrySelect.value);updatePreview(countrySelect.value,currencySelect.value);updateLimits(countrySelect.value,providerSelect.value,currencySelect.value);}'
        . '})();</script>';
    $s .= '</div>';

    return $s;
}

// ---------------------------------------------------------------------------
// Build available countries/providers/currencies from active configuration
// ---------------------------------------------------------------------------
function pawapay_buildPaymentOptions($activeConfig)
{
    $countriesOut = array();

    if (is_array($activeConfig) && !empty($activeConfig['countries']) && is_array($activeConfig['countries'])) {
        foreach ($activeConfig['countries'] as $countryItem) {
            $countryCode = strtoupper((string) ($countryItem['country'] ?? ''));
            if ($countryCode === '') {
                continue;
            }

            $countryName = (string) ($countryItem['displayName']['en'] ?? $countryItem['displayName']['fr'] ?? $countryCode);
            $providersOut = array();
            $limitsOut = array();
            $countryCurrencies = array();

            $providers = $countryItem['providers'] ?? array();
            if (!is_array($providers)) {
                $providers = array();
            }

            foreach ($providers as $providerItem) {
                $providerCode  = strtoupper((string) ($providerItem['provider'] ?? ''));
                $providerLabel = (string) ($providerItem['displayName'] ?? $providerCode);
                if ($providerCode !== '') {
                    $providersOut[$providerCode] = $providerLabel;
                }

                $providerLimitsByCurrency = pawapay_extractProviderDepositLimits($providerItem);
                if ($providerCode !== '') {
                    $limitsOut[$providerCode] = $providerLimitsByCurrency;
                    foreach ($providerLimitsByCurrency as $limitCurrency => $limitRow) {
                        $lc = strtoupper((string) $limitCurrency);
                        if ($lc !== '' && !in_array($lc, $countryCurrencies)) {
                            $countryCurrencies[] = $lc;
                        }
                    }
                }
            }

            // Business requirement: DRC must allow both CDF and USD
            if ($countryCode === 'COD') {
                if (!in_array('CDF', $countryCurrencies)) {
                    $countryCurrencies[] = 'CDF';
                }
                if (!in_array('USD', $countryCurrencies)) {
                    $countryCurrencies[] = 'USD';
                }
            }

            if (empty($countryCurrencies)) {
                $countryCurrencies = array('USD');
            }

            if (!empty($providersOut)) {
                $countriesOut[$countryCode] = array(
                    'name'      => $countryName,
                    'currencies' => array_values($countryCurrencies),
                    'providers' => $providersOut,
                    'limits'    => $limitsOut,
                );
            }
        }
    }

    if (empty($countriesOut)) {
        return pawapay_defaultPaymentOptions();
    }

    return array('countries' => $countriesOut);
}

// ---------------------------------------------------------------------------
// Fallback options used when active-conf cannot be fetched
// ---------------------------------------------------------------------------
function pawapay_defaultPaymentOptions()
{
    $countries = array(
        'COD' => array('name' => 'DR Congo (DRC)', 'currencies' => array('CDF', 'USD'), 'providers' => array('ORANGE_COD' => 'Orange Money', 'AIRTEL_COD' => 'Airtel Money', 'VODACOM_COD' => 'M-Pesa (Vodacom)', 'AFRICELL_COD' => 'Africell Money')),
        'CMR' => array('name' => 'Cameroon', 'currencies' => array('XAF'), 'providers' => array('MTN_MOMO_CMR' => 'MTN MoMo', 'ORANGE_CMR' => 'Orange Money')),
        'CIV' => array('name' => 'Côte d\'Ivoire', 'currencies' => array('XOF'), 'providers' => array('MTN_MOMO_CIV' => 'MTN MoMo', 'ORANGE_CIV' => 'Orange Money', 'MOOV_CIV' => 'Moov Money')),
        'GHA' => array('name' => 'Ghana', 'currencies' => array('GHS'), 'providers' => array('MTN_MOMO_GHA' => 'MTN MoMo', 'VODAFONE_GHA' => 'Vodafone Cash', 'AIRTEL_TIGO_GHA' => 'AirtelTigo Money')),
        'KEN' => array('name' => 'Kenya', 'currencies' => array('KES'), 'providers' => array('MPESA_KE' => 'M-Pesa', 'AIRTEL_KE' => 'Airtel Money')),
        'RWA' => array('name' => 'Rwanda', 'currencies' => array('RWF'), 'providers' => array('MTN_MOMO_RWA' => 'MTN MoMo', 'AIRTEL_RWA' => 'Airtel Money')),
        'TZA' => array('name' => 'Tanzania', 'currencies' => array('TZS'), 'providers' => array('MPESA_TZA' => 'M-Pesa', 'AIRTEL_TZA' => 'Airtel Money', 'TIGO_TZA' => 'Tigo Pesa')),
        'UGA' => array('name' => 'Uganda', 'currencies' => array('UGX'), 'providers' => array('MTN_MOMO_UGA' => 'MTN MoMo', 'AIRTEL_UGA' => 'Airtel Money')),
        'ZMB' => array('name' => 'Zambia', 'currencies' => array('ZMW'), 'providers' => array('MTN_MOMO_ZMB' => 'MTN MoMo', 'AIRTEL_ZMB' => 'Airtel Money')),
        'SEN' => array('name' => 'Senegal', 'currencies' => array('XOF'), 'providers' => array('ORANGE_SEN' => 'Orange Money', 'FREE_SEN' => 'Free Money')),
        'BEN' => array('name' => 'Benin', 'currencies' => array('XOF'), 'providers' => array('MTN_MOMO_BEN' => 'MTN MoMo', 'MOOV_BEN' => 'Moov Money')),
        'MLI' => array('name' => 'Mali', 'currencies' => array('XOF'), 'providers' => array('ORANGE_MLI' => 'Orange Money', 'MOOV_MLI' => 'Moov Money')),
        'BFA' => array('name' => 'Burkina Faso', 'currencies' => array('XOF'), 'providers' => array('ORANGE_BFA' => 'Orange Money', 'MOOV_BFA' => 'Moov Money')),
        'TGO' => array('name' => 'Togo', 'currencies' => array('XOF'), 'providers' => array('TMONEY_TGO' => 'T-Money', 'FLOOZ_TGO' => 'Flooz')),
        'MDG' => array('name' => 'Madagascar', 'currencies' => array('MGA'), 'providers' => array('MVOLA_MDG' => 'M-Vola', 'AIRTEL_MDG' => 'Airtel Money', 'ORANGE_MDG' => 'Orange Money')),
        'MOZ' => array('name' => 'Mozambique', 'currencies' => array('MZN'), 'providers' => array('MPESA_MOZ' => 'M-Pesa', 'EMOLA_MOZ' => 'e-Mola')),
        'ZWE' => array('name' => 'Zimbabwe', 'currencies' => array('USD'), 'providers' => array('ECOCASH_ZWE' => 'EcoCash')),
    );

    foreach ($countries as $countryCode => $countryData) {
        $limits = array();
        $countryCurrencies = isset($countryData['currencies']) && is_array($countryData['currencies'])
            ? $countryData['currencies']
            : array('USD');
        foreach (($countryData['providers'] ?? array()) as $providerCode => $providerLabel) {
            $limits[$providerCode] = array();
            foreach ($countryCurrencies as $cur) {
                $curCode = strtoupper((string) $cur);
                if ($curCode === '') {
                    continue;
                }
                $limits[$providerCode][$curCode] = array(
                    'currency' => $curCode,
                    'min'      => null,
                    'max'      => null,
                );
            }
        }
        $countries[$countryCode]['limits'] = $limits;
    }

    return array('countries' => $countries);
}

// ---------------------------------------------------------------------------
// Extract provider deposit limits from active-conf provider block
// ---------------------------------------------------------------------------
function pawapay_extractProviderDepositLimits(array $providerItem)
{
    $currencies = $providerItem['currencies'] ?? array();
    if (!is_array($currencies)) {
        $currencies = array();
    }

    $out = array();
    foreach ($currencies as $currencyItem) {
        $currencyCode = strtoupper((string) ($currencyItem['currency'] ?? ''));
        if ($currencyCode === '') {
            continue;
        }

        $depositLimits = pawapay_extractDepositLimitsFromCurrency($currencyItem);
        $out[$currencyCode] = array(
            'currency' => $currencyCode,
            'min'      => ($depositLimits['found'] ?? false) ? $depositLimits['min'] : null,
            'max'      => ($depositLimits['found'] ?? false) ? $depositLimits['max'] : null,
        );
    }

    return $out;
}

function pawapay_extractDepositLimitsFromCurrency(array $currencyItem)
{
    $operationTypes = $currencyItem['operationTypes'] ?? array();
    if (!is_array($operationTypes)) {
        $operationTypes = array();
    }

    foreach ($operationTypes as $operation) {
        if (!is_array($operation)) {
            continue;
        }

        $depositBlock = null;
        if (isset($operation['DEPOSIT']) && is_array($operation['DEPOSIT'])) {
            $depositBlock = $operation['DEPOSIT'];
        } elseif (strtoupper((string) ($operation['operationType'] ?? '')) === 'DEPOSIT') {
            $depositBlock = $operation;
        }

        if (!is_array($depositBlock)) {
            continue;
        }

        $min = isset($depositBlock['minTransactionLimit']) && $depositBlock['minTransactionLimit'] !== ''
            ? (float) $depositBlock['minTransactionLimit']
            : null;
        $max = isset($depositBlock['maxTransactionLimit']) && $depositBlock['maxTransactionLimit'] !== ''
            ? (float) $depositBlock['maxTransactionLimit']
            : null;

        return array(
            'found' => true,
            'min'   => $min,
            'max'   => $max,
        );
    }

    return array('found' => false, 'min' => null, 'max' => null);
}

// ---------------------------------------------------------------------------
// Validate and fetch provider min/max for strict range checks
// ---------------------------------------------------------------------------
function pawapay_getProviderLimitInfo(array $countries, $countryCode, $providerCode, $targetCurrency)
{
    $countryCode = strtoupper((string) $countryCode);
    $providerCode = strtoupper((string) $providerCode);
    $targetCurrency = strtoupper((string) $targetCurrency);

    if (empty($countries[$countryCode]) || empty($countries[$countryCode]['limits'][$providerCode])) {
        return array('valid' => true, 'enforced' => false, 'message' => 'Provider limits not found in configuration.');
    }

    $providerLimits = $countries[$countryCode]['limits'][$providerCode];
    $limit = $providerLimits[$targetCurrency] ?? null;
    if (!$limit) {
        return array('valid' => true, 'enforced' => false, 'message' => 'No limit profile for selected provider/currency.');
    }
    $limitCurrency = strtoupper((string) ($limit['currency'] ?? ''));
    $min = isset($limit['min']) && $limit['min'] !== null ? (float) $limit['min'] : null;
    $max = isset($limit['max']) && $limit['max'] !== null ? (float) $limit['max'] : null;

    if ($limitCurrency !== '' && $targetCurrency !== '' && $limitCurrency !== $targetCurrency) {
        return array('valid' => true, 'enforced' => false, 'message' => 'Provider limit currency mismatch; skipping strict check.');
    }

    if ($min === null || $max === null || $min < 0 || $max <= 0 || $max < $min) {
        return array('valid' => true, 'enforced' => false, 'message' => 'Provider min/max limits are unavailable. Continuing without strict limit enforcement.');
    }

    return array(
        'valid'    => true,
        'enforced' => true,
        'min'      => $min,
        'max'      => $max,
    );
}

function pawapay_buildLimitDisplayText(array $countries, $countryCode, $providerCode, $targetCurrency)
{
    $countryCode = strtoupper((string) $countryCode);
    $providerCode = strtoupper((string) $providerCode);
    $targetCurrency = strtoupper((string) $targetCurrency);

    if ($providerCode === '' || empty($countries[$countryCode]['limits'][$providerCode][$targetCurrency])) {
        return '';
    }

    $item = $countries[$countryCode]['limits'][$providerCode][$targetCurrency];
    $min = isset($item['min']) && $item['min'] !== null ? (float) $item['min'] : null;
    $max = isset($item['max']) && $item['max'] !== null ? (float) $item['max'] : null;
    if ($min === null || $max === null) {
        return '';
    }

    return 'Allowed range: ' . $targetCurrency . ' ' . number_format($min, 2) . ' to ' . $targetCurrency . ' ' . number_format($max, 2);
}

// ---------------------------------------------------------------------------
// Parse admin exchange table from textarea
// ---------------------------------------------------------------------------
function pawapay_parseFxRates($raw)
{
    $rates = array('USD' => 1.0);

    // Dhru may persist textarea content with HTML entities and <br> tags.
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

        // Remove inline comments
        $line = preg_replace('/\s*[#;].*$/', '', $line);
        $line = trim((string) $line);
        if ($line === '') {
            continue;
        }

        // Accept formats like: CDF=2850, CDF : 2850, CDF -> 2850, CDF 2850
        if (preg_match('/^([A-Za-z]{3})\s*(?:=|:|=>|->)?\s*([-+]?[0-9]+(?:[\.,][0-9]+)?)$/', $line, $m)) {
            $k = strtoupper((string) $m[1]);
            $v = (string) $m[2];
        } else {
            continue;
        }

        // Normalize possible number formats like 1,234.56 or 1234,56
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

// ---------------------------------------------------------------------------
// Convert USD amount to requested currency using admin-defined rates
// ---------------------------------------------------------------------------
function pawapay_convertUsdAmount($amountUsd, $targetCurrency, array $fxRates, &$error = '')
{
    $error = '';
    $targetCurrency = strtoupper((string) $targetCurrency);
    $usd = (float) $amountUsd;

    if ($targetCurrency === '' || $targetCurrency === 'USD') {
        return round($usd, 2);
    }

    if (!isset($fxRates[$targetCurrency]) || (float) $fxRates[$targetCurrency] <= 0) {
        $error = 'Missing exchange rate for ' . $targetCurrency . ' in gateway admin settings.';
        return false;
    }

    return round($usd * (float) $fxRates[$targetCurrency], 2);
}

// ---------------------------------------------------------------------------
// Return a human-readable provider label for the confirmation screen
// ---------------------------------------------------------------------------
function pawapay_providerLabel($code)
{
    $labels = array(
        'ORANGE_COD'      => 'Orange Money (DRC)',
        'AIRTEL_COD'      => 'Airtel Money (DRC)',
        'VODACOM_COD'     => 'M-Pesa / Vodacom (DRC)',
        'AFRICELL_COD'    => 'Africell (DRC)',
        'MTN_MOMO_CMR'    => 'MTN MoMo (Cameroon)',
        'ORANGE_CMR'      => 'Orange Money (Cameroon)',
        'MTN_MOMO_CIV'    => 'MTN MoMo (Côte d\'Ivoire)',
        'ORANGE_CIV'      => 'Orange Money (Côte d\'Ivoire)',
        'MOOV_CIV'        => 'Moov Money (Côte d\'Ivoire)',
        'MTN_MOMO_GHA'    => 'MTN MoMo (Ghana)',
        'VODAFONE_GHA'    => 'Vodafone Cash (Ghana)',
        'AIRTEL_TIGO_GHA' => 'AirtelTigo (Ghana)',
        'MPESA_KE'        => 'M-Pesa (Kenya)',
        'AIRTEL_KE'       => 'Airtel Money (Kenya)',
        'MTN_MOMO_RWA'    => 'MTN MoMo (Rwanda)',
        'AIRTEL_RWA'      => 'Airtel Money (Rwanda)',
        'MPESA_TZA'       => 'M-Pesa (Tanzania)',
        'AIRTEL_TZA'      => 'Airtel Money (Tanzania)',
        'TIGO_TZA'        => 'Tigo Pesa (Tanzania)',
        'MTN_MOMO_UGA'    => 'MTN MoMo (Uganda)',
        'AIRTEL_UGA'      => 'Airtel Money (Uganda)',
        'MTN_MOMO_ZMB'    => 'MTN MoMo (Zambia)',
        'AIRTEL_ZMB'      => 'Airtel Money (Zambia)',
        'ORANGE_SEN'      => 'Orange Money (Senegal)',
        'FREE_SEN'        => 'Free Money (Senegal)',
        'MTN_MOMO_BEN'    => 'MTN MoMo (Benin)',
        'MOOV_BEN'        => 'Moov Money (Benin)',
        'ORANGE_MLI'      => 'Orange Money (Mali)',
        'MOOV_MLI'        => 'Moov Money (Mali)',
        'ORANGE_BFA'      => 'Orange Money (Burkina Faso)',
        'MOOV_BFA'        => 'Moov Money (Burkina Faso)',
        'TMONEY_TGO'      => 'T-Money (Togo)',
        'FLOOZ_TGO'       => 'Flooz (Togo)',
        'MVOLA_MDG'       => 'M-Vola (Madagascar)',
        'AIRTEL_MDG'      => 'Airtel Money (Madagascar)',
        'ORANGE_MDG'      => 'Orange Money (Madagascar)',
        'MPESA_MOZ'       => 'M-Pesa (Mozambique)',
        'EMOLA_MOZ'       => 'e-Mola (Mozambique)',
        'ECOCASH_ZWE'     => 'EcoCash (Zimbabwe)',
    );
    return $labels[$code] ?? $code;
}

// ---------------------------------------------------------------------------
// Helper: POST JSON to PawaPay API using cURL
// ---------------------------------------------------------------------------
function pawapay_apiPost($endpoint, $apiToken, array $payload, $idempotencyKey = null)
{
    $json    = json_encode($payload);
    $headers = array(
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Bearer ' . $apiToken,
    );
    if ($idempotencyKey) {
        $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
    }

    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $result   = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($result === false) {
        return false;
    }
    $decoded = json_decode($result, true);
    return is_array($decoded) ? $decoded : false;
}

// ---------------------------------------------------------------------------
// Helper: GET request (used for status check fallback)
// ---------------------------------------------------------------------------
function pawapay_apiGet($endpoint, $apiToken)
{
    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Accept: application/json',
        'Authorization: Bearer ' . $apiToken,
    ));
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $result = curl_exec($ch);
    curl_close($ch);

    if ($result === false) {
        return false;
    }
    $decoded = json_decode($result, true);
    return is_array($decoded) ? $decoded : false;
}

// ---------------------------------------------------------------------------
// Helper: Normalize phone number (strip spaces, dashes, parentheses, +)
// ---------------------------------------------------------------------------
function pawapay_normalizePhone($phone)
{
    return preg_replace('/[\s\-\(\)\+]/', '', (string) $phone);
}
