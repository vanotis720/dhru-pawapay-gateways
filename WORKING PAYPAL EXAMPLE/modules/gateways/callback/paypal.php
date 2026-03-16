<?php

define("DEFINE_MY_ACCESS", true);
define("DEFINE_DHRU_FILE", true);
include '../../../comm.php';
require '../../../includes/fun.inc.php';
include '../../../includes/gateway.fun.php';
include '../../../includes/invoice.fun.php';

unset($invoiceid,$txn_type,$userid);

$GATEWAY = loadGatewayModule('paypal');
$postipn = 'cmd=_notify-validate';
$orgipn = '';
foreach ($_POST as $key => $value) {
    $orgipn .= ('' . $key . ' => ' . $value . ' ');
    $postipn .= '&' . $key . '=' . urlencode(stripslashes($value));
}
if ($GATEWAY['SendBox']) {
    //logTransaction('paypal', $orgipn, 'SendBox');
    $_paypal_link = 'ssl://www.paypal.com';
    $urlParsed = parse_url('https://www.paypal.com/cgi-bin/webscr');
} else {
    $_paypal_link = 'ssl://www.paypal.com';
    $urlParsed = parse_url('https://www.paypal.com/cgi-bin/webscr');
}
$port = fsockopen($_paypal_link, 443, $errno, $errstr, 30);
if ((!$port and !$error)) {
    logTransaction('paypal', 'No::' . $errno . ' Error::' . $errstr, 'Error-1');
    exit();
} else {
    fputs($port, "POST $urlParsed[path] HTTP/1.1\r\n");
    fputs($port, "Host: $urlParsed[host]\r\n");
    fputs($port, "Content-type: application/x-www-form-urlencoded\r\n");
    fputs($port, "Content-length: " . strlen($postipn) . "\r\n");
    fputs($port, "Connection: close\r\n\r\n");
    fputs($port, $postipn . "\r\n\r\n");
    while (!feof($port)) {
        $reply .= fgets($port, 1024);
    }
    fclose($port);
    //logTransaction('paypal', $reply, 'TOPP');
 $TmpInvoiceNo = $_POST['custom'];
    //check unauthorized_clame
    if ( stripos($reply, "unauthorized")!==false ) {
        
       
        /// Process to Fraud Cage.////
        if(mysqli_num_rows(dquery("select id from tbl_invoices
        where id='$_POST[custom]'"))){
            
            
            $ipndata=enc(json_encode($_POST),1523);
           
            dquery("insert into tbl_fraud
            (invoiceid,data) values ('$_POST[custom]','$ipndata')");
             include_once ROOTDIR . "/includes/mail.fun.php";
            $here = "<a href='$config[site_address]/$config[admin_directory]/main.php?pageurl=" . base64_encode("edituser.editinvoice&id=$_POST[custom]") . "&title=" . base64_encode("View Invoice -$_POST[custom]") . "'>here</a>";
            $adminmessage = "New PayPal dispute open as a Unauthorised transaction.
            <br /> You can submit fraud details to Utilities->Fraud Cage to reduce fraud cases world wide.     
            <br /><br /> Click $here to view invoice";
            sendNotification('account', "Paypal Unauthorised claim #$_POST[custom]", $adminmessage, '');
            if(!$config['autofraudclose']){
                
                $result = select_query('tbl_invoices', '', 
                array('id' => $_POST['custom']));
                $data = mysqli_fetch_assoc($result);
                $userid = $data['userid'];
                dquery("update tblUsers set userstatus=1,
                block_reason='Paypal Unauthorised Claim'
                where id='$userid'");
                
            }
        }
        exit();
        /////////////////////////////
    }
    
    
    
    if ( stripos($reply, "VERIFIED")!==false
     || stripos($reply, "verified")!==false) {
        logTransaction('paypal', $orgipn, 'Verified','invoice',$TmpInvoiceNo);
    } else {
        $payment_status = $_POST['payment_status'];
        if(!$config['directunverified']){
            if (stripos($payment_status, "Completed")!==false) {
                $txn_id = $_POST['txn_id'];
                $idnumber = $_POST['custom'];
                include_once ROOTDIR . "/includes/mail.fun.php";
                $here = "<a href='$config[site_address]/$config[admin_directory]/main.php?pageurl=" . base64_encode("edituser.editinvoice&id=$idnumber") . "&title=" . base64_encode("View Invoice -$idnumber") . "'>here</a>";
                $adminmessage = "Payment received for invoice#$idnumber .
                <br /> But this payer ($_POST[payer_email]) is unverified so please review transaction ($txn_id) and Pay invoice Manualy .
                <br /><br /> Click $here to view invoice";
                sendNotification('account', "Unverified transaction [$txn_id]", $adminmessage, '');
                exit();
            }
        }
        if (stripos($reply, "INVALID")!==false) {
            logTransaction('paypal', $orgipn.":".$reply, 'Invalid','invoice',$TmpInvoiceNo);
            //logTransaction('paypal', "Reply:".$reply, 'Invalid');
            exit();
        } elseif (stripos($reply, "unverified")!==false) {
            logTransaction('paypal', $orgipn.":".$reply, 'Unverified','invoice',$TmpInvoiceNo);
            exit();
        } else {
            logTransaction('paypal', $orgipn.":".$reply, 'Error-2','invoice',$TmpInvoiceNo);
            exit();
        }
    }
}
$payment_status = $_POST['payment_status'];
$subscr_id = $_POST['subscr_id'];
$txn_type = $_POST['txn_type'];
$txn_id = $_POST['txn_id'];
$mc_gross = $_POST['mc_gross'];
$mc_fee = $_POST['mc_fee'];
$idnumber = $_POST['custom'];
$paypalcurrency = $_REQUEST['mc_currency'];
$business = $_POST['business'];

if(strtolower(trim($business))!=strtolower(trim($GATEWAY['email'])))
{
    logTransaction('paypal',$orgipn,'Wrong Business Email','invoice',$TmpInvoiceNo);
    exit();
}
if($payment_status=='Pending') {
    logTransaction('paypal',$orgipn,'Pending','invoice',$TmpInvoiceNo);
    exit();
}
if($txn_id){
    if(checkTransID($txn_id)) 
    { exit(); }
}
if (!is_numeric($idnumber)) {
    $idnumber = '';
}
$result = select_query('tbl_currencies', '', array('code' => $paypalcurrency));
$data = mysqli_fetch_assoc($result);
$paypalcurrencyid = $data['id'];
$currencyconvrate = $data['rate'];
if (!$paypalcurrencyid) {
    logTransaction('paypal', $orgipn, 'Unrecognised Currency','invoice',$TmpInvoiceNo);
    exit();
}
switch ($txn_type) {
    case 'web_accept':
    {
        if ($payment_status != 'Completed') {
            logTransaction('paypal', $orgipn, $payment_status,'invoice',$TmpInvoiceNo);
            exit();
        }
        $result = select_query('tbl_invoices','',array('id' => $idnumber));
        $data = mysqli_fetch_assoc($result);
        $invoiceid = $data['id'];
        $userid = $data['userid'];
    }
}
if ($invoiceid) {
    logTransaction('paypal', $orgipn, 'Successful','invoice',$TmpInvoiceNo);
    $currency = getCurrency('', $userid);
    dquery("update tbl_invoices set businessid='$business'
    where id='$invoiceid'");
    if ($paypalcurrencyid != $currency['id']) {
        $mc_gross = convertCurrency($mc_gross, $paypalcurrencyid, $currency['id']);
        $mc_fee = convertCurrency($mc_fee, $paypalcurrencyid, $currency['id']);
        $result = select_query('tbl_invoices', 'total', array('id' => $invoiceid));
        $data = mysqli_fetch_assoc($result);
        $total = $data['total'];
        if (($total < $mc_gross + 1 and $mc_gross - 1 < $total)) {
            $mc_gross = $total;
        }
    }
    addPayment($invoiceid, $txn_id, $mc_gross, $mc_fee, 'paypal');
    exit();
}
logTransaction('paypal', $orgipn, 'Not Supported','invoice',$TmpInvoiceNo);
