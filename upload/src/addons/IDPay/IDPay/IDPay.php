<?php
/**
 * IDPay payment gateway
 *
 * @developer JMDMahdi
 * @publisher IDPay
 * @copyright (C) 2018 IDPay
 * @license http://www.gnu.org/licenses/gpl-2.0.html GPLv2 or later
 *
 * http://idpay.ir
 */
namespace IDPay\IDPay;

use XF\Entity\PaymentProfile;
use XF\Entity\PurchaseRequest;
use XF\Mvc\Controller;
use XF\Payment\AbstractProvider;
use XF\Payment\CallbackState;
use XF\Purchasable\Purchase;

class IDPay extends AbstractProvider
{
    public function getTitle()
    {
        return 'IDPay';
    }

    public function verifyConfig(array &$options, &$errors = [])
    {
        if (empty($options['idpay_api_key'])) {
            $errors[] = \XF::phrase('you_must_provide_idpay_api_key');
        }
        return (empty($errors) ? false : true);
    }

    public function initiatePayment(Controller $controller, PurchaseRequest $purchaseRequest, Purchase $purchase)
    {
        $api_key = $purchase->paymentProfile->options['idpay_api_key'];
        $sandbox = $purchase->paymentProfile->options['idpay_sandbox'] == 1 ? 'true' : 'false';
        $amount = intval($purchase->cost);
        $desc = ($purchase->title ?: ('Invoice#' . $purchaseRequest->request_key));
        $callback = $this->getCallbackUrl();

        if (empty($amount)) {
            return 'واحد پول انتخاب شده پشتیبانی نمی شود.';
        }

        $data = array(
            'order_id' => $purchaseRequest->request_key,
            'amount' => $amount,
            'phone' => '',
            'desc' => $desc,
            'callback' => $callback,
        );

        $ch = curl_init('https://api.idpay.ir/v1/payment');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'X-API-KEY:' . $api_key,
            'X-SANDBOX:' . $sandbox,
        ));

        $result = curl_exec($ch);
        $result = json_decode($result);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_status != 201 || empty($result) || empty($result->id) || empty($result->link)) {
            return sprintf('خطا هنگام ایجاد تراکنش. کد خطا: %s', $http_status);
        } else {
            @session_start();
            $_SESSION[$result->id . '1'] = $purchase->returnUrl;
            $_SESSION[$result->id . '2'] = $purchase->cancelUrl;
            setcookie($result->id . '1', $purchase->returnUrl, time() + 1200, '/');
            setcookie($result->id . '2', $purchase->cancelUrl, time() + 1200, '/');
            return $controller->redirect($result->link, '');
        }
    }

    public function supportsRecurring(PaymentProfile $paymentProfile, $unit, $amount, &$result = self::ERR_NO_RECURRING)
    {
        return false;
    }

    public function setupCallback(\XF\Http\Request $request)
    {
        $state = new CallbackState();
        $state->transactionId = $request->filter('id', 'str');
        $state->costAmount = $request->filter('amount', 'unum');
        $state->taxAmount = 0;
        $state->costCurrency = 'IRR';
        $state->paymentStatus = $request->filter('status', 'unum');
        $state->requestKey = $request->filter('order_id', 'str');
        $state->ip = $request->getIp();
        $state->_POST = $_REQUEST;
        return $state;
    }

    public function validateTransaction(CallbackState $state)
    {
        if (!$state->requestKey) {
            $state->logType = 'info';
            $state->logMessage = 'No purchase request key. Unrelated payment, no action to take.';
            return false;
        }
        if (!$state->getPurchaseRequest()) {
            $state->logType = 'info';
            $state->logMessage = 'Invalid request key. Unrelated payment, no action to take.';
            return false;
        }
        if (!$state->transactionId) {
            $state->logType = 'info';
            $state->logMessage = 'No transaction or subscriber ID. No action to take.';
            return false;
        }
        $paymentRepo = \XF::repository('XF:Payment');
        $matchingLogsFinder = $paymentRepo->findLogsByTransactionId($state->transactionId);
        if ($matchingLogsFinder->total()) {
            $state->logType = 'info';
            $state->logMessage = 'Transaction already processed. Skipping.';
            return false;
        }
        return parent::validateTransaction($state);
    }

    public function validateCost(CallbackState $state)
    {
        $purchaseRequest = $state->getPurchaseRequest();
        $cost = $purchaseRequest->cost_amount;
        $currency = $purchaseRequest->cost_currency;
        $costValidated = (round(($state->costAmount - $state->taxAmount), 2) == round($cost, 2) && $state->costCurrency == $currency);
        if (!$costValidated) {
            $state->logType = 'error';
            $state->logMessage = 'Invalid cost amount';
            return false;
        }
        return true;
    }

    public function getPaymentResult(CallbackState $state)
    {
        if (intval($state->paymentStatus) == 100) {
            $state->paymentResult = CallbackState::PAYMENT_RECEIVED;
        } else {
            $state->paymentResult = CallbackState::PAYMENT_REINSTATED;
        }
    }

    public function prepareLogData(CallbackState $state)
    {
        $state->logDetails = $state->_POST;
    }

    public function completeTransaction(CallbackState $state)
    {
        @session_start();
        $router = \XF::app()->router('public');
        $returnUrl = $_SESSION[$state->transactionId . '1'];
        $cancelUrl = $_SESSION[$state->transactionId . '2'];
        if (!$returnUrl) $returnUrl = $_COOKIE[$state->transactionId . '1'];
        if (!$cancelUrl) $cancelUrl = $_COOKIE[$state->transactionId . '2'];
        if (!$returnUrl) $returnUrl = $router->buildLink('canonical:account/upgrade-purchase');
        if (!$cancelUrl) $cancelUrl = $router->buildLink('canonical:account/upgrades');
        unset($_SESSION[$state->transactionId . '1'], $_SESSION[$state->transactionId . '2']);
        setcookie($state->transactionId . '1', './?', time(), '/');
        setcookie($state->transactionId . '2', './?', time(), '/');

        $api_key = $state->paymentProfile->options['idpay_api_key'];
        $sandbox = $state->paymentProfile->options['idpay_sandbox'] == 1 ? 'true' : 'false';
        $url = $cancelUrl;
        $data = array(
            'id' => $state->transactionId,
            'order_id' => $state->requestKey
        );

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.idpay.ir/v1/payment/inquiry');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'X-API-KEY:' . $api_key,
            'X-SANDBOX:' . $sandbox,
        ));

        $result = curl_exec($ch);
        $result = json_decode($result);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_status != 200) {
            $state->logType = 'error';
            $state->logMessage = sprintf('خطا هنگام بررسی وضعیت تراکنش. کد خطا: %s', $http_status);
        }

        $inquiry_status = empty($result->status) ? NULL : $result->status;
        $inquiry_track_id = empty($result->track_id) ? NULL : $result->track_id;
        $inquiry_order_id = empty($result->order_id) ? NULL : $result->order_id;
        $inquiry_amount = empty($result->amount) ? NULL : $result->amount;

        if (empty($inquiry_status) || empty($inquiry_track_id) || empty($inquiry_amount) || $inquiry_amount != $amount || $inquiry_status != 100) {
            $state->logType = 'error';
            $state->logMessage = $this->idpay_get_failed_message($state->paymentProfile->options['idpay_failed_message'], $inquiry_track_id, $inquiry_order_id);

        } else {
            $state->transactionId = $inquiry_track_id;
            parent::completeTransaction($state);
            $url = $returnUrl;
        }
        @header('location: ' . $url);
        echo '<script>document.location="' . $url . '";</script>';
        exit;
    }

    public function idpay_get_failed_message($failed_massage, $track_id, $order_id)
    {
        return str_replace(["{track_id}", "{order_id}"], [$track_id, $order_id], $failed_massage);
    }

    public function idpay_get_success_message($success_massage, $track_id, $order_id)
    {
        return str_replace(["{track_id}", "{order_id}"], [$track_id, $order_id], $success_massage);
    }

}