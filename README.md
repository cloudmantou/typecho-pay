# TypechoPay

TypechoPay 是一个 Typecho 支付插件骨架，按“订单中心 + 多支付网关适配器”实现。当前版本提供：

- 统一订单表 `pay_orders` 和通知事件表 `pay_events`
- `/action/typechopay` 统一创建、通知、查询、返回入口
- PayPay Dynamic QR 直接 HMAC 客户端
- 微信支付 Native、支付宝 Page/Precreate 的 SDK 接入层
- 后台订单列表
- 文章短代码支付入口和金额防篡改签名

## 安装

1. 将目录放到 `usr/plugins/TypechoPay`。
2. 在 Typecho 后台启用 `TypechoPay`。
3. 在插件设置里启用支付网关并填写商户参数。
4. 生产环境设置独立的“入口签名密钥”。

启用插件会创建两张表：`{prefix}pay_orders` 和 `{prefix}pay_events`。禁用插件不会删除表，便于审计。

## 短代码

在文章中加入：

```text
[typechopay amount="500" currency="JPY" subject="AppFlex 30日权限" gateways="paypay"]
```

`amount` 使用最小货币单位：JPY 为日元整数，CNY 为分。短代码渲染时会生成 HMAC 签名，创建订单时服务端会重新验签，防止用户修改隐藏字段篡改金额。

## 网关状态

PayPay：

- 已实现 Dynamic QR 的直接请求签名、创建二维码/支付链接、主动查询基础逻辑。
- Webhook 会校验 `Authorization: hmac OPA-Auth:...`，并要求时间偏移不超过 120 秒。
- 只在 `state=COMPLETED` 且签名有效时标记订单已支付。

微信支付：

- 创建 Native 订单依赖官方 `wechatpay/wechatpay` SDK。
- 回调实现了 `Wechatpay-*` 头、时间窗口、平台公钥验签和 APIv3 Key AES-GCM 解密。
- 只在 `trade_state=SUCCESS` 且金额/币种匹配时标记订单已支付。

支付宝：

- 创建 Page Pay / Precreate 订单依赖支付宝 PHP SDK 中的 `AopClient`。
- 异步通知使用 SDK `rsaCheckV1` 验签，并校验 `app_id`、可选 `seller_id`、订单金额和状态。
- 只有 `TRADE_SUCCESS` / `TRADE_FINISHED` 会标记已支付。

## 回调地址

```text
/action/typechopay?do=notify&gateway=paypay
/action/typechopay?do=notify&gateway=wechat
/action/typechopay?do=notify&gateway=alipay
```

主动查询：

```text
/action/typechopay?do=query&out_trade_no=TP...
```

## 官方资料

- WeChat Pay PHP SDK: https://github.com/wechatpay-apiv3/wechatpay-php
- PayPay Dynamic QR: https://www.paypay.ne.jp/opa/doc/jp/v1.0/dynamicqrcode
- PayPay HMAC: https://www.paypay.ne.jp/opa/doc/jp/v1.0/api_authorization.html
- PayPay PHP SDK: https://github.com/paypay/paypayopa-sdk-php
- Alipay PHP SDK: https://github.com/alipay/alipay-sdk-php-all

## 安全边界

- 不在代码里写任何商户密钥。
- 支付状态只由验签通过的异步通知或可信主动查询更新。
- 每次通知都会写入 `pay_events`，便于审计。
- 订单更新是幂等的：已支付订单不会被重复通知覆盖。
- 当前插件不负责卡密库存/自动交付；如果要做卡密交付，应单独设计库存、锁定、发货和售后审计表。

## 验证

本仓库当前 Typecho 根目录没有 `config.inc.php`，本机也没有可用 `php` 命令。可在有 PHP 的环境中运行：

```sh
php usr/plugins/TypechoPay/tests/SignerTest.php
find usr/plugins/TypechoPay -name '*.php' -print0 | xargs -0 -n1 php -l
```
