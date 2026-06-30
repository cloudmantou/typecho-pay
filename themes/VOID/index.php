<?php
/**
 * VOID：无类型
 * 
 * 作者：<a href="https://www.imalan.cn">熊猫小A</a>
 * 
 * @package     Typecho-Theme-VOID
 * @author      熊猫小A
 * @version     3.5.1
 * @link        https://blog.imalan.cn/archives/247/
 */
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
$setting = $GLOBALS['VOIDSetting']; 

if(!Utils::isPjax()){
    $this->need('includes/head.php');
    $this->need('includes/header.php');
} 
?>

<main id="pjax-container">
    <title hidden>
        <?php Contents::title($this); ?>
    </title>

    <?php $this->need('includes/ldjson.php'); ?>
    <?php $this->need('includes/banner.php'); ?>

    <div class="wrapper container <?php if($setting['indexStyle'] == 1) echo 'narrow'; else echo 'wide'; ?>">
        <?php
            $homeTitle = Helper::options()->title;
            if ($setting['indexBannerTitle'] != '') {
                $homeTitle = $setting['indexBannerTitle'];
            }
            $homeSubtitle = Helper::options()->description;
            if ($setting['indexBannerSubtitle'] != '') {
                $homeSubtitle = $setting['indexBannerSubtitle'];
            }
            $homeSubtitleText = trim((string) $homeSubtitle);
            if ($homeSubtitleText == '' || strpos($homeSubtitleText, 'Your description here') !== false) {
                $homeSubtitle = '我是馒头助手，这里记录 Typecho、PHP、小程序、服务器运维和 AI 工具等技术内容';
            }
        ?>
        <section class="cm-home-hero" aria-label="站点介绍">
            <div class="cm-home-hero__main">
                <span class="cm-home-hero__kicker">技术记录 / 工具分享 / 卡密服务</span>
                <h1>记录技术折腾，打造自己的实用工具站</h1>
                <p><?php echo htmlspecialchars($homeSubtitle); ?>。这里记录 Typecho、PHP、小程序、服务器运维、AI 工具等内容，也承载文章内购买和自动发卡。</p>
                <div class="cm-home-hero__actions">
                    <a href="#index-list">查看技术文章</a>
                    <a href="#cm-product-zone">进入卡密商店</a>
                </div>
            </div>
            <aside class="cm-home-profile" aria-label="技术栈概览">
                <span class="cm-home-profile__mark">馒</span>
                <strong>CloudMantou</strong>
                <p>个人博客、项目记录、卡密售卖与技术服务聚合站。</p>
                <div class="cm-home-profile__tags">
                    <span>Typecho</span>
                    <span>PHP</span>
                    <span>小程序</span>
                    <span>运维</span>
                    <span>AI 工具</span>
                    <span>支付系统</span>
                </div>
            </aside>
        </section>
        <section class="cm-home-focus" aria-label="博客功能">
            <div class="cm-home-focus__item">
                <span>卡密商店</span>
                <strong>文章内自动发卡</strong>
                <p>售卖软件授权、工具服务、会员卡密和付费资源，支付后自动发卡。</p>
            </div>
            <div class="cm-home-focus__item">
                <span>日常记录</span>
                <strong>项目和生活时间线</strong>
                <p>记录工作、生活、项目进展和碎片想法，形成自己的成长时间线。</p>
            </div>
            <div class="cm-home-focus__item">
                <span>技术栈</span>
                <strong>工作笔记沉淀</strong>
                <p>记录 Typecho 插件、PHP、小程序、服务器运维、AI 工具等实践经验。</p>
            </div>
        </section>
        <?php
            $homeShopPreview = '';
            $hasHomeShopPreview = false;
            if (Utils::isPluginAvailable('TypechoPay')
                && class_exists('\\TypechoPlugin\\TypechoPay\\Plugin')
                && method_exists('\\TypechoPlugin\\TypechoPay\\Plugin', 'renderPayShortcodes')) {
                try {
                    $homeShopPreview = \TypechoPlugin\TypechoPay\Plugin::renderPayShortcodes('[typechopay_shop limit="3" columns="3"]', $this);
                    $hasHomeShopPreview = trim((string) $homeShopPreview) !== ''
                        && strpos((string) $homeShopPreview, 'typechopay-shop__empty') === false;
                } catch (\Throwable $e) {
                    $homeShopPreview = '';
                }
            }
        ?>
        <section id="cm-product-zone" class="cm-home-products" aria-label="卡密商店">
            <div class="cm-home-products__head">
                <div>
                    <span>卡密商店</span>
                    <h2>热门卡密与工具服务</h2>
                </div>
                <a href="<?php Utils::index('/shop'); ?>">查看全部</a>
            </div>
            <?php if ($hasHomeShopPreview): ?>
                <div class="cm-home-products__preview">
                    <?php echo $homeShopPreview; ?>
                </div>
            <?php else: ?>
                <div class="cm-home-products__fallback">
                    <strong>卡密商品独立展示</strong>
                    <p>商店页使用 <code>[typechopay_shop]</code> 聚合卡密、授权码和付费工具，文章列表继续保留技术记录与日常内容。</p>
                    <a href="<?php Utils::index('/shop'); ?>">进入卡密商店</a>
                </div>
            <?php endif; ?>
        </section>
        <section class="cm-home-section-head" aria-label="最新文章标题">
            <div>
                <span>最新文章</span>
                <h2>技术记录、工作笔记、日常更新</h2>
            </div>
            <p>这里优先呈现可阅读内容；带价格与库存的商品文章会使用独立商品样式，不再伪装成普通文章。</p>
        </section>
        <section id="index-list" class="float-up">
            <ul id="masonry">
            <?php while($this->next()): ?>
                <?php $bannerAsCover = $this->fields->bannerascover; if($this->fields->banner == '') $bannerAsCover='0'; ?>
                <?php
                    $payBadge = '';
                    if (Utils::isPluginAvailable('TypechoPay') && class_exists('\\TypechoPlugin\\TypechoPay\\Plugin')) {
                        try {
                            $payBadge = \TypechoPlugin\TypechoPay\Plugin::renderPostBadge($this);
                        } catch (\Throwable $e) {
                            $payBadge = '';
                        }
                    }
                    $isProductPost = trim((string) $payBadge) !== '';
                ?>
                <li id="p-<?php $this->cid(); ?>" class="masonry-item cm-post <?php echo $isProductPost ? 'cm-post--product' : 'cm-post--article'; ?> style-<?php
                        if($this->fields->showfullcontent=='1') {
                            if($bannerAsCover == '2')
                                echo '1';
                            echo ' full-content';                        
                        } else {
                            echo $bannerAsCover;
                        }
                    ?>">
                
                    <?php if($this->fields->showfullcontent != '1'): ?>
                        <a href="<?php $this->permalink(); ?>">
                    <?php endif; ?>
                        <article class="yue">
                            <?php if($this->fields->banner != ''): ?>
                            <?php if($this->fields->showfullcontent == '1'): ?>
                                <a href="<?php $this->permalink(); ?>">
                            <?php endif; ?>
                                <div class="banner">
                                    <?php if (Helper::options()->lazyload == '1'): ?>
                                        <?php if($setting['browserLevelLoadingLazy']): ?>
                                            <img class="lazyload browserlevel-lazy" src="<?php echo $this->fields->banner;?>" loading="lazy">
                                        <?php else: ?>
                                            <?php if($setting['bluredLazyload']): ?>
                                                <img src="<?php echo Contents::genBluredPlaceholderSrc($this->fields->banner); ?>" class="blured-placeholder">
                                            <?php endif; ?>
                                            <img class="lazyload" data-src="<?php echo $this->fields->banner;?>">
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <img src="<?php echo $this->fields->banner;?>">
                                    <?php endif; ?>
                                </div>
                            <?php if($this->fields->showfullcontent == '1'): ?>
                                </a>
                            <?php endif; ?>
                            <?php endif; ?>
                            <div class="content-wrap">
                                <div class="post-meta-index">
                                    <time datetime="<?php echo date('c', $this->created); ?>"><?php echo date('M d, Y', $this->created); ?></time>
                                    <?php if($setting['VOIDPlugin']): ?>
                                        <span class="word-count">+ <?php echo $this->wordCount; ?> 字</span>
                                    <?php endif; ?>
                                    <?php if ($payBadge !== ''): ?>
                                        <?php echo $payBadge; ?>
                                    <?php endif; ?>
                                </div>

                                <?php if($this->fields->showfullcontent == '1'): ?>
                                    <a href="<?php $this->permalink(); ?>">
                                <?php endif; ?>
                                <h1 class="title"><?php $this->title(); ?></h1>
                                <?php if($this->fields->showfullcontent == '1'): ?>
                                    </a>
                                <?php endif; ?>
                                
                                <?php if($this->fields->excerpt != '') echo "<p class=\"headline content\">{$this->fields->excerpt}</p>"; ?>

                                <div class="articleBody">
                                <?php if($this->fields->showfullcontent != '1'): ?>
                                    <?php if($this->fields->excerpt == ''): ?>
                                        <p><?php if(Utils::isMobile()) $this->excerpt(60); else $this->excerpt(80); ?></p>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php $this->content(); ?>
                                <?php endif; ?>
                                </div>

                                <?php if($this->fields->showfullcontent != '1'): ?>
                                    <div class="cm-post-entry" aria-hidden="true">
                                        <span class="<?php echo $isProductPost ? 'cm-product-entry' : 'cm-read-entry'; ?>"><?php echo $isProductPost ? '查看商品详情' : '阅读全文'; ?></span>
                                    </div>
                                <?php endif; ?>

                            </div>
                        </article>
                    <?php if($this->fields->showfullcontent != '1'): ?>
                        </a>
                    <?php endif; ?>
                </li>
                <script>VOID_Ui.MasonryCtrler.watch("p-<?php $this->cid(); ?>");</script>
            <?php endwhile; ?>
            </ul>
        </section>
        <?php $this->pageNav('<span aria-label="上一页">←</span>', '<span aria-label="下一页">→</span>', 1, '...', 'wrapClass=pager&prevClass=prev&nextClass=next'); ?>
    </div>
</main>

<?php
if(!Utils::isPjax()){
    $this->need('includes/footer.php');
} 
?>
