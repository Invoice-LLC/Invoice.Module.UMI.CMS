<?php

require "InvoiceSDK/RestClient.php";
require "InvoiceSDK/common/SETTINGS.php";
require "InvoiceSDK/common/ORDER.php";
require "InvoiceSDK/CREATE_TERMINAL.php";
require "InvoiceSDK/CREATE_PAYMENT.php";

class invoicePayment extends payment {

    const TERMINAL_FILE = "invoice_tid";

    public function validate() {
        return true;
    }

    public function process($template = 'default') {
        $this->order->order();

        $amount = (float) $this->order->getActualPrice();
        $id = $this->order->getId();

        $order = new INVOICE_ORDER($amount);
        $order->id = $id;
        $settings = new SETTINGS($this->getTerminal());
        $settings->success_url = ( ((!empty($_SERVER['HTTPS'])) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']);

        $request = new CREATE_PAYMENT($order, $settings, []);
        $response = (new RestClient($this->object->getValue('login'), $this->object->getValue('api_key')))
            ->CreatePayment($request);

        if($response == null or isset($response->error)) throw new Exception("Payment error");

        $params = array(
            'payment_ur'    =>  $response->payment_url,
        );

        $this->order->setPaymentStatus('initialized');

        list($form_block) = def_module::loadTemplates('emarket/payment/invoice/' . $template, 'form_block');

        return def_module::parseTemplate($form_block, $params);
    }

    public function poll() {
        $notification = $this->getNotification();
        $type = $notification["notification_type"];
        $id = $notification["order"]["id"];

        $signature = $notification["signature"];

        if($signature != $this->getSignature($notification["id"], $notification["status"], $this->object->getValue('api_key'))) {
            return $this->response(["result" => "wrong signature"]);
        }

        if($type == "pay") {

            if($notification["status"] == "successful") {
                $this->order->setPaymentStatus('accepted');
                return $this->response(["result" => "payment successful"]);
            }
            if($notification["status"] == "error") {
                $this->order->setPaymentStatus('declined');
                return $this->response(["result" => "payment error"]);
            }
        }

        return $this->response(["result" => null]);
    }


    public function getSignature($id, $status, $key) {
        return md5($id.$status.$key);
    }

    public function getNotification() {
        $postData = file_get_contents('php://input');
        return json_decode($postData, true);
    }

    public function response($object) {
        $buffer = outputBuffer::current();
        $buffer->clear();
        $buffer->contentType('application/json');
        $buffer->push(json_encode($object));
        $buffer->end();
    }

    public function getTerminal() {
        if(!file_exists(self::TERMINAL_FILE)) file_put_contents(self::TERMINAL_FILE, "");
        $tid = file_get_contents(self::TERMINAL_FILE);

        if($tid == null or empty($tid)) {
            $request = new CREATE_TERMINAL($this->object->getValue('default_terminal_name'));
            $response = (new RestClient($this->object->getValue('login'), $this->object->getValue('api_key')))
                ->CreateTerminal($request);

            if($response == null) throw new Exception("Terminal Error");
            if(isset($response->error)) throw new Exception("Terminal Error(".$response->description.")");

            file_put_contents(self::TERMINAL_FILE, $response->id);
            $tid = $response->id;
        }

        return $tid;
    }
}