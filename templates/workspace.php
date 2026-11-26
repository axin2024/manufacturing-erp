<?php
use ManufacturingErp\WebView;
?>
<div class="page-head">
    <?php if (isset($screen['parent'])): ?>
        <a class="breadcrumb" href="<?= WebView::e(WebView::url($screen['parent'][0])) ?>"><?= WebView::e($screen['parent'][1]) ?></a>
    <?php endif; ?>
    <h1><?= WebView::e($screen['title']) ?></h1>
</div>
<?php if (($screen['links'] ?? []) !== []): ?>
    <div class="toolbar" aria-label="相关页面">
        <?php foreach ($screen['links'] as $link): ?>
            <a class="button" href="<?= WebView::e($link['url']) ?>"><?= WebView::e($link['label']) ?></a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php if (isset($screen['notification'])): ?>
    <p class="alert" role="status"><?= WebView::e($screen['notification']['message']) ?></p>
    <?php if (($screen['notification']['details'] ?? []) !== []): ?>
        <ul aria-label="选中行的处理结果">
            <?php foreach ($screen['notification']['details'] as $detail): ?>
                <li><?= WebView::e($detail) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
<?php endif; ?>
<?php if (($screen['filters'] ?? []) !== []): ?>
    <form class="filters" method="get" action="/">
        <input type="hidden" name="page" value="<?= WebView::e($page) ?>">
        <?php foreach (($screen['filter_parameters'] ?? []) as $key => $value): ?>
            <input type="hidden" name="<?= WebView::e($key) ?>" value="<?= WebView::e($value) ?>">
        <?php endforeach; ?>
        <?php foreach ($screen['filters'] as $field): ?>
            <?php $fieldName = $field['name']; ?>
            <?php require __DIR__ . '/field.php'; ?>
        <?php endforeach; ?>
        <button class="button" type="submit">查询</button>
    </form>
<?php endif; ?>
<?php if (isset($screen['demo'])): ?>
    <section class="demo" aria-labelledby="demo-title">
        <div class="section-title"><h2 id="demo-title">合成数据回放</h2><span>计划基准 <?= WebView::e($screen['demo']['date']) ?></span></div>
        <div class="metric-grid">
            <?php foreach ($screen['demo']['metrics'] as $metric): ?>
                <div>
                    <span><?= WebView::e($metric['label']) ?></span>
                    <?php if (isset($metric['url'])): ?>
                        <a href="<?= WebView::e($metric['url']) ?>"><?= WebView::e($metric['value']) ?> <span aria-hidden="true">↗</span></a>
                    <?php else: ?>
                        <strong><?= WebView::e($metric['value']) ?></strong>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>
<?php if (isset($screen['cards'])): ?>
    <h2>待办事项</h2>
    <?php if ($screen['cards'] === []): ?><p class="empty">当前角色没有待办入口。</p><?php endif; ?>
    <div class="summary-grid">
        <?php foreach ($screen['cards'] as $card): ?>
            <a class="summary-card" href="<?= WebView::e($card['url']) ?>">
                <span><?= WebView::e($card['label']) ?></span>
                <strong><?= WebView::e($card['value']) ?><span aria-hidden="true">↗</span></strong>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php if (isset($screen['details'])): ?>
    <dl class="record">
        <?php foreach ($screen['details'] as $name => $value): ?>
            <div><dt><?= WebView::e($name) ?></dt><dd><?= WebView::e($value ?? '—') ?></dd></div>
        <?php endforeach; ?>
    </dl>
<?php endif; ?>
<?php if (($screen['forms'] ?? []) !== []): ?>
    <section class="form-tray" aria-label="可用操作">
        <?php foreach ($screen['forms'] as $form): ?>
            <details class="operation">
                <summary><?= WebView::e($form['title']) ?></summary>
                <form method="post" action="/?action=<?= WebView::e($form['action']) ?>" <?= !empty($form['upload']) ? 'enctype="multipart/form-data"' : '' ?>>
                    <input type="hidden" name="_workbench" value="1">
                    <input type="hidden" name="_csrf" value="<?= WebView::e($csrf) ?>">
                    <?php foreach ($form['hidden'] as $key => $value): ?>
                        <input type="hidden" name="<?= WebView::e($key) ?>" value="<?= WebView::e($value) ?>">
                    <?php endforeach; ?>
                    <?php if ($form['hint'] !== ''): ?><p class="note"><?= WebView::e($form['hint']) ?></p><?php endif; ?>
                    <?php foreach ($form['fields'] as $field): ?>
                        <?php if ($field['type'] === 'rows'): ?>
                            <?php $group = $field; ?>
                            <div class="line-group" data-line-group>
                                <fieldset class="document-line" data-line>
                                    <legend><?= WebView::e($group['label']) ?></legend>
                                    <?php foreach ($group['fields'] as $field): ?>
                                        <?php $fieldName = $group['name'] . '[0][' . $field['name'] . ']'; ?>
                                        <?php require __DIR__ . '/field.php'; ?>
                                    <?php endforeach; ?>
                                    <button class="quiet" type="button" data-remove-line hidden>删除此行</button>
                                </fieldset>
                                <button class="quiet" type="button" data-add-line hidden>添加一行</button>
                            </div>
                        <?php else: ?>
                            <?php $fieldName = $field['name']; ?>
                            <?php require __DIR__ . '/field.php'; ?>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <input type="hidden" name="_complete" value="1">
                    <button class="primary" type="submit">提交</button>
                </form>
            </details>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
<?php foreach ($screen['tables'] as $table): ?>
    <section class="panel">
        <div class="section-title"><h2><?= WebView::e($table['title']) ?></h2></div>
        <?php if ($table['rows'] === []): ?>
            <p class="empty">暂无记录</p>
        <?php else: ?>
            <div class="table-scroll" tabindex="0" role="region" aria-label="<?= WebView::e($table['title']) ?>">
                <table>
                    <thead><tr><?php foreach ($table['columns'] as $name): ?><th scope="col"><?= WebView::e($name) ?></th><?php endforeach; ?></tr></thead>
                    <tbody>
                    <?php foreach ($table['rows'] as $row): ?>
                        <tr>
                            <?php foreach ($table['columns'] as $key => $name): ?>
                                <?php $value = WebView::display($key, $row[$key] ?? null); ?>
                                <td>
                                    <?php if (isset($row['_links'][$key])): ?>
                                        <a href="<?= WebView::e($row['_links'][$key]) ?>"><?= WebView::e($value) ?></a>
                                    <?php elseif ($key === 'state'): ?>
                                        <span class="status" data-state="<?= WebView::e($row[$key] ?? '') ?>"><?= WebView::e($value) ?></span>
                                    <?php elseif (in_array($key, ['payload','result','previous_values','current_values','payload_digest'], true)): ?>
                                        <code><?= WebView::e($value) ?></code>
                                    <?php else: ?>
                                        <?= WebView::e($value) ?>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <?php if ($table['next'] !== null): ?><a class="page-link" href="<?= WebView::e($table['next']) ?>">下一页 →</a><?php endif; ?>
    </section>
<?php endforeach; ?>
