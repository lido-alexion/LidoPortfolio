<?php

return [
    'max_rows' => (int) env('EXPORT_MAX_ROWS', 50000),
    'max_file_bytes' => (int) env('EXPORT_MAX_FILE_BYTES', 52428800),
    'max_basket_items' => (int) env('EXPORT_MAX_BASKET_ITEMS', 10),
    'max_sheets' => (int) env('EXPORT_MAX_SHEETS', 10),
    'max_fields' => (int) env('EXPORT_MAX_FIELDS', 100),
    'max_workbook_cells' => (int) env('EXPORT_MAX_WORKBOOK_CELLS', 500000),
    'max_runtime_seconds' => (int) env('EXPORT_MAX_RUNTIME_SECONDS', 120),
    'max_memory_bytes' => (int) env('EXPORT_MAX_MEMORY_BYTES', 268435456),
    'sync_rows' => (int) env('EXPORT_SYNC_ROWS', 1000),
];
