<?php
use ManufacturingErp\WebView;
$required = $field['required'] ?? true;
?>
<label>
    <?= WebView::e($field['label']) ?>
    <?php if ($field['type'] === 'select'): ?>
        <select name="<?= WebView::e($fieldName) ?>" <?= $required ? 'required' : '' ?>>
            <option value="">请选择</option>
            <?php foreach ($field['options'] as $key => $label): ?>
                <option value="<?= WebView::e($key) ?>"
                    <?= (string) ($field['value'] ?? '') === (string) $key ? 'selected' : '' ?>><?= WebView::e($label) ?></option>
            <?php endforeach; ?>
        </select>
    <?php elseif ($field['type'] === 'textarea'): ?>
        <textarea name="<?= WebView::e($fieldName) ?>" rows="6" <?= $required ? 'required' : '' ?>
            placeholder="<?= WebView::e($field['placeholder'] ?? '') ?>"><?= WebView::e($field['value'] ?? '') ?></textarea>
    <?php else: ?>
        <input name="<?= WebView::e($fieldName) ?>" type="<?= WebView::e($field['type']) ?>"
            value="<?= WebView::e($field['value'] ?? '') ?>" <?= $required ? 'required' : '' ?>
            <?php foreach (['min','max','step','maxlength','autocomplete','placeholder'] as $attribute): ?>
                <?php if (isset($field[$attribute])): ?><?= $attribute ?>="<?= WebView::e($field[$attribute]) ?>"<?php endif; ?>
            <?php endforeach; ?>>
    <?php endif; ?>
</label>
