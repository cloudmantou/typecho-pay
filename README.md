# TypechoPay

TypechoPay 是一个 Typecho 支付插件骨架，按“订单中心 + 多支付网关适配器”实现。当前版本提供：

- 统一订单表 `pay_orders` 和通知事件表 `pay_events`
- 付费权益表 `pay_entitlements`
- 一次性入口 nonce 表 `pay_nonces`
- `/action/typechopay` 统一创建、通知、查询、返回入口
- PayPay Dynamic QR 直接 HMAC 客户端
- 微信支付 Native、支付宝 Page/Precreate 的 SDK 接入层和主动查单
- 后台订单列表和权益重发入口
- 文章短代码支付入口和金额防篡改签名
- 最小付费阅读隐藏内容块

## 安装

1. 将目录放到 `usr/plugins/TypechoPay`。
2. 在插件目录执行 `composer install --no-dev`，或发布时一并打包 `vendor/`。
3. 在 Typecho 后台启用 `TypechoPay`。
4. 在插件设置里启用支付网关并填写商户参数。
5. 生产环境设置独立的“入口签名密钥”。

启用插件会创建四张表：`{prefix}pay_orders`、`{prefix}pay_events`、`{prefix}pay_entitlements` 和 `{prefix}pay_nonces`。禁用插件不会删除表，便于审计。

## 短代码

在文章中加入：

```text
[typechopay amount="500" currency="JPY" subject="AppFlex 30日权限" gateways="paypay"]
```

`amount` 使用最小货币单位：JPY 为日元整数，CNY 为分。短代码渲染时会为每个支付按钮生成包含 `gateway`、`return_to`、`ts`、`nonce` 的 HMAC 签名，创建订单时服务端会重新验签、要求 10 分钟内有效，并一次性消费 nonce，防止用户修改隐藏字段篡改金额或重复提交同一入口。已购买当前 `biz_type` / `biz_id` 的访问者会看到“已购买”，不再显示付款按钮。

付费阅读内容可以这样包裹：

```text
[typechopay_content]
这里是购买后展示的内容。
[/typechopay_content]
```

也可以显式绑定业务对象：

```text
[typechopay_content biz_type="post" biz_id="123"]
这里是购买后展示的内容。
[/typechopay_content]
```

支付成功后会写入 `pay_entitlements`。登录用户按 `user_id` 判断权益，免登录购买按 guest cookie 的哈希判断权益。

## 网关状态

PayPay：

- 已实现 Dynamic QR 的直接请求签名、创建二维码/支付链接、主动查询基础逻辑。
- 主动查单使用 Dynamic QR 的 `/v2/codes/payments/{merchantPaymentId}`。
- Webhook 会校验 `Authorization: hmac OPA-Auth:...`，并要求时间偏移不超过 120 秒。
- 只在 `state=COMPLETED` 且签名有效时标记订单已支付。
- 通知和主动查单都会先检查 PayPay 商户配置是否完整。

微信支付：

- 创建 Native 订单依赖官方 `wechatpay/wechatpay` SDK。
- 回调实现了 `Wechatpay-*` 头、时间窗口、平台公钥验签和 APIv3 Key AES-GCM 解密。
- 回调和主动查单都会复核 `appid`、`mchid`。
- 只在 `trade_state=SUCCESS` 且金额/币种匹配时标记订单已支付。

支付宝：

- 创建 Page Pay / Precreate 订单依赖官方 `alipaysdk/openapi` 包内的 v2 AOP 类。
- 异步通知使用 SDK `rsaCheckV1` 验签，并校验 `app_id`、可选 `seller_id`、订单金额和状态。
- 主动查单使用 `AlipayTradeQueryRequest`。
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
- Alipay PHP SDK: https://github.com/alipay/alipay-sdk-php-all (`composer require alipaysdk/openapi`)

## 安全边界

- 不在代码里写任何商户密钥。
- 付款入口签名覆盖金额、币种、业务对象和支付网关，并通过 `pay_nonces` 做一次性消费。
- 支付状态只由验签通过的异步通知或可信主动查询更新。
- 前端可以 3 秒轮询本地订单状态，服务端会把远程主动查单节流到约 8 秒一次。
- 每次通知或实际主动查单都会写入 `pay_events`，便于审计；事件表保留 provider event id/type、平台交易号、IP、请求头和 payload。
- 订单更新是状态机控制的：只有 `pending` / `processing` 可以进入 `paid_pending_grant`；权益发放成功后才进入 `paid`，失败会进入 `grant_failed`，后台可重发权益。
- 支付成功页不会重载创建订单的 POST 页面，而是跳回签名保护的 `return_to`。
- 当前插件只实现最小付费阅读权益，不负责卡密库存/自动交付；如果要做卡密交付，应单独设计库存、锁定、发货和售后审计表。

## 验证

本仓库当前 Typecho 根目录没有 `config.inc.php`，本机也没有可用 `php` 命令。可在有 PHP 的环境中运行：

```sh
php usr/plugins/TypechoPay/tests/SignerTest.php
find usr/plugins/TypechoPay -name '*.php' -print0 | xargs -0 -n1 php -l
```
