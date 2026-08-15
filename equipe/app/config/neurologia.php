<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Retenção de backups (seção 94 do PRD)
    |--------------------------------------------------------------------------
    */
    'backup_retention' => [
        'daily' => env('NEUROLOGIA_BACKUP_RETENTION_DAILY', 7),
        'weekly' => env('NEUROLOGIA_BACKUP_RETENTION_WEEKLY', 4),
        'monthly' => env('NEUROLOGIA_BACKUP_RETENTION_MONTHLY', 12),
    ],

    'backups_path' => env('NEUROLOGIA_BACKUPS_PATH', base_path('../backups')),

    'exports_path' => env('NEUROLOGIA_EXPORTS_PATH', base_path('../exports')),

    /*
    |--------------------------------------------------------------------------
    | Senha padrão para novos usuários (seção 49 do PRD)
    |--------------------------------------------------------------------------
    */
    'default_password' => 'senha@1234',
];
