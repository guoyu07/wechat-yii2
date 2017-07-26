<?php
namespace xiankun\wechat;

use yii\base\Component;
use yii\base\InvalidParamException;
use GuzzleHttp\Client;
use yii\web\HttpException;

/**
 * 企业微信api
 * @package common\components\qywechat
 * @author xiankun.geng<james@lightbeijing.com>
 * @version 1.2 (现实现了成员登陆、发送信息等功能.)
 * @time 2017-07-17
 */
class QyWechat extends Component
{
    /** @const string 主动调用最基本的URL */
    const WECHAT_BASE_URL = "https://qyapi.weixin.qq.com";

    /** @const string 企业获取code的URL */
    const WECHAT_OAUTH2_AUTHORIZE_URL = 'https://open.weixin.qq.com/connect/oauth2/authorize';

    /** @const string 企业或服务商网站引导用户进入登录授权页的URL */
    const WECHAT_LOGIN_PAGE_URL = 'https://qy.weixin.qq.com/cgi-bin/loginpage?corp_id=%s&redirect_uri=%s&usertype=%s';

    /** @const string 获取AccessToken的URI */
    const WECHAT_GET_TOKEN_URI = "/cgi-bin/gettoken";

    /** @const string 发送消息的URI */
    const WECHAT_SEND_MESSAGE_URI = "/cgi-bin/message/send";

    /** @const string 该API用于获取企业号某个应用的基本信息，包括头像、昵称、帐号类型、认证类型、可见范围等信息的URI */
    const WECHAT_GET_AGENT_URI = "/cgi-bin/agent/get";

    /** @const string 根据code获取成员信息的URI */
    const WECHAT_GET_USERINFO_URI = "/cgi-bin/user/getuserinfo";

    /** @const string 使用user_ticket获取成员详情的URI */
    const WECHAT_GET_USERDETAIL_URI = "/cgi-bin/user/getuserdetail";

    /** @const string 上传临时素材文件的URI */
    const WECHAT_UPLOAD_MEDIA_URI = "/cgi-bin/media/upload";

    /** @const string 通过media_id获取图片、语音、视频等文的URI */
    const WECHAT_GET_MEDIA_URI = "/cgi-bin/media/get";

    /** @const string 获取企业号登录用户信息的URI */
    const WECHAT_GET_LOGIN_INFO_URI = "/cgi-bin/service/get_login_info";

    /** @const string access_token的缓存键名称 */
    const CACHE_ACCESS_TOKEN_KEY = 'access_token';

    /** @const string cache duration 缓存默认持续时间 */
    const CACHE_DEFAULT_DURATION = '7200';

    /** @var object Guzzle客户端 */
    private static $_guzzle_client;

    /** @var string 企业Id */
    public $corpid;

    /** @var string 管理组的凭证密钥 */
    public $corpsecret;

    /** @var string 企业号的全局唯一票据，调用接口时需携带AccessToken */
    private $_access_token;

    public function init()
    {
        if ($this->corpid == null) {
            throw new InvalidParamException('corpid does not exist.');
        }

        if ($this->corpsecret == null) {
            throw new InvalidParamException('corpsecret does not exist.');
        }
    }

    /**
     * 获取Guzzle客户端
     * @return object
     */
    public function getGuzzleClient()
    {
        //不存在Guzzle客户端则创建
        if (!(static::$_guzzle_client instanceof Client)) {
            //创建Guzzle客户端
            static::$_guzzle_client = new Client([
                'base_uri' => self::WECHAT_BASE_URL,
                'timeout'  => 6.0,
            ]);
        }

        return static::$_guzzle_client;
    }

    /**
     * 根据键获得缓存数据
     * @param  $key  键名
     * @return  mixed
     */
    public function getCache($key)
    {
        return \Yii::$app->cache->get($key);
    }

    /**
     * 设置缓存
     * @param string $key  键名
     * @param array $value  缓存数据
     * @param integer $duration  缓存时间
     * @return bool
     */
    public function setCache($key = null, $value = null, $duration = null)
    {
        return \Yii::$app->cache->set($key, $value, !empty($duration) ? $duration : self::CACHE_DEFAULT_DURATION);
    }

    /**
     * 通过Guzzle发送GET请求
     * @param null $data
     * @param null $calledFunction
     * @param bool $is_access_token
     * @return mixed
     */
    public function guzzleRequestForGet($data = null, $calledFunction = null, $is_access_token = true)
    {
        if ($is_access_token) $data = array_merge($data, ['access_token' => $this->accessToken]);

        $guzzle_response = $this->guzzleClient->request('GET', $calledFunction, [
            'query' => $data
        ]);
        return json_decode($guzzle_response->getBody()->getContents());
    }

    /**
     * 通过Guzzle发送POST请求
     * @param null $data
     * @param null $calledFunction
     * @param bool $is_access_token
     * @return mixed
     */
    public function guzzleRequestForPost($data = null, $calledFunction = null, $is_access_token = true)
    {
        if (empty($calledFunction)) throw new InvalidParamException('calledFunction does not exist.');

        $data = json_encode($data, JSON_UNESCAPED_UNICODE);

        if ($is_access_token) $calledFunction = $calledFunction."?access_token=".$this->accessToken;

        $guzzle_response = $this->guzzleClient->request('POST', $calledFunction, [
            'body' => $data
        ]);
        return json_decode($guzzle_response->getBody()->getContents());
    }

    /**
     * 获取accessToekn
     * @return mixed
     * @throws HttpException
     */
    public function getAccessToken()
    {
        if ($this->_access_token == null || $this->_access_token['expires_in'] < YII_BEGIN_TIME) {
            $result = $this->getCache(self::CACHE_ACCESS_TOKEN_KEY);
            if ($result == false) {

                if (!($result = $this->requestAccessToken())) {
                    throw new HttpException(500, 'Fail to get access_token from wechat server.');
                }

                $this->setCache(self::CACHE_ACCESS_TOKEN_KEY, $result, $result->expires_in);
            }

            $this->accessToken = $result;
        }
        return $this->_access_token['access_token'];
    }

    /**
     * 发送请求获取accessToekn
     * @return mixed
     */
    public function requestAccessToken()
    {
        $data = [
            'corpid' => $this->corpid,
            'corpsecret' => $this->corpsecret
        ];
        return $this->guzzleRequestForGet($data, self::WECHAT_GET_TOKEN_URI, false);
    }

    /**
     * 设置accessToekn
     * @param null $result
     */
    public function setAccessToken($result = null)
    {
        if (!$result->access_token) {
            throw new InvalidParamException('access_token does not exist.');
        }

        if (!$result->expires_in) {
            throw new InvalidParamException('expires_in does not exist.');
        }

        $this->_access_token = [
            'access_token' => $result->access_token,
            'expires_in' => $result->expires_in
        ];
    }

    /**
     * 获取企业或服务商网站引导用户进入登录授权页
     * @param null $redirect_uri
     * @param string $usertype
     * @return int
     */
    public function getLoginPage($redirect_uri = null, $usertype = 'member')
    {
        if (empty($redirect_uri)) throw new InvalidParamException('redirect_uri does not exist.');
        $redirect_uri = urlencode($redirect_uri);
        return printf(self::WECHAT_LOGIN_PAGE_URL, $this->corpid, $redirect_uri, $usertype);
    }

    /**
     * 获取企业号登录用户信息
     * @param null $auth_code
     * @return mixed
     */
    public function requestGetLoginInfo($auth_code = null)
    {
        if (empty($auth_code)) throw new InvalidParamException('auth_code does not exist.');

        return $this->guzzleRequestForPost([
            'auth_code' => $auth_code,
        ], self::WECHAT_GET_LOGIN_INFO_URI);
    }

    /**
     * 企业获取code
     * @param null $redirect_uri
     * @param string $scope
     * @param int $agentid
     * @param null $state
     * @return string
     */
    public function getOauth2AuthorizeURL($redirect_uri = null, $scope = 'snsapi_base', $agentid = 0, $state = 'STATE')
    {
        if (empty($redirect_uri)) throw new InvalidParamException('redirect_uri does not exist.');

        $redirect_uri = base64_encode($redirect_uri);
        return self::WECHAT_OAUTH2_AUTHORIZE_URL."?appid=$this->corpid&redirect_uri=$redirect_uri&response_type=code&scope=$scope&agentid=$agentid&state=$state#wechat_redirect";
    }

    /**
     * 通过code获得用户详细信息
     * @param null $code
     * @return mixed
     */
    public function requestGetUserInfo($code = null)
    {
        if(empty($user_ticket)) throw new InvalidParamException('code does not exist.');

        $data = ['code' => $code];
        return $this->guzzleRequestForGet($data, self::WECHAT_GET_AGENT_URI);
    }

    /**
     * 发送消息的请求
     * @param null $data
     * @return mixed
     */
    public function requestSendMessage($data = null)
    {
        return $this->guzzleRequestForPost($data, self::WECHAT_SEND_MESSAGE_URI);
    }

    /**
     * 发送text格式的消息
     * @param string $touser 成员ID列表（消息接收者，多个接收者用‘|’分隔，最多支持1000个）。特殊情况：指定为@all，则向关注该企业应用的全部成员发送
     * @param null $content 消息内容，最长不超过2048个字节，注意：主页型应用推送的文本消息在微信端最多只显示20个字（包含中英文）
     * @param string $toparty 部门ID列表，多个接收者用‘|’分隔，最多支持100个。当touser为@all时忽略本参数
     * @param string $agentid 企业应用的id，整型。可在应用的设置页面查看
     * @param string $safe 表示是否是保密消息，0表示否，1表示是，默认0
     * @return mixed
     */
    public function sendText($touser = '@all', $content = null, $toparty = '@all', $agentid = '0', $safe = '0')
    {
        return $this->requestSendMessage([
            'touser' => $touser,
            'msgtype' => 'text',
            'agentid' => $agentid,
            'toparty' => $toparty,
            'text' => [
                'content' => $content
            ],
            'safe' => $safe
        ]);
    }

    /**
     * 发送image消息
     * @param string $touser 成员ID列表（消息接收者，多个接收者用‘|’分隔，最多支持1000个）。特殊情况：指定为@all，则向关注该企业应用的全部成员发送
     * @param null $media_id 图片媒体文件id，可以调用上传临时素材或者永久素材接口获取,永久素材media_id必须由发消息的应用创建
     * @param string $toparty 部门ID列表，多个接收者用‘|’分隔，最多支持100个。当touser为@all时忽略本参数
     * @param string $agentid 企业应用的id，整型。可在应用的设置页面查看
     * @param string $safe 表示是否是保密消息，0表示否，1表示是，默认0
     * @return mixed
     */
    public function sendImage($touser = '@all', $media_id = null, $toparty = '@all', $agentid = '0', $safe = '0')
    {
        return $this->requestSendMessage([
            'touser' => $touser,
            'toparty' => $toparty,
            'msgtype' => 'image',
            'agentid' => $agentid,
            'image' => [
                'media_id' => $media_id
            ],
            'safe' => $safe
        ]);
    }

    /**
     * 该API用于获取企业号某个应用的基本信息，包括头像、昵称、帐号类型、认证类型、可见范围等信息
     * @param int $agentid
     * @return mixed
     */
    public function requestGetAgent($agentid = 0)
    {
        $data = ['agentid' => $agentid];
        return $this->guzzleRequestForGet($data, self::WECHAT_GET_AGENT_URI);
    }

    /**
     * 通过user_ticket获得用户详细信息
     * @param null $user_ticket
     * @return mixed
     */
    public function requestGetUserDetail($user_ticket = null)
    {
        if (empty($user_ticket)) throw new InvalidParamException('user_ticket does not exist.');

        $data = ['user_ticket' => $user_ticket];
        return $this->guzzleRequestForGet($data, self::WECHAT_GET_USERDETAIL_URI);
    }

    /**
     * 请求上传临时文件
     * @param null $media
     * @param null $type
     * @return mixed
     */
    public function requestUploadMedia($media = null, $type = null)
    {
        if (empty($media)) throw new InvalidParamException('media does not exist.');
        if (empty($type)) throw new InvalidParamException('type does not exist.');

        return $this->guzzleRequestForPost([
            'type' => $type,
            'media' => $media
        ], self::WECHAT_UPLOAD_MEDIA_URI);
    }

    /**
     * 通过media_id获取图片、语音、视频等文件，协议和普通的http文件下载完全相同。该接口即原"下载多媒体文件"接口。
     * @param null $media_id
     * @return mixed
     */
    public function requestGetMeida($media_id = null)
    {
        if (empty($media_id)) throw new InvalidParamException('media id does not exist.');

        $data = ['media_id' => $media_id];
        return $this->guzzleRequestForGet($data, self::WECHAT_GET_MEDIA_URI);
    }
}
/** End file of QyWechat.php */