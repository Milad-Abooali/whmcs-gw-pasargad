<?php
/**
 **************************************************************************
 * IranPasargadBank Gateway
 * IranPasargadBank.php
 * Send Request & Callback
 * @author           Milad Abooali <m.abooali@hotmail.com>
 * @version          1.1
 **************************************************************************
 * @noinspection PhpUnused
 * @noinspection PhpUndefinedFunctionInspection
 * @noinspection PhpUndefinedMethodInspection
 * @noinspection PhpDeprecationInspection
 * @noinspection SpellCheckingInspection
 * @noinspection PhpIncludeInspection
 * @noinspection PhpIncludeInspection
 */

global $CONFIG;

$cb_gw_name    = 'IranPasargadBank';
$cb_output     = ['POST'=>$_POST,'GET'=>$_GET];
$action 	   = isset($_GET['a']) ? $_GET['a'] : false;

$root_path     = '../../../';
$includes_path = '../../../includes/';
include($root_path.((file_exists($root_path.'init.php'))?'init.php':'dbconnect.php'));
include($includes_path.'functions.php');
include($includes_path.'gatewayfunctions.php');
include($includes_path.'invoicefunctions.php');

$modules       = getGatewayVariables($cb_gw_name);
if(!$modules['type']) die('Module Not Activated');

$invoice_id    = $_REQUEST['invoiceid'];
$amount_rial   = intval($_REQUEST['amount']);
$amount        = $amount_rial / $modules['cb_gw_unit'];
$callback_URL  = $CONFIG['SystemURL']."/modules/gateways/$cb_gw_name/payment.php?a=callback&invoiceid=". $invoice_id.'&amount='.$amount_rial;
$invoice_URL   = $CONFIG['SystemURL']."/viewinvoice.php?id=".$invoice_id;

/**
 * Telegram Notify
 * @param $notify
 */
function notifyTelegram($notify) {
    global $modules;
    $row = "------------------";
    $pm= "\n".$row.$row.$row."\n".$notify['title']."\n".$row."\n".$notify['text'];
    $chat_id = $modules['cb_telegram_chatid'];
    $botToken = $modules['cb_telegram_bot'];
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
    if($receivers) foreach ($receivers as $receiver)
        $cb_output['mail'][] = mail($receiver, $notify['title'], $notify['text'], $headers);
}

/**
 * Payment Failed
 * @param $log
 */
function payment_failed($log)
{
    global $modules;
    global $cb_gw_name;
    global $cb_output;
    $log['status'] = "unpaid";
    $cb_output['payment_failed']=$log;
    logTransaction($modules["name"], $log, "ناموفق");
    if($modules['cb_email_on_error'] || $modules['cb_telegram_on_error']){
        $notify['title'] = $cb_gw_name . ' | ' . "تراکنش ناموفق";
        $notify['text'] = '';
        foreach ($log as $key=>$item)
            $notify['text'] .= "\n\r$key: $item";
        if ($modules['cb_email_on_error']) notifyEmail($notify);
        if ($modules['cb_telegram_on_error']) notifyTelegram($notify);
    }
}

/**
 * Payment Success
 * @param $log
 */
function payment_success($log)
{
    global $modules;
    global $cb_gw_name;
    global $cb_output;
    $log['status'] = "OK";
    $cb_output['payment_success']=$log;
    logTransaction($modules["name"], $log, "موفق");
    if($modules['cb_email_on_success'] || $modules['cb_telegram_on_success']){
        $notify['title'] = $cb_gw_name . ' | ' . "تراکنش موفق";
        $notify['text'] = '';
        foreach ($log as $key=>$item)
            $notify['text'] .= "\n\r$key: $item";
        if ($modules['cb_email_on_success']) notifyEmail($notify);
        if ($modules['cb_telegram_on_success']) notifyTelegram($notify);
    }
}

/**
 * Redirecttion
 * @param $url
 */
function redirect($url)
{
    if (headers_sent())
        echo "<script>window.location.assign('$url')</script>";
    else
        header("Location: $url");
    exit;
}

/**
 * Show Error
 * @param $text
 */
function show_error($text)
{
    global $cb_gw_name;
    global $invoice_URL;
    echo "<img src='/modules/gateways/$cb_gw_name/logo.png' alt='$cb_gw_name'>
        <p>$text</p><a href='$invoice_URL'>بازگشت</a>";
}

/**
 * Get DB Amount
 * @return float
 */
function get_db_amount(){
    global $modules;
    global $invoice_id;
    $sql = select_query("tblinvoices", "", array("id" => $invoice_id));
    $sql_res = mysql_fetch_array($sql);
    $db_amount = strtok($sql_res['total'], '.');
    return $db_amount * $modules['cb_gw_unit'];
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
 * Pep Check Transaction Result
 * @param $TransactionReferenceID
 * @param string $InvoiceNumber
 * @param string $InvoiceDate
 * @param string $TerminalCode
 * @param string $MerchantCode
 * @return mixed
 */
function PepCheckTransactionResult($TransactionReferenceID, $InvoiceNumber = '', $InvoiceDate = '', $TerminalCode = '', $MerchantCode = '')
{
    $data = array(
        'InvoiceNumber' => $InvoiceNumber,
        'InvoiceDate' => $InvoiceDate,
        'TerminalCode' => $TerminalCode,
        'MerchantCode' => $MerchantCode,
        'TransactionReferenceID' => $TransactionReferenceID
    );
    $curl = curl_init('https://pep.shaparak.ir/Api/v1/Payment/CheckTransactionResult');
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json'
        )
    );
    $result = json_decode(curl_exec($curl));
    curl_close($curl);
    return $result;
}


/**
 * Pep Reversal Request
 * @param $InvoiceNumber
 * @param $InvoiceDate
 * @param $TerminalCode
 * @param $MerchantCode
 * @return mixed
 */
function PepReversalRequest($InvoiceNumber, $InvoiceDate, $TerminalCode, $MerchantCode)
{
    require_once(dirname(__FILE__) . '/includes/RSAProcessor.class.php');
    $processor = new RSAProcessor(dirname(__FILE__) . '/includes/certificate.xml', RSAKeyType::XMLFile);
    $data = array(
        'InvoiceNumber' => $InvoiceNumber,
        'InvoiceDate' => $InvoiceDate,
        'TerminalCode' => $TerminalCode,
        'MerchantCode' => $MerchantCode,
        'Timestamp' => date('Y/m/d H:i:s')
    );
    $sign_data = json_encode($data);
    $sign_data = sha1($sign_data, true);
    $sign_data = $processor->sign($sign_data);
    $sign = base64_encode($sign_data);
    $curl = curl_init('https://pep.shaparak.ir/Api/v1/Payment/RefundPayment');
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
 * Pep Verify Request
 * @param $InvoiceNumber
 * @param $InvoiceDate
 * @param $TerminalCode
 * @param $MerchantCode
 * @param $Amount
 * @return mixed
 */
function PepVerifyRequest($InvoiceNumber, $InvoiceDate, $TerminalCode, $MerchantCode, $Amount)
{
    require_once(dirname(__FILE__) . '/includes/RSAProcessor.class.php');
    $processor = new RSAProcessor(dirname(__FILE__) . '/includes/certificate.xml', RSAKeyType::XMLFile);
    $data = array(
        'InvoiceNumber' => $InvoiceNumber,
        'InvoiceDate' => $InvoiceDate,
        'TerminalCode' => $TerminalCode,
        'MerchantCode' => $MerchantCode,
        'Amount' => $Amount,
        'Timestamp' => date('Y/m/d H:i:s')
    );
    $sign_data = json_encode($data);
    $sign_data = sha1($sign_data, true);
    $sign_data = $processor->sign($sign_data);
    $sign = base64_encode($sign_data);
    $curl = curl_init('https://pep.shaparak.ir/Api/v1/Payment/VerifyPayment');
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
 * Display Error
 * @param string $pay_status
 * @param string $tran_id
 * @param string $order_id
 * @param string $amount
 * @param string $message
 */
function display_error($pay_status = '', $tran_id = '', $order_id = '', $amount = '', $message = '')
{
    global $modules, $CONFIG, $cb_gw_name;
    if ($pay_status == 'retry') {
        $client_mess='';
        $page_title = 'خطای موقت در پرداخت';
        $admin_mess = 'در هنگام بازگشت خریدار از بانک سرور بانک پاسخ نداد ، از خریدار درخواست شد صفحه را رفرش کند';
        $retry_mess = '
			<div style="margin:15px 0 21px 0;font-size: 12px;">
				سرور درگاه اینترنتی <span style="color:#ff0000;">به صورت موقت</span> با مشکل مواجه شده است ، جهت تکمیل تراکنش لحظاتی بعد بر روی دکمه زیر کلیک کنید
			</div>
			<div style="margin:20px 0 25px 0;color:#008800;" id="reqreload">
				<button onclick="reload_page()">تلاش مجدد</button>
			</div>
			<script>
				function reload_page(){
					document.getElementById("reqreload").innerHTML = "در حال تلاش مجدد لطفا صبر کنید ..";
					location.reload();
				}
			</script>';
    } elseif ($pay_status == 'reversal_done') {
        $page_title = 'مشکل در ارائه خدمات';
        $admin_mess = 'خریدار مبلغ را پرداخت کرد اما در هنگام بازگشت از بانک مشکلی در ارائه خدمات رخ داد ، دستور بازگشت وجه به حساب خریدار در بانک ثبت شد';
        $client_mess = 'پرداخت شما با شماره پیگیری ' . $tran_id . ' با موفقیت در بانک انجام شده است اما در ارائه خدمات مشکلی رخ داده است !<br />دستور بازگشت وجه به حساب شما در بانک ثبت شده است ، در صورتی که وجه پرداختی تا ساعات آینده به حساب شما بازگشت داده نشد با پشتیبانی تماس بگیرید (نهایت مدت زمان بازگشت به حساب 72 ساعت می باشد)';
    } elseif ($pay_status == 'reversal_error') {
        $page_title = 'مشکل در ارائه خدمات';
        $admin_mess = 'خریدار مبلغ را پرداخت کرد اما در هنگام بازگشت از بانک مشکلی در ارائه خدمات رخ داد ، دستور بازگشت وجه به حساب خریدار در بانک ثبت شد اما متاسفانه با خطا روبرو شد ، به این خریدار باید یا خدمات ارائه شود یا وجه استرداد گردد';
        $client_mess = 'پرداخت شما با شماره پیگیری ' . $tran_id . ' با موفقیت در بانک انجام شده است اما در ارائه خدمات مشکلی رخ داده است !<br />به منظور ثبت دستور بازگشت وجه به حساب شما در بانک اقدام شد اما متاسفانه با خطا روبرو شد ، لطفا به منظور دریافت خدمات و یا استرداد وجه پرداختی با پشتیبانی تماس بگیرید';
    } elseif ($pay_status == 'order_not_exist') {
        $page_title = 'سفارش یافت نشد';
        $admin_mess = 'سفارش در سایت یافت نشد';
        $client_mess = 'متاسفانه سفارش شما در سایت یافت نشد ! در صورتی که وجه پرداختی از حساب بانکی شما کسر شده باشد به صورت خودکار از سوی بانک به حساب شما باز خواهد گشت (نهایت مدت زمان بازگشت به حساب 72 ساعت می باشد)';
    } elseif ($pay_status == 'order_not_for_this_person') {
        $page_title = $admin_mess = 'شماره سفارش نادرست است';
        $client_mess = 'شماره سفارش نادرست است ؛ در صورت نیاز به پشتیبانی تماس بگیرید';
    } elseif ($pay_status == 'invoice_id_is_blank') {
        $page_title = 'خطا در پارامتر ورودی';
        $admin_mess = 'پس از بازگشت از بانک شماره سفارش موجود نبود';
        $client_mess = 'متاسفانه پارامتر ورودی شما معتبر نیست ! در صورتی که وجه پرداختی از حساب بانکی شما کسر شده باشد به صورت خودکار از سوی بانک به حساب شما باز خواهد گشت (نهایت مدت زمان بازگشت به حساب 72 ساعت می باشد)';
    } else {
        $page_title = $admin_mess = 'پرداخت انجام نشد';
        $client_mess = $message;
    }
    echo '
	<!DOCTYPE html> 
	<html xmlns="http://www.w3.org/1999/xhtml" lang="fa">
	<head>
	<title>' . $page_title . '</title>
	<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
	<style>body{font-family:tahoma;text-align:center;margin-top:30px;}</style>
	</head>
	<body>
		<div dir="rtl" style="font-family:tahoma;font-size:12px;border:1px dotted #c3c3c3; width:60%; margin: 50px auto 0 auto;line-height: 25px;padding-left: 12px;padding-top: 8px;">
			<span style="color:#ff0000;"><b>' . $page_title . '</b></span><br/>';
    if (isset($retry_mess)) {
        echo $retry_mess;
    } else {
        echo '<p style="text-align:right;margin-right:8px;">' . $client_mess . '</p><a href="' . $CONFIG['SystemURL'] . '/viewinvoice.php?id=' . $order_id . '">بازگشت >></a><br/><br/>';
    }
    echo '</div>
	</body>
	</html>';
    logTransaction($modules["name"]  ,  array( 'invoiceid'=>$order_id,'order_id'=>$order_id,'amount'=>$amount." ".(($modules['cb_gw_unit']>1) ? 'Toman' : 'Rial'),'tran_id'=>$tran_id, 'status'=>'unpaid' )  ,"ناموفق - $admin_mess");
    $notify['title'] = $cb_gw_name.' | '."تراکنش ناموفق";
    $notify['text']  = "\n\rGateway: $cb_gw_name\n\rAmount: $amount ".(($modules['cb_gw_unit']>1) ? 'Toman' : 'Rial')."\n\rOrder: $order_id\n\rInvoice: $order_id\n\r";
    if($modules['cb_email_on_error']) notifyEmail($notify);
    if($modules['cb_telegram_on_error']) notifyTelegram($notify);
    exit;
}

if($action==='callback') {

    if(empty($invoice_id)) die('Invoice ID Missing!');
    $cb_output['invoice_id'] = $invoice_id;

    $tran_id  = $order_id  = $invoice_id;
    $TransactionReferenceID = isset($_REQUEST['tref']) ? $_REQUEST['tref'] : '';
    $InvoiceNumber = isset($_REQUEST['iN']) ? $_REQUEST['iN'] : '';
    $InvoiceDate = isset($_REQUEST['iD']) ? $_REQUEST['iD'] : '';
    $TerminalID = $modules['cb_gw_terminal_id'];
    $MerchantID = $modules['cb_gw_merchant_id'];
    $cb_output['invoice_id'] = $invoice_id;
    $get_amount=null;
    if ($order_id == substr($InvoiceNumber, 0, -2)) {
        $invoiceid = checkCbInvoiceID($order_id, $modules['name']);
        if (!empty($invoiceid)) {
            checkCbTransID($TransactionReferenceID);
            if ($TransactionReferenceID != '') {
                $checkResult = PepCheckTransactionResult($TransactionReferenceID);
            } else {
                $checkResult = PepCheckTransactionResult(null, $InvoiceNumber, $InvoiceDate, $TerminalID, $MerchantID);
            }
            if (isset($checkResult) && $checkResult->IsSuccess && $checkResult->InvoiceNumber == $InvoiceNumber) {
                $get_amount = $checkResult->Amount;

                if (strlen($amount) == 0 || $get_amount != $amount_rial) {
                    $message = 'مبلغ پرداختی نادرست است ، وجه کسر شده به صورت خودکار از سوی بانک به حساب شما بازگشت داده خواهد شد.';
                } else {
                    $Request = PepVerifyRequest($InvoiceNumber, $InvoiceDate, $TerminalID, $MerchantID, $amount_rial);
                    if (isset($Request) && $Request->IsSuccess) {
                        $log = array(
                            'Invoice'        => $invoice_id,
                            'Amount'         => number_format($amount).(($modules['cb_gw_unit']>1)?' Toman':' Rial'),
                            'Order'          => $order_id,
                            'Transaction'    => $TransactionReferenceID,
                            'ReferenceID'    => $TransactionReferenceID
                        );
                        payment_success($log);
                        addInvoicePayment($invoice_id, $TransactionReferenceID, $amount, 0, $cb_gw_name);
                        // print("<pre>".print_r($cb_output,true)."</pre>");
                        redirect($invoice_URL);
                    } else {
                        $message = $Request->Message;
                    }
                }
            } else {
                $message = 'پرداخت توسط شما انجام نشده است';
            }
        } else {
            $error_code = 'order_not_exist';
        }
    } else {
        $error_code = 'order_not_for_this_person';
    }
    display_error(isset($error_code) ? $error_code : null, $TransactionReferenceID, $order_id, $get_amount, isset($message) ? $message : '');
}
elseif ($action==='send'){
    $order_id = $invoice_id . mt_rand(10, 100);
    $Request = PepPayRequest($order_id, $modules['cb_gw_terminal_id'], $modules['cb_gw_merchant_id'], $amount_rial, $callback_URL, '', $_POST['email']);
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
	</head>
	<body>
		<div dir="rtl" style="border:1px dotted #c3c3c3; width:60%; margin: 50px auto 0 auto;line-height: 25px;padding-left: 12px;padding-top: 8px;">
			<span style="color:#ff0000;"><b>خطا در ارسال به بانک</b></span><br/>
			<p style="text-align:center;">' . $message . '</p>
			<a href="' . $CONFIG['SystemURL'] . '/viewinvoice.php?id=' . $invoice_id . '">بازگشت</a><br/><br/>
		</div>
	</body>
	</html>';
    }
}