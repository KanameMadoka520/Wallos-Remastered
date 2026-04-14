<?php

function wallos_get_backup_progress_labels($lang)
{
    $isChinese = in_array($lang, ['zh_cn', 'zh_tw'], true);

    if ($isChinese) {
        return [
            'panel_title' => '当前手动备份进度',
            'idle_message' => '等待开始新的手动备份',
            'starting_message' => '正在初始化备份任务…',
        ];
    }

    return [
        'panel_title' => 'Current Manual Backup Progress',
        'idle_message' => 'Waiting for a new manual backup to start',
        'starting_message' => 'Initializing backup task…',
    ];
}

function wallos_get_backup_progress_message($lang, $stage, array $context = [])
{
    $isChinese = in_array($lang, ['zh_cn', 'zh_tw'], true);
    $current = (int) ($context['current'] ?? 0);
    $total = (int) ($context['total'] ?? 0);

    switch ((string) $stage) {
        case 'preparing':
            return $isChinese ? '正在准备备份工作区…' : 'Preparing backup workspace…';
        case 'snapshot':
            return $isChinese ? '正在创建数据库快照…' : 'Creating database snapshot…';
        case 'copy_logos':
            if ($total > 0) {
                return $isChinese
                    ? sprintf('正在复制 logos 文件（%d / %d）…', $current, $total)
                    : sprintf('Copying logos files (%d / %d)…', $current, $total);
            }
            return $isChinese ? '正在复制 logos 文件…' : 'Copying logos files…';
        case 'manifest':
            return $isChinese ? '正在生成备份清单…' : 'Building backup manifest…';
        case 'zip_archive':
            if ($total > 0) {
                return $isChinese
                    ? sprintf('正在写入压缩包（%d / %d）…', $current, $total)
                    : sprintf('Writing archive (%d / %d)…', $current, $total);
            }
            return $isChinese ? '正在写入压缩包…' : 'Writing backup archive…';
        case 'finalizing':
            return $isChinese ? '正在完成备份并校验结果…' : 'Finalizing backup and checking result…';
        case 'completed':
            return $isChinese ? '备份已完成，正在刷新列表…' : 'Backup completed, refreshing list…';
        case 'failed':
            return $isChinese ? '备份失败' : 'Backup failed';
        case 'waiting':
        default:
            return wallos_get_backup_progress_labels($lang)['idle_message'];
    }
}
