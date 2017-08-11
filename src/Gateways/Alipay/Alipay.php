<?php 

namespace Yansongda\Pay\Gateways\Alipay;

use Yansongda\Pay\Support\Config;
use Yansongda\Pay\Traits\HasHttpRequest;
use Yansongda\Pay\Contracts\GatewayInterface;
use Yansongda\Pay\Exceptions\InvalidArgumentException;

/**
*   
*/
abstract class Alipay implements GatewayInterface
{
    use HasHttpRequest;

    /**
     * 支付宝支付网关
     * @var string
     */
    protected $gateway = 'https://openapi.alipaydev.com/gateway.do';

    /**
     * 支付宝公共参数
     * @var [type]
     */
    protected $config;

    /**
     * 用户的传参
     * @var [type]
     */
    protected $user_config;

    /**
     * [__construct description]
     * @author yansongda <me@yansongda.cn>
     * @version 2017-08-05
     * @param   [type]     $config [description]
     */
    public function __construct($config)
    {
        if ($config['app_id'] === '') {
            throw new InvalidArgumentException("Missing Config -- [app_id]");
        }

        $this->user_config = new Config($config);

        $this->config = [
            'app_id' => $this->user_config->get('app_id', ''),
            'method' => '',
            'format' => 'JSON',
            'charset' => 'utf-8',
            'sign_type' => 'RSA2',
            'version' => '1.0',
            'return_url' => $this->user_config->get('return_url', ''),
            'notify_url' => $this->user_config->get('notify_url', ''),
            'timestamp' => date('Y-m-d H:i:s'),
            'sign' => '',
            'biz_content' => '',
        ];
    }


    /**
     * 对外接口 - 支付
     * @author JasonYan <me@yansongda.cn>
     * @version 2017-07-30
     * @param   [type]     $config_biz [description]
     * @param   [type]     $type       [description]
     * @return  [type]                 [description]
     */
    public function pay($config_biz = [])
    {
        $config_biz['product_code'] = $this->getPayProductCode();

        $this->config['method'] = $this->getPayMethod();
        $this->config['biz_content'] = json_encode($config_biz, JSON_UNESCAPED_UNICODE);
        $this->config['sign'] = $this->getSign();

        return $this->buildPayHtml();
    }

    /**
     * 对外接口 - 退款
     * @author JasonYan <me@yansongda.cn>
     * @version 2017-07-29
     * @return  [type]     [description]
     */
    public function refund($config_biz = [])
    {
        $this->config['method'] = 'alipay.trade.refund';
        $this->config['biz_content'] = json_encode($config_biz, JSON_UNESCAPED_UNICODE);
        $this->config['sign'] = $this->getSign();
        
        $data = json_decode($this->post($this->gateway, $this->config), true);

        if ($data['alipay_trade_refund_response']['code'] === '10000') {
            return $this->verify($data['alipay_trade_refund_response'], $data['sign'], true);
        }

        return $data['alipay_trade_refund_response'];
    }

    /**
     * 对外接口 - 关闭
     * @author JasonYan <me@yansongda.cn>
     * @version 2017-07-29
     * @return  [type]     [description]
     */
    public function close($config_biz = [])
    {
        $this->config['method'] = 'alipay.trade.close';
        $this->config['biz_content'] = json_encode($config_biz, JSON_UNESCAPED_UNICODE);
        $this->config['sign'] = $this->getSign();
        
        $data = json_decode($this->post($this->gateway, $this->config), true);

        if ($data['alipay_trade_close_response']['code'] === '10000') {
            return $this->verify($data['alipay_trade_close_response'], $data['sign'], true);
        }

        return $data['alipay_trade_close_response'];
    }

    /**
     * 对外接口 - 验证
     * @author yansongda <me@yansongda.cn>
     * @version 2017-08-11
     * @param   array      $data 待签名数组
     * @param   string     $sign 签名字符串-支付宝服务器发送过来的原始串
     * @param   bool       $sync 是否同步验证
     * @return  [type]           [description]
     */
    public function verify(array $data, $sign, $sync = false)
    {
        if (is_null($this->user_config->get('ali_public_key'))) {
            throw new InvalidArgumentException("Missing Config -- [ali_public_key]");
        }

        $res = "-----BEGIN PUBLIC KEY-----\n" .
                wordwrap($this->user_config->get('ali_public_key'), 64, "\n", true) .
                "\n-----END PUBLIC KEY-----";

        if ($sync) {
            $data = json_encode($data, JSON_UNESCAPED_UNICODE);
        } else {
            $data = $this->getSignContent($data, true);
        }
        
        if (openssl_verify($data, base64_decode($sign), $res, OPENSSL_ALGO_SHA256) === 1) {
            return true;
        }

        return false;
    }


    /**
     * [getMethod description]
     * @author yansongda <me@yansongda.cn>
     * @version 2017-08-10
     * @return  [type]     [description]
     */
    abstract protected function getPayMethod();

    /**
     * [getProductCode description]
     * @author yansongda <me@yansongda.cn>
     * @version 2017-08-10
     * @return  [type]     [description]
     */
    abstract protected function getPayProductCode();

    /**
     * [buildHtmlPay description]
     * @author yansongda <me@yansongda.cn>
     * @version 2017-08-11
     * @return  [type]     [description]
     */
    protected function buildPayHtml()
    {
        $sHtml = "<form id='alipaysubmit' name='alipaysubmit' action='".$this->gateway."?charset=utf-8' method='POST'>";
        while (list ($key, $val) = each ($this->config)) {
            $val = str_replace("'","&apos;",$val);
            $sHtml.= "<input type='hidden' name='".$key."' value='".$val."'/>";
        }
        $sHtml = $sHtml."<input type='submit' value='ok' style='display:none;''></form>";
        $sHtml = $sHtml."<script>document.forms['alipaysubmit'].submit();</script>";
        
        return $sHtml;
    }

    /**
     * 签名
     * @author yansongda <me@yansongda.cn>
     * @version 2017-08-10
     * @return  [type]     [description]
     */
    protected function getSign()
    {
        if (is_null($this->user_config->get('private_key'))) {
            throw new InvalidArgumentException("Missing Config -- [private_key]");
        }

        $res = "-----BEGIN RSA PRIVATE KEY-----\n" .
                wordwrap($this->user_config->get('private_key'), 64, "\n", true) .
                "\n-----END RSA PRIVATE KEY-----";

        openssl_sign($this->getSignContent($this->config), $sign, $res, OPENSSL_ALGO_SHA256);

        return base64_encode($sign);
    }

    /**
     * 待签名数组
     * @author yansongda <me@yansongda.cn>
     * @version 2017-08-11
     * @param   array      $toBeSigned [description]
     * @param   boolean    $verify     是否异步同时验证签名
     * @return  [type]                 [description]
     */
    protected function getSignContent(array $toBeSigned, $verify = false)
    {
        ksort($toBeSigned);

        $stringToBeSigned = "";
        foreach ($toBeSigned as $k => $v) {
            if ($verify && $k != 'sign' && $k != 'sign_type') {
                $stringToBeSigned .= $k . "=" . $v . "&";
            }
            if (!$verify && $v !== '' && !is_null($v) && $k != 'sign' && "@" != substr($v, 0, 1)) {
                $stringToBeSigned .= $k . "=" . $v . "&";
            }
        }
        $stringToBeSigned = substr($stringToBeSigned, 0, -1);
        unset ($k, $v);

        return $stringToBeSigned;
    }

}