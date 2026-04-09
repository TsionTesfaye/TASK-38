<?php
declare(strict_types=1);
namespace App\Storage;

class LocalStorageService
{
    private string $basePath;

    public function __construct()
    {
        $this->basePath = $_ENV['STORAGE_PATH'] ?? '/var/www/storage';
    }

    public function storePdf(string $content, string $filename): string
    {
        return $this->store('pdfs', $filename, $content);
    }

    public function storeExport(string $content, string $filename): string
    {
        return $this->store('exports', $filename, $content);
    }

    public function storeBackup(string $content, string $filename): string
    {
        return $this->store('backups', $filename, $content);
    }

    public function storeTerminalAsset(string $content, string $filename): string
    {
        return $this->store('terminal_assets', $filename, $content);
    }

    public function getFile(string $path): string
    {
        $fullPath = $this->basePath . '/' . ltrim($path, '/');
        if (!file_exists($fullPath)) {
            throw new \RuntimeException("File not found: {$path}");
        }
        return file_get_contents($fullPath);
    }

    public function deleteFile(string $path): void
    {
        $fullPath = $this->basePath . '/' . ltrim($path, '/');
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
    }

    public function fileExists(string $path): bool
    {
        return file_exists($this->basePath . '/' . ltrim($path, '/'));
    }

    public function listDirectory(string $subdir): array
    {
        $dir = $this->basePath . '/' . ltrim($subdir, '/');
        if (!is_dir($dir)) {
            return [];
        }
        return array_values(array_diff(scandir($dir), ['.', '..']));
    }

    private function store(string $subdir, string $filename, string $content): string
    {
        $dir = $this->basePath . '/' . $subdir;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $path = $subdir . '/' . $filename;
        file_put_contents($this->basePath . '/' . $path, $content);
        return $path;
    }
}
