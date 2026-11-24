<?php
use ManufacturingErp\Identity;
use ManufacturingErp\WebView;
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= WebView::e($screen['title']) ?> · 制造集团 ERP</title>
    <link rel="stylesheet" href="/assets/workspace.css">
    <script src="/assets/workspace.js" defer></script>
</head>
<body>
<?php if ($template === 'login'): ?>
    <main class="login-shell">
        <section class="login-card" aria-labelledby="login-title">
            <div class="wordmark"><span class="logo" aria-hidden="true">M</span><span>制造集团 ERP</span></div>
            <h1 id="login-title">登录工作台</h1>
            <?php if (isset($screen['error'])): ?><p class="alert error" role="alert"><?= WebView::e($screen['error']) ?></p><?php endif; ?>
            <form method="post" action="/?action=login">
                <input type="hidden" name="_csrf" value="<?= WebView::e($csrf) ?>">
                <label>邮箱<input name="email" type="email" autocomplete="username" maxlength="190" required></label>
                <label>密码<input name="password" type="password" autocomplete="current-password" maxlength="72" required></label>
                <button class="primary" type="submit">登录</button>
            </form>
        </section>
    </main>
<?php else: ?>
    <header class="topbar">
        <a class="wordmark" href="<?= WebView::e(WebView::url('home')) ?>"><span class="logo" aria-hidden="true">M</span><span>制造集团 ERP</span></a>
        <?php if ($scope !== null): ?>
            <div class="account">
                <form class="organization" method="post" action="/?action=switch-org">
                    <input type="hidden" name="_csrf" value="<?= WebView::e($csrf) ?>">
                    <label for="selected-org">账套</label>
                    <select id="selected-org" name="org_id">
                        <?php foreach ($organizations as $org): ?>
                            <option value="<?= WebView::e($org['id']) ?>" <?= (int) $org['id'] === $scope->org ? 'selected' : '' ?>><?= WebView::e($org['tenant'] . ' / ' . $org['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (count($organizations) > 1): ?><button class="quiet" type="submit">切换</button><?php endif; ?>
                </form>
                <div class="account-name">
                    <span><?= WebView::e($scope->actor) ?></span>
                    <small><?= WebView::e(implode(' / ', array_map(static fn(string $role): string => Identity::ROLES[$role], $scope->roles))) ?></small>
                </div>
                <form method="post" action="/?action=logout">
                    <input type="hidden" name="_csrf" value="<?= WebView::e($csrf) ?>">
                    <button type="submit" class="quiet">退出</button>
                </form>
            </div>
        <?php endif; ?>
    </header>
    <div class="shell">
        <?php if ($menu !== []): ?>
            <aside class="sidebar">
                <nav aria-label="业务模块">
                    <?php foreach ($menu as $slug => [$label, $icon]): ?>
                        <a href="<?= WebView::e(WebView::url($slug)) ?>" <?= $slug === ($screen['navigation'] ?? $page) ? 'aria-current="page"' : '' ?>>
                            <span aria-hidden="true"><?= WebView::e($icon) ?></span><?= WebView::e($label) ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
                <div class="sidebar-footer"><?= WebView::e($scope->code) ?> <span>·</span> <?= WebView::e($scope->currency) ?></div>
            </aside>
        <?php endif; ?>
        <main class="content" id="main">
            <?php if ($template === 'error'): ?>
                <h1>请求未完成</h1>
                <p class="alert error" role="alert"><?= WebView::e($screen['error']) ?></p>
                <a class="button" href="<?= WebView::e(WebView::url('home')) ?>">返回工作台</a>
            <?php else: ?>
                <?php require __DIR__ . '/workspace.php'; ?>
            <?php endif; ?>
        </main>
    </div>
<?php endif; ?>
</body>
</html>
