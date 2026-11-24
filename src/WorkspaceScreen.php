<?php

declare(strict_types=1);

namespace ManufacturingErp;

abstract class WorkspaceScreen extends Service
{
    protected function paged(array $rows, string $field, string $page, ?int $id = null, string $cursor = 'before', array $other = []): array
    {
        $next = null;
        if (count($rows) > 50) {
            array_pop($rows);
            $next = WebView::url($page, $id, [$cursor => $rows[49][$field], ...$other]);
        }
        return ['rows' => $rows, 'next' => $next];
    }

    protected function table(string $title, array $columns, array $rows, ?string $route = null, ?string $link = null, ?string $next = null): array
    {
        if ($route !== null && $link !== null) {
            foreach ($rows as &$row) {
                $row['_links'][$link] = WebView::url($route, (int) $row['id']);
            }
            unset($row);
        }
        return ['title' => $title, 'columns' => $columns, 'rows' => $rows, 'next' => $next];
    }

    protected function listTable(string $title, array $fields, array $rows, string $page, string $link = 'id', array $parameters = []): array
    {
        $list = $this->paged($rows, 'id', $page, null, 'before', $parameters);
        return $this->table($title, $fields, $list['rows'], $page, $link, $list['next']);
    }

    protected function form(string $title, string $action, array $fields, array $hidden = [], string $hint = ''): array
    {
        return compact('title', 'action', 'fields', 'hidden', 'hint');
    }

    protected function requestForm(string $title, string $action, array $fields, array $hidden = [], string $hint = ''): array
    {
        return $this->form($title, $action, $fields, ['request_key' => bin2hex(random_bytes(16))] + $hidden, $hint);
    }

    protected function field(string $name, string $label, string $type = 'text', array $settings = []): array
    {
        return compact('name', 'label', 'type') + $settings;
    }

    protected function quantityField(string $name, string $label, bool $allowZero = false): array
    {
        return $this->field($name, $label, 'number', $allowZero ? ['min' => '0', 'step' => '0.001', 'value' => '0']
            : ['min' => '0.001', 'step' => '0.001']);
    }

    protected function dateField(string $name, string $label): array
    {
        return $this->field($name, $label, 'date', ['value' => gmdate('Y-m-d')]);
    }

    protected function options(array $rows, string $label, ?string $description = null): array
    {
        $options = [];
        foreach ($rows as $row) {
            $options[$row['id']] = $row[$label] . ($description === null ? '' : ' · ' . $row[$description]);
        }
        return $options;
    }

    protected function locations(): array
    {
        return $this->options($this->db->rows('SELECT id,code,name FROM locations WHERE org_id=? ORDER BY code', [$this->scope->org]), 'code', 'name');
    }

    protected function parties(string $kind): array
    {
        return $this->options($this->db->rows("SELECT id,code,name FROM parties WHERE org_id=? AND kind IN (?, 'both') ORDER BY code",
            [$this->scope->org, $kind]), 'code', 'name');
    }

    protected function items(): array
    {
        return $this->options($this->db->rows('SELECT i.id,i.code,i.name FROM org_items x JOIN items i ON i.id=x.item_id
            WHERE x.org_id=? ORDER BY i.code', [$this->scope->org]), 'code', 'name');
    }

    protected function link(string $label, string $page, ?int $id = null, array $parameters = []): array
    {
        return ['label' => $label, 'url' => WebView::url($page, $id, $parameters)];
    }

    protected function scopeLinks(array $rows, string $field, string $page, array $parameters = []): array
    {
        foreach ($rows as &$row) {
            if (isset($row[$field])) {
                $row['_links'][$field] = WebView::url($page, (int) $row[$field], $parameters);
            }
        }
        unset($row);
        return $rows;
    }

    protected function export(string $name, string $kind, ?int $id = null, ?string $filter = null): array
    {
        return $this->link($name, 'export', $id, ['kind' => $kind, 'filter' => $filter]);
    }
}
