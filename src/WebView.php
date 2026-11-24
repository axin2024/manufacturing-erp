<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class WebView
{
    private const STATES = [
        'draft' => '草稿', 'frozen' => '已冻结', 'released' => '已下达', 'active' => '执行中', 'paused' => '已挂起',
        'fulfilled' => '已交付', 'cancelled' => '已取消', 'completed' => '已完成', 'closed' => '已关闭',
        'review' => '待审核', 'approved' => '已批准', 'superseded' => '已被替代', 'blocked' => '已阻塞',
        'queued' => '待调度', 'running' => '执行中', 'failed' => '失败', 'retryable' => '可重试', 'rejected' => '拒绝',
        'partial' => '部分完成', 'succeeded' => '成功', 'pending' => '待处理', 'open' => '开放', 'resolved' => '已处置',
        'items' => '物料导入', 'bom' => '物料清单导入', 'opening' => '期初库存导入', 'mrp' => '物料计划试算',
        'purchase' => '采购', 'manufacture' => '自制', 'subcontract' => '委外', 'work' => '工单', 'shipping' => '发货',
        'receipt' => '收货', 'issued' => '已下单', 'received' => '已收货', 'waiting_quality' => '完工待检',
        'available' => '可用', 'rework' => '返工', 'stock' => '现有库存', 'ar' => '应收', 'ap' => '应付',
    ];

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function url(string $page, ?int $id = null, array $query = []): string
    {
        return '/?' . http_build_query(['page' => $page, 'id' => $id] + $query, '', '&', PHP_QUERY_RFC3986);
    }

    public static function state(string $value): string
    {
        return self::STATES[$value] ?? $value;
    }

    public static function scaled(int|string $value, int $places): string
    {
        $number = (string) $value;
        $negative = str_starts_with($number, '-');
        $digits = str_pad(ltrim($number, '-'), $places + 1, '0', STR_PAD_LEFT);
        $fraction = substr($digits, -$places);
        if ($places === 3) {
            $fraction = rtrim($fraction, '0');
        }
        return ($negative ? '-' : '') . substr($digits, 0, -$places) . ($fraction === '' ? '' : '.' . $fraction);
    }

    public static function display(string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        if (str_ends_with($field, '_milli')) {
            return self::scaled($value, 3);
        }
        if (str_ends_with($field, '_minor')) {
            return self::scaled($value, 2);
        }
        return in_array($field, ['state','kind','source_kind','change_kind','quality','direction'], true) ? self::state((string) $value) : (string) $value;
    }
}
