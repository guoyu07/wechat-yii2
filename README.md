# wechat-yii2

## Install
`composer require xiankun/wechat-yii2 "@dev" -vvv`

## Usage
```
//wechat应用组件
'wechat' => [
    'class' => 'xiankun\wechat\QyWechat',
    'corpid' => '',
    'corpsecret' => ''
]

$wechat = \Yii::$app->wechat;
```
