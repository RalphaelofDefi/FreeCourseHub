<?php
declare(strict_types=1);

class JobLock
{
    private string $path;
    private $handle = null;

    public function __construct(string $name)
    {
        $safeName = preg_replace('/[^a-z0-9_\-]/i', '_', $name) ?? 'job';
        $directory = storage_path('locks');
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
        $this->path = $directory . '/' . $safeName . '.lock';
    }

    public function acquire(): bool
    {
        $handle = @fopen($this->path, 'c+');
        if ($handle === false) {
            return false;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }
        ftruncate($handle, 0);
        fwrite($handle, json_encode(['pid' => getmypid(), 'started_at' => date('c')]));
        fflush($handle);
        $this->handle = $handle;
        return true;
    }

    public function release(): void
    {
        if ($this->handle !== null) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
