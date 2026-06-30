# typecho-cloudmantou

个人 Typecho 站点的支付插件与 VOID 主题定制 monorepo。项目定位是：

- 技术文章与日常记录
- 文章型商品介绍页
- 卡密/授权码售卖
- 付费阅读与支付后交付

这个仓库不是完整商城，也不提交 Typecho 核心。运行时边界保持清晰：

```text
plugins/TypechoPay/   -> usr/plugins/TypechoPay
themes/VOID/          -> usr/themes/VOID
scripts/deploy.sh     -> 同步插件和主题到 Typecho 安装目录
```

## 适合谁

- Typecho 个人博客
- 技术教程、工具资源和小型数字商品售卖
- 软件授权卡密、兑换码、账号类一次性交付
- 需要文章介绍 + 支付购买 + 卡密查看闭环的站点

暂不把项目扩展成复杂商城；多商户、分销、购物车、多规格 SKU、实物发货等不在当前优先级内。

## 一分钟流程

1. 启用 `TypechoPay` 插件。
2. 在插件设置中配置支付宝或微信支付。
3. 新建一篇 Typecho 文章，用正文写商品介绍、截图、使用说明和注意事项。
4. 在文章底部的卡密面板开启售卖，设置价格和游客购买权限。
5. 粘贴或导入卡密。
6. 前台文章页显示购买卡片，用户支付后进入卡密交付页。
7. 后台在 `TypechoPay -> 卡密库存 / 卡密销售 / 支付订单` 里追踪库存、销售和异常交付。

## 前台体验

推荐把文章本身作为商品详情页：

```text
文章标题
文章正文：介绍、截图、教程、注意事项
购买卡片：价格、库存状态、支付按钮、已购买状态、查看卡密
评论区
相关文章
```

商品列表不重新造商城分类，优先复用 Typecho 原生分类：

```text
/category/tools    工具资源
/category/card     卡密商品
/category/typecho  Typecho 技术
/category/daily    日常记录
/shop              商店聚合页
```

商店聚合页可以通过插件短代码筛选 Typecho 分类：

```text
[typechopay_shop category_slug="card"]
```

推荐在 Typecho 后台新建独立页面：

- 页面标题：`卡密商店`
- 页面缩略名：`shop`
- 页面正文：`[typechopay_shop]`

首页只展示文章列表和少量商品预览；完整商品聚合交给 `/shop` 页面承载。主题不直接写入或查询商品数据。

前台展示保持四个清晰表面：

- 首页普通文章卡片
- 首页卡密商品预览
- `/shop` 商品网格
- 文章详情页购买面板

VOID 主题只负责展示，不直接查询支付表。需要商品角标或购买卡片时，主题调用插件 helper：

```php
<?php if (class_exists('\TypechoPlugin\TypechoPay\Plugin')): ?>
    <?php echo \TypechoPlugin\TypechoPay\Plugin::renderPostBadge($this); ?>
    <?php echo \TypechoPlugin\TypechoPay\Plugin::renderArticleProductPanel($this); ?>
<?php endif; ?>
```

## 后台边界

文章编辑页只保留高频操作：

- 是否开启售卖
- 售卖类型：卡密 / 付费阅读
- 价格
- 是否允许游客购买
- 插入购买模块
- 当前文章卡密列表和粘贴导入

复杂运营能力留在插件后台：

- 商品状态与购买策略
- 库存显示方式
- 交付规则
- 批次管理
- 卡密作废/泄露标记
- 异常订单筛选与补发

## 安装

从 Release 下载两个包：

- `TypechoPay-x.y.z.zip`
- `VOID-cloudmantou-x.y.z.zip`

上传后保持目录名：

```text
usr/plugins/TypechoPay
usr/themes/VOID
```

启用前确认 PHP 扩展：

- `json`
- `openssl`
- `curl`

插件依赖微信支付和支付宝 SDK。正式 Release 包应包含生产 `vendor/`，普通用户不需要在服务器上执行 Composer。如果从源码安装，则进入 `usr/plugins/TypechoPay` 执行：

```sh
composer install --no-dev --prefer-dist
```

升级前建议备份数据库。插件启用/升级会创建或迁移订单、事件、权益、商品、交付、卡密库存和 nonce 相关表。

## 部署

本机或服务器上可直接运行：

```sh
TYPECHO_ROOT=/www/wwwroot/example.com/typecho ./scripts/deploy.sh
```

远程部署：

```sh
TYPECHO_HOST=example.com TYPECHO_USER=ubuntu TYPECHO_ROOT=/www/wwwroot/example.com/typecho ./scripts/deploy.sh
```

可选变量：

- `TYPECHO_USER`，默认 `ubuntu`
- `SSH_OPTS`，默认空
- `RSYNC_OPTS`，默认 `-az --delete`

脚本会排除 `.git`、`vendor`、`node_modules`、日志和本地截图。发布包如果需要携带 `vendor/`，请使用打包脚本。

## 发布包

生成 Release zip：

```sh
VERSION=0.6.0 ./scripts/package-release.sh
```

输出：

```text
dist/TypechoPay-0.6.0.zip
dist/VOID-cloudmantou-0.6.0.zip
```

发布说明应写清楚：

- 支持的 Typecho 版本
- 支持的 PHP 版本
- 是否包含 `vendor/`
- 升级前是否需要备份数据库
- 是否需要停用再启用插件以触发表结构迁移

## 测试

本机需要 PHP。运行插件测试：

```sh
for test in plugins/TypechoPay/tests/*Test.php; do php "$test"; done
```

运行主题测试：

```sh
for test in themes/VOID/tests/*Test.php; do php "$test"; done
```

完整 lint：

```sh
find plugins/TypechoPay themes/VOID \
  -path '*/vendor/*' -prune -o \
  -path '*/node_modules/*' -prune -o \
  -name '*.php' -print0 | xargs -0 -n1 php -l
```

GitHub Actions 会在 PHP 7.4、8.0、8.2 上执行语法检查、插件测试、主题测试和发布包构建校验。

## 截图清单

正式发布前补齐以下截图到 `docs/screenshots/`，并在 README 中引用：

- 支付设置页
- 文章编辑页卡密面板
- 商品管理页
- 前台购买卡片
- 支付成功卡密页
- VOID 首页商品角标

## 维护原则

- 插件只负责支付、商品、订单、卡密、短代码、helper 和默认样式。
- VOID 主题只负责列表、详情、首页布局和视觉展示。
- 主题不要直接查询 `pay_orders`、`pay_products` 等支付表。
- 插件不要写入大量 VOID 专用 HTML；主题适配优先走 `themes/VOID/typechopay/` 和 helper。
- 优先补测试、发布包和异常订单处理，不优先堆新功能。
