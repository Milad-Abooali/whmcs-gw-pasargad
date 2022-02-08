<?php
/**
 **************************************************************************
 * IranPasargadBank Gateway
 * payment.php
 * Send Request & Callback
 * @author           Milad Abooali <m.abooali@hotmail.com>
 * @version          1.0
 **************************************************************************
 * @noinspection PhpUnused
 * @noinspection PhpUndefinedFunctionInspection
 * @noinspection SpellCheckingInspection
 * @noinspection PhpIncludeInspection
 * @noinspection PhpDeprecationInspection
 * @noinspection PhpUndefinedMethodInspection
 * @noinspection PhpUnhandledExceptionInspection
 **************************************************************************
 */

global $CONFIG;
$cb_output = [$_POST,$_GET];
$cb_gw_name = 'IranPasargadBank';
$action = isset($_GET['a']) ? $_GET['a'] : false;
$root_path     = '../../../';
$includes_path = '../../../includes/';
include($root_path.((file_exists($root_path.'init.php'))?'init.php':'dbconnect.php'));
include($includes_path.'functions.php');
include($includes_path.'gatewayfunctions.php');
include($includes_path.'invoicefunctions.php');
$modules    = getGatewayVariables($cb_gw_name);
if (!$modules['type']) die('Module Not Activated');
$amount 			= intval($_REQUEST['amount']);
$invoice_id 	    = $_REQUEST['invoiceid'];
$gw_id          	= $modules['cb_gw_id'];

/**
 * Telegram Notify
 * @param $notify
 */
function notifyTelegram($notify) {
    global $modules;
    $row = "------------------";
    $pm= "\n".$row.$row.$row."\n".$notify['title']."\n".$row."\n".$notify['text'];
    $chat_id = $modules['cb_telegram_chatid'];
    $botToken = $modules['cb_telegram_bot']; // "291958747:AAF65_lFLaap35HS5zYxSbO1ycNb8Pl2vTk";
    $data = ['chat_id' => $chat_id, 'text' => $pm];
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, "https://api.telegram.org/bot$botToken/sendMessage");
    curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
    curl_setopt($curl, CURLOPT_TIMEOUT, 10);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_exec($curl);
    curl_close($curl);
}

/**
 * Email Notify
 * @param $notify
 */
function notifyEmail($notify) {
    global $modules;
    global $cb_output;
    $receivers = explode(',', $modules['cb_email_address']);
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/plain;charset=UTF-8" . "\r\n";
    $headers .= "From: ".$modules['cb_email_from']."\r\n";
    if($receivers) foreach ($receivers as $receiver){
        $cb_output['mail'][] = mail($receiver, $notify['title'], $notify['text'], $headers);
    }
}

/**
 * PepPayRequest
 * @param $InvoiceNumber
 * @param $TerminalCode
 * @param $MerchantCode
 * @param $Amount
 * @param $RedirectAddress
 * @param string $Mobile
 * @param string $Email
 * @return mixed
 */
function PepPayRequest($InvoiceNumber, $TerminalCode, $MerchantCode, $Amount, $RedirectAddress, $Mobile = '', $Email = '')
{
    require_once(dirname(__FILE__) . '/includes/RSAProcessor.class.php');
    $processor = new RSAProcessor(dirname(__FILE__) . '/includes/certificate.xml', RSAKeyType::XMLFile);
    if (!function_exists('jdate')) {
        require_once(dirname(__FILE__) . '/includes/jdf.php');
    }
    $data = array(
        'InvoiceNumber' => $InvoiceNumber,
        'InvoiceDate' => jdate('Y/m/d'),
        'TerminalCode' => $TerminalCode,
        'MerchantCode' => $MerchantCode,
        'Amount' => $Amount,
        'RedirectAddress' => $RedirectAddress,
        'Timestamp' => date('Y/m/d H:i:s'),
        'Action' => 1003,
        'Mobile' => $Mobile,
        'Email' => $Email
    );

    $sign_data = json_encode($data);
    $sign_data = sha1($sign_data, true);
    $sign_data = $processor->sign($sign_data);
    $sign = base64_encode($sign_data);

    $curl = curl_init('https://pep.shaparak.ir/Api/v1/Payment/GetToken');
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Sign: ' . $sign
        )
    );
    $result = json_decode(curl_exec($curl));
    curl_close($curl);

    return $result;
}

/**
 * redirect
 * @param $url
 */
function redirect($url)
{
    if ($url != '') {
        if (headers_sent()) {
            echo '<script type="text/javascript">window.location.assign("' . $url . '")</script>';
        } else {
            header("Location: $url");
        }
        exit();
    }
}

if($action==='callback') {
    $tran_id  = $order_id  = $invoice_id;
    $ref_code = $_POST['SaleReferenceId'];
    if(!empty($invoice_id)) {
        $cb_output['invoice_id'] = $invoice_id;
        if(!empty($order_id) && !empty($tran_id) && !empty($ref_code)) {
            $cb_output['tran_id'] = $tran_id;
            $invoice_id = checkCbInvoiceID($invoice_id, $modules['name']);
            $results = select_query("tblinvoices", "", array("id" => $invoice_id));
            $data = mysql_fetch_array($results);
            $db_amount = strtok($data['total'], '.');
            if ($_POST['ResCode'] == '0') {
                include_once('nusoap.php');
                $client = new nusoap_client('https://bpm.shaparak.ir/pgwchannel/services/pgw?wsdl');
                $namespace='http://interfaces.core.sw.bps.com/';
                $parameters = array(
                    'terminalId' 		=> $modules['cb_gw_terminal_id'],
                    'userName' 			=> $modules['cb_gw_user'],
                    'userPassword' 		=> $modules['cb_gw_pass'],
                    'orderId'           => $_POST['SaleOrderId'],
                    'saleOrderId'       => $_POST['SaleOrderId'],
                    'saleReferenceId'   => $_POST['SaleReferenceId']
                );
                $cb_output['res']['result'] = $bpVerifyRequest = $client->call('bpVerifyRequest', $parameters, $namespace);
                if($bpVerifyRequest == 0) {
                    $bpSettleRequest = $client->call('bpSettleRequest', $parameters, $namespace);
                    if($bpSettleRequest == 0) {
                        $cartNumber = $_POST['CardHolderPan'];
                        addInvoicePayment($invoice_id, $ref_code, $amount, 0, $cb_gw_name);
                        logTransaction($modules["name"], array('invoiceid' => $invoice_id,'order_id' => $order_id,'amount' => $amount." ".(($modules['cb_gw_unit']>1) ? 'Toman' : 'Rial'),'tran_id' => $tran_id,'RefId' => $_POST['RefId'],'SaleReferenceId' => $ref_code,'CardNumber' => $cartNumber,'status' => "OK"), "موفق");
                        $notify['title'] = $cb_gw_name.' | '."تراکنش موفق";
                        $notify['text']  = "\n\rGateway: $cb_gw_name\n\rAmount: $amount ".(($modules['cb_gw_unit']>1) ? 'Toman' : 'Rial')."\n\rOrder: $order_id\n\rInvoice: $invoice_id\n\rCart Number: $cartNumber";
                        if($modules['cb_email_on_success']) notifyEmail($notify);
                        if($modules['cb_telegram_on_success']) notifyTelegram($notify);
                    }
                    else {
                        $client->call('bpReversalRequest', $parameters, $namespace);
                        logTransaction($modules["name"], array('invoiceid' => $invoice_id,'order_id' => $order_id,'amount' => $amount." ".(($modules['cb_gw_unit']>1) ? 'Toman' : 'Rial'),'tran_id' => $tran_id, 'status' => $bpSettleRequest), "ناموفق");
                        $notify['title'] = $cb_gw_name.' | '."تراکنش ناموفق";
                        $notify['text']  = "\n\rGateway: $cb_gw_name\n\rAmount: $amount ".(($modules['cb_gw_unit']>1) ? 'Toman' : 'Rial')."\n\rOrder: $order_id\n\rInvoice: $invoice_id\n\rError: به دلیل رخ دادن خطا در پرداخت، درخواست بازگشت وجه داده شد.";
                        if($modules['cb_email_on_error']) notifyEmail($notify);
                        if($modules['cb_telegram_on_error']) notifyTelegram($notify);
                    }
                }
                else {
                    $client->call('bpReversalRequest', $parameters, $namespace);
                    logTransaction($modules["name"], array('invoiceid' => $invoice_id,'order_id' => $order_id,'amount' => $amount." ".(($modules['cb_gw_unit']>1) ? 'Toman' : 'Rial'),'tran_id' => $tran_id, 'status' => $bpVerifyRequest), "ناموفق");
                    $notify['title'] = $cb_gw_name.' | '."تراکنش ناموفق";
                    $notify['text']  = "\n\rGateway: $cb_gw_name\n\rAmount: $amount ".(($modules['cb_gw_unit']>1) ? 'Toman' : 'Rial')."\n\rOrder: $order_id\n\rInvoice: $invoice_id\n\rError: به دلیل رخ دادن خطا در پرداخت، درخواست بازگشت وجه داده شد.";
                    if($modules['cb_email_on_error']) notifyEmail($notify);
                    if($modules['cb_telegram_on_error']) notifyTelegram($notify);
                }
            } else {
                logTransaction($modules["name"], array('invoiceid' => $invoice_id,'order_id' => $order_id,'amount' => $amount." ".(($modules['cb_gw_unit']>1) ? 'Toman' : 'Rial'),'tran_id' => $tran_id, 'status' => $_POST['ResCode']), "ناموفق");
                $notify['title'] = $cb_gw_name.' | '."تراکنش ناموفق";
                $notify['text']  = "\n\rGateway: $cb_gw_name\n\rAmount: $amount ".(($modules['cb_gw_unit']>1) ? 'Toman' : 'Rial')."\n\rOrder: $order_id\n\rInvoice: $invoice_id\n\rError: به دلیل رخ دادن خطا در پرداخت، درخواست بازگشت وجه داده شد.";
                if($modules['cb_email_on_error']) notifyEmail($notify);
                if($modules['cb_telegram_on_error']) notifyTelegram($notify);
            }


        }
        $action = $CONFIG['SystemURL'] . "/viewinvoice.php?id=" . $invoice_id;
        header('Location: ' . $action);
        //print("<pre>".print_r($cb_output,true)."</pre>");
    }
    else {
        echo "invoice id is blank";
    }
}
else if($action==='send') {
    $order_id = $invoice_id . mt_rand(10, 100);
    $callback_URL   = $CONFIG['SystemURL']."/modules/gateways/$cb_gw_name/payment.php?a=callback&invoiceid=". $invoice_id.'&amount='.$amount;

    $Request = PepPayRequest($order_id, $modules['cb_gw_terminal_id'], $modules['cb_gw_merchant_id'], $amount, $callback_URL, '', $_POST['email']);

    if (isset($Request) && $Request->IsSuccess) {
        redirect('https://pep.shaparak.ir/payment.aspx?n=' . $Request->Token);
    } else {
        $message = isset($Request->Message) ? $Request->Message : 'خطای نامشخص';
        echo '
	<!DOCTYPE html> 
	<html xmlns="http://www.w3.org/1999/xhtml" lang="fa">
	<head>
	<title>خطا در ارسال به بانک</title>
	<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
	<style>body{font-family:tahoma;text-align:center;margin-top:30px;}</style>
	</head>
	<body>
		<div align="center" dir="rtl" style="font-family:tahoma;font-size:12px;border:1px dotted #c3c3c3; width:60%; margin: 50px auto 0px auto;line-height: 25px;padding-left: 12px;padding-top: 8px;">
			<span style="color:#ff0000;"><b>خطا در ارسال به بانک</b></span><br/>
			<p style="text-align:center;">' . $message . '</p>
			<a href="' . $CONFIG['SystemURL'] . '/viewinvoice.php?id=' . $invoice_id . '">بازگشت</a><br/><br/>
		</div>
	</body>
	</html>
	';
    }

}