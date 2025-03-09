<?php
namespace Careminate\Sessions;

class SessionHandler implements \SessionHandlerInterface

{
    public function __construct(public string $save_path, public string $prefix)
    {
        if (!is_dir($this->save_path)) {
            mkdir($this->save_path, 0755, true);
        }
    }

    private function getSessionFilePath(string $id): string
    {
        return $this->save_path . DIRECTORY_SEPARATOR . $this->prefix . '_' . $id;
    }

    public function close(): bool
    {
        return true;
    }

    public function destroy(string $id): bool
    {
        $file = $this->getSessionFilePath($id);
        return file_exists($file) ? unlink($file) : true;
    }

    public function gc(int $max_lifetime): int | false
    {
        $deletedCount = 0;

        foreach (glob($this->save_path . '/' . $this->prefix . '_*') as $file) {
            if (filemtime($file) + $max_lifetime < time() && file_exists($file)) {
                if (unlink($file)) {
                    $deletedCount++;
                }
            }
        }
        return $deletedCount;
    }

    public function open(string $path, string $name): bool
    {
        return is_dir($this->save_path) || mkdir($this->save_path, 0755, true);
    }

    public function read(string $id): string | false
    {
        $file = $this->getSessionFilePath($id);
        return file_exists($file) ? file_get_contents($file) : '';
    }

    public function write(string $id, string $data): bool
    {
        return file_put_contents($this->getSessionFilePath($id), $data) !== false;
    }
}
