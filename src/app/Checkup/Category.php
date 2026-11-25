<?php

namespace App\Checkup;

/** Checkup categories (= the tabs of the Checkup page), in display order. */
final class Category
{
    /** @return array<string, array{0: string, 1: string}> key → [label, icon path] */
    public static function all(): array
    {
        return [
            'security' => [__('Security'), 'M12 3 4.5 6v5.5c0 4.6 3.2 8.3 7.5 9.5 4.3-1.2 7.5-4.9 7.5-9.5V6zM9.5 12l1.8 1.8 3.4-3.6'],
            'hosts' => [__('Hosts'), 'M3 4h18v7H3zM3 13h18v7H3zM7 7.5h.01M7 16.5h.01'],
            'clusters' => [__('Clusters & HA'), 'm12 3 9 4.5-9 4.5-9-4.5zM3 12l9 4.5 9-4.5M3 16.5 12 21l9-4.5'],
            'vms' => [__('Virtual machines'), 'M3 5h18v11H3zM8 20h8M12 16v4'],
            'snapshots' => [__('Snapshots'), 'M4 8h3l2-3h6l2 3h3v11H4zM12 16.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7'],
            'storage' => [__('Storage'), 'M12 3c-4.97 0-9 1.34-9 3s4.03 3 9 3 9-1.34 9-3-4.03-3-9-3zM3 6v6c0 1.66 4.03 3 9 3s9-1.34 9-3V6M3 12v6c0 1.66 4.03 3 9 3s9-1.34 9-3v-6'],
            'network' => [__('Network'), 'M9 2.5h6v5H9zM2.5 16.5h6v5h-6zM15.5 16.5h6v5h-6zM12 7.5V12M5.5 16.5V12h13v4.5'],
            'licensing' => [__('Licensing'), 'M15 7a2 2 0 1 1 0 4 2 2 0 0 1 0-4zM3 21l7.5-7.5M13.4 11.6A5 5 0 1 0 12.4 10.6M7 17l2 2M5 19l2 2'],
            'alarms' => [__('Alarms'), 'M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.94 1.94 0 0 0 3.4 0'],
            'vcenter' => [__('vCenter'), 'M3 21h18M5 21V7l7-4 7 4v14M9 21v-4h6v4'],
        ];
    }
}
