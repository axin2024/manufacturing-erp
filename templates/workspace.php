<?php
use ManufacturingErp\WebView;
?>
<div class="page-head">
    <?php if (isset($screen['parent'])): ?>
        <a class="breadcrumb" href="<?= WebView::e(WebView::url($screen['parent'][0])) ?>"><?= WebView::e($screen['parent'][1]) ?></a>
    <?php endif; ?>
    <h1><?= WebView::e($screen['title']) ?></h1>
</div>
<?php if (isset($screen['notification'])): ?>
    <p class="alert" role="status"><?= WebView::e($screen['notification']['message']) ?></p>
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
                <form method="post" action="/?action=<?= WebView::e($form['action']) ?>">
                    <input type="hidden" name="_csrf" value="<?= WebView::e($csrf) ?>">
                    <?php foreach ($form['hidden'] as $key => $value): ?>
                        <input type="hidden" name="<?= WebView::e($key) ?>" value="<?= WebView::e($value) ?>">
                    <?php endforeach; ?>
                    <?php if ($form['hint'] !== ''): ?><p class="note"><?= WebView::e($form['hint']) ?></p><?php endif; ?>
                    <?php foreach ($form['fields'] as $field): ?>
                        <label>
                            <?= WebView::e($field['label']) ?>
                            <?php if ($field['type'] === 'select'): ?>
                                <select name="<?= WebView::e($field['name']) ?>" required>
                                    <?php foreach ($field['options'] as $key => $label): ?>
                                        <option value="<?= WebView::e($key) ?>"><?= WebView::e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php elseif ($field['type'] === 'textarea'): ?>
                                <textarea name="<?= WebView::e($field['name']) ?>" rows="6" required placeholder="<?= WebView::e($field['placeholder'] ?? '') ?>"><?= WebView::e($field['value'] ?? '') ?></textarea>
                            <?php else: ?>
                                <input name="<?= WebView::e($field['name']) ?>" type="<?= WebView::e($field['type']) ?>"
                                       value="<?= WebView::e($field['value'] ?? '') ?>" required>
                            <?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                    <button class="primary" type="submit">确认<?= WebView::e($form['title']) ?></button>
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
