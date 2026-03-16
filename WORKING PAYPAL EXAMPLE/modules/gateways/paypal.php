<?php

defined("DEFINE_MY_ACCESS") or die('<h1 style="color: #C00; text-align: center;"><strong>Restricted Access</strong></h1>');
function paypal_config() {
    $configarray = array(
        'name' => array('Type' => 'System', 'Value' => 'PayPal'),
        'email' => array(
            'Name' => 'PayPal Email',
            'Type' => 'text',
            'Size' => '40',
            'Description' => 'Login Mail.'),
        'info' => array(
            'Name' => 'Other Information',
            'Type' => 'textarea',
            'Cols' => '5',
            'Rows' => '10'),
        'forceshippingadd' => array(
            'Type' => 'yesno',
            'Name' => 'Test Mode',
            'Description' => 'forcefully add shipping address for all products include digital delivery and add fund'),
         'notes' => array('Type' => 'System', 'Value' => 
         'Auto Return is turned off by default. To turn on Auto Return: <br /><br />

1. Log in to your PayPal account at https://www.paypal.com. The My Account Overview page appears.<br />
2. Click the Profile subtab. The Profile Summary page appears.<br />
3. Click the My Selling Tools link in the left column.<br />
4. Under the Selling Online section, click the Update link in the row for Website Preferences. The Website Payment Preferences page appears<br />
5. Under Auto Return for Website Payments, click the On radio button to enable Auto Return.<br />
6. In the Return URL field, enter the URL to which you want your payers redirected after they complete their payments. NOTE: PayPal checks the Return URL that you enter. If the URL is not properly formatted or cannot be validated, PayPal will not activate Auto Return.<br />
7. Scroll to the bottom of the page, and click the Save button.')
            );
    return $configarray;
}
function paypal_link($params) {
    
    global $config;
    global $lng_languag;
    $code = '';
    $invoiceid    = $params['invoiceid'];
    $invoicetotal = $params['amount'];
    $invoicetotal = formatCurrency2($invoicetotal);
    $paypallink = 'https://www.paypal.com/cgi-bin/webscr';
    if ($params['SendBox']) {
        $paypallink = 'https://www.sandbox.paypal.com/cgi-bin/webscr';
    }
    $code .= '<form action="' . $paypallink . '" method="post">
    <input type="hidden" name="cmd" value="_xclick">
    <input type="hidden" name="business" value="' . $params['email'] . '">';
    if ($params['style']) {
        $code .= '<input type="hidden" name="page_style" value="' . $params['style'] . '">';
    }
    
    if($params['forceshippingadd']){
        $asdfadfdsa = '
        <input type="hidden" name="item_name" value="' . $params['description'] . '">        
        <input type="hidden" name="invoice" value="' . $invoiceid . '">
        ' ;
    }else{
        $asdfadfdsa = '
        <input type="hidden" name="item_name" value="' . $params['description'] . '">        
        <input type="hidden" name="no_shipping" value="1">
        ' ;
    }
    
    $code .=  $asdfadfdsa . '
    <input type="hidden" name="quantity" value="1">
    <input type="hidden" name="amount" value="' . $invoicetotal . '">
    <input type="hidden" name="tax" value="0.00">
    <input type="hidden" name="no_note" value="1">
    <input type="hidden" name="first_name" value="' . $params['clientdetails']['firstname'] . '">
    <input type="hidden" name="last_name" value="' . $params['clientdetails']['lastname'] . '">
    <input type="hidden" name="address1" value="' . $params['clientdetails']['address1'] . '">
    <input type="hidden" name="city" value="' . $params['clientdetails']['city'] . '">
    <input type="hidden" name="state" value="' . $params['clientdetails']['state'] . '">
    <input type="hidden" name="zip" value="' . $params['clientdetails']['postcode'] . '">
    <input type="hidden" name="country" value="' . $params['clientdetails']['country'] . '">
    <input type="hidden" name="H_PhoneNumber" value="' . $params['clientdetails']['phonenumber'] . '">
    <input TYPE="hidden" name="charset" value="' . $config['charset'] . '">
    <input type="hidden" name="currency_code" value="' . $params['currency'] . '">
    <input type="hidden" name="custom" value="' . $params['invoiceid'] . '">
    <input type="hidden" name="return" value="' . $params['returnurl'] . '&paymentsuccess=true">
    <input type="hidden" name="cancel_return" value="' . $params['returnurl'] . '&paymentfailed=true">
    <input type="hidden" name="notify_url" value="' . $params['systemurl'] . '/modules/gateways/callback/paypal.php">
    <input type="hidden" name="bn" value="DHRUFUSION">
    <input type="hidden" name="rm" value="2">
    <input type="submit" class="btn btn-success" value="' . $lng_languag["invoicespaynow"] . '" alt="' . $lng_languag["invoicespaynow"] . '">
    </form>';
    return $code;
}