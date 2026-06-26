# TypechoPay

TypechoPay 是一个 Typecho 支付插件骨架，按“订单中心 + 多支付网关适配器”实现。当前版本提供：

- 统一订单表 `pay_orders` 和通知事件表 `pay_events`
- 付费权益表 `pay_entitlements`
- 商品表 `pay_products`、商品交付规则表 `pay_product_deliverables`、订单交付记录表 `pay_fulfillments`
- 卡密批次表 `pay_card_batches` 和卡密库存表 `pay_card_items`
- 一次性入口 nonce 表 `pay_nonces`
- `/action/typechopay` 统一创建、通知、查询、返回入口
- PayPay Dynamic QR 直接 HMAC 客户端
- 微信支付 Native、支付宝 Page/Precreate 的 SDK 接入层和主动查单
- 后台订单列表、商品与卡密管理、权益/交付重发入口
- 商品短代码支付入口、旧金额短代码兼容层和入口防篡改签名
- 最小付费阅读隐藏内容块

## 安装

1. 将目录放到 `usr/plugins/TypechoPay`。
2. 确认 PHP 已启用 `json`、`openssl`、`curl` 扩展。
3. 在插件目录执行 `composer install --no-dev`，或发布时一并打包 `vendor/`；插件会自动加载 `vendor/autoload.php`。
4. 在 Typecho 后台启用 `TypechoPay`。
5. 在插件设置里启用支付网关并填写商户参数。
6. 生产环境设置独立的”入口签名密钥”。

启用插件会创建订单、事件、权益、商品、交付、卡密库存和 nonce 相关表，并通过 `typechopay_schema_version` 记录 schema 版本。升级后请在后台停用再启用一次插件，或执行插件启用流程，让新增列和表完成迁移。禁用插件不会删除表，便于审计。

## 后台管理

启用插件后，左侧菜单会出现 **TypechoPay** 菜单，包含：

- **支付订单**：查看所有订单，支持按订单号筛选，可对已支付订单重发交付
- **商品与卡密**：创建服务端商品、导入卡密库存、查看可用/预留/已发库存
- **支付设置说明**：查看回调地址、各网关配置指南、短代码使用说明和常见问题

插件配置在 **后台 → 控制台 → 插件 → TypechoPay → 设置** 中填写，包含：

- **基础设置**：启用支付方式、默认币种、入口签名密钥
- **PayPay 配置**：环境、API Key、API Secret、Merchant ID
- **微信支付配置**：AppID、商户号、证书序列号、私钥路径、APIv3 Key 等
- **支付宝配置**：支付模式、AppID、应用私钥、支付宝公钥、Seller ID

## 短代码

推荐使用服务端商品模式：

```text
[typechopay product="article-123-premium" gateways="paypay,alipay"]
```

或：

```text
[typechopay product_id="18" gateways="alipay"]
```

商品模式下，文章 HTML 只包含商品标识和入口签名。用户点击支付时，服务端会从 `pay_products` 读取当前价格、币种、购买策略和交付规则，再创建订单；订单会保存 `product_id`、`product_version` 和 `product_snapshot_json`，便于后续审计。

旧版短代码仍可使用：

```text
[typechopay amount="500" currency="JPY" subject="AppFlex 30日权限" gateways="paypay"]
```

`amount` 使用最小货币单位：JPY 为日元整数，CNY 为分。旧版短代码渲染时会把金额放入长期入口签名，适合过渡兼容；如果站点开启 CDN/静态缓存，管理员改价后旧 HTML 仍可能保留旧金额，正式商品建议迁移到 `product` / `product_id` 模式。已购买 `purchase_policy=once` 的访问者会看到“已购买”，不再显示付款按钮；`repeatable` 商品允许重复下单。PayPay 只会在 JPY 订单中展示，微信/支付宝只会在 CNY 订单中展示。

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

## 商品和交付

`pay_products` 保存当前商品价格和购买策略，`pay_product_deliverables` 保存付款后要交付的内容。当前已接入的交付 handler：

- `post_access`：写入 `pay_entitlements`，解锁整篇文章
- `content_block`：写入 `pay_entitlements`，用于后续稳定内容块权限
- `cardcode`：创建订单前预留一张卡密，支付成功后交付，订单失败/过期/关闭时释放未交付预留

订单保留旧 `status` 字段，同时新增：

- `payment_status`：支付侧状态，如 `pending`、`processing`、`paid`、`failed`、`expired`
- `fulfillment_status`：交付侧状态，如 `none`、`pending`、`fulfilled`、`partial`、`failed`

`pay_fulfillments` 按 `(order_id, deliverable_id)` 去重，重复回调不会重复交付。卡密正文使用 OpenSSL AES-256-GCM 加密保存，库存表只额外保存基于站点密钥的 HMAC 去重指纹，不把明文写入订单表、事件表或日志。

## 卡密闭环

后台菜单结构：

```text
TypechoPay
├── 支付订单        — 订单列表和补发
├── 商品管理        — 商品 CRUD、卡密导入、编辑
├── 卡密库存        — 逐条库存查看、筛选、作废/泄露
├── 卡密销售        — 已售卡密关联订单、支付和交付详情
└── 支付设置说明    — 网关配置指南和回调地址
```

操作流程：

1. **创建文章**：先用 Typecho 原生文章写商品介绍、分类、SEO、封面、评论和使用说明，记下文章 `cid`。
2. **创建商品**：TypechoPay → 商品管理 → 创建商品，填写文章 `cid`，勾选”交付卡密”，可设置 `max_per_user`。卡密商品默认要求登录购买；如确需免登录购买，可勾选“允许游客购买”。
3. **导入卡密**：选择商品 → 粘贴卡密或上传 `.txt/.csv/.tsv` 文件。支持 `卡号----卡密`、`卡号|卡密`、`卡号,卡密`、Tab 分隔或单独兑换码。导入前会预查已有指纹，报告原始行数、文件内重复、数据库重复和成功数。
4. **编辑商品**：点击商品列表中的”编辑”可修改标题、价格、状态、购买策略、登录购买规则和交付规则；这些影响购买或交付的规则变化会自动递增商品版本号。
5. **查看库存**：TypechoPay → 卡密库存 → 按商品/状态/批次筛选，支持标记作废和标记泄露。
6. **查看销售**：TypechoPay → 卡密销售 → 已交付卡密关联订单号、金额、网关、买家、交付状态、补发次数和最后错误。
7. 前台展示有两种方式：

```text
[typechopay product=”recharge-card-100”]
[typechopay_product product="recharge-card-100"]
[typechopay_product]
```

也可以在插件设置里开启“文章商品卡自动插入位置”。当文章 `cid` 绑定了上架商品，且正文没有手写 TypechoPay 短代码时，插件会自动在正文顶部、底部或第一段后插入购买模块。

8. 用户点击支付时，系统先从 `available` 库存中条件更新预留一张卡密为 `reserved`，预留 30 分钟。同一买家、同一商品、同一网关的未过期活动订单会被复用，不会重复创建支付平台订单。
9. 支付成功后，`FulfillmentManager` 把同一张预留卡密标记为 `delivered`。即使卡密交付暂时失败，支付确认和 ACK 仍会成功，不会导致支付平台重复回调。
10. 用户支付页轮询到成功后会跳转到卡密交付页。访问通过 HttpOnly delivery cookie、订单所有者校验完成。
11. 过期预留释放时会跳过已支付订单的卡密，防止已付款订单丢失预留。

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
- 当前只支持支付宝普通公钥模式，暂不支持公钥证书模式。
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
- 订单查询必须提供创建订单时生成的 `poll_token`，或匹配当前登录用户/访客 token；查询响应不会返回 `return_to`。
- 文章支付按钮点击后先走 `/action/typechopay?do=prepare` 动态准备入口，再创建订单，避免缓存页面复用一次性 nonce。
- 同一买家、同一商品版本、同一金额/币种/网关的未过期活动订单会复用已有支付入口，不会再次用同一个 `out_trade_no` 调支付平台创建接口。
- 每次通知或实际主动查单都会写入 `pay_events`，便于审计；事件表保留 provider event id/type、平台交易号、IP、请求头和 payload。
- 订单更新是状态机控制的：只有 `pending` / `processing` 可以进入 `paid_pending_grant`；交付成功后才进入 `paid`，失败会进入 `grant_failed`，后台可重发交付。
- 支付和交付状态已分离：第三方支付成功会先写 `payment_status=paid` 并向支付平台 ACK；交付成功后才写 `fulfillment_status=fulfilled`。交付失败不会抹掉“用户已付款”的事实，也不会让支付平台重复回调。
- 支付平台返回 `expired`、`cancelled`、`failed`、`closed`、`revoked`、`trade_closed` 等终态时会同步到本地订单并停止前端轮询。
- 支付平台回跳必须携带一次性 `return_token`；服务端原子消费后签发新的 HttpOnly delivery cookie，再 303 跳转到不含 token 的卡密交付地址。
- 卡密查看页必须通过 delivery cookie、兼容的 delivery token 或订单所有者校验；公开查询接口只返回 `has_card_delivery`，不会返回明文卡密。
- 卡密正文使用 Typecho 站点密钥派生出的 AES-256-GCM 密钥加密保存；导入后后台只显示库存统计，不回显明文。不要随意更换站点密钥，否则历史卡密无法解密。
- 访客权益 Cookie 以 HttpOnly、SameSite=Lax 写入；访客购买后登录会认领同一 guest token 下的订单和权益。
- 业务请求不再尝试执行 `ALTER TABLE`，schema 变更只在插件启用/升级迁移时执行。

## 验证

GitHub Actions 会执行最小 CI：安装生产依赖、PHP 语法检查和签名测试。也可在有 PHP 的环境中手动运行：

```sh
composer install --no-dev --prefer-dist
find . -path './vendor' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
for test in tests/*Test.php; do php "$test"; done
```
