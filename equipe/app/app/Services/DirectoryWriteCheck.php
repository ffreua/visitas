<?php

namespace App\Services;

class DirectoryWriteCheck
{
    /**
     * is_writable() é conhecidamente pouco confiável para diretórios no
     * Windows; fazemos um teste real de escrita+remoção, que funciona
     * igual em Windows (dev) e Linux/cPanel (produção).
     */
    public static function isWritable(string $directory): bool
    {
        if (! is_dir($directory)) {
            return false;
        }

        $probe = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.'.write_test_'.uniqid();

        $written = @file_put_contents($probe, 'ok') !== false;

        if ($written) {
            @unlink($probe);
        }

        return $written;
    }
}
