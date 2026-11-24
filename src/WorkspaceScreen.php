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
