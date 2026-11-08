<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** Stores generated report data so reports can be downloaded again later. */
class Reports_model extends Model
{
    public function history(int $userId): array
    {
        $rows = eco_db_all(
            'SELECT id, title, period, format, categories, date_from, date_to, filters, tables_json, created_at
             FROM report_history WHERE user_id = ? ORDER BY created_at DESC, id DESC',
            [$userId]
        );

        return array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'title' => $row['title'],
                'period' => $row['period'],
                'format' => $row['format'],
                'categories' => json_decode($row['categories'], true) ?: [],
                'from' => $row['date_from'],
                'to' => $row['date_to'],
                'filters' => json_decode($row['filters'], true) ?: [],
                'tables' => json_decode($row['tables_json'], true) ?: [],
                'created_at' => $row['created_at'],
            ];
        }, $rows);
    }

    public function create(int $userId, array $report): int
    {
        return eco_db_insert(
            'INSERT INTO report_history
                (user_id, title, period, format, categories, date_from, date_to, filters, tables_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $userId,
                $report['title'],
                $report['period'],
                $report['format'],
                json_encode($report['categories'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                $report['from'],
                $report['to'],
                json_encode($report['filters'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                json_encode($report['tables'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            ]
        );
    }
}
