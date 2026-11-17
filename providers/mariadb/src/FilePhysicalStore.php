<?php
declare(strict_types=1);
namespace Alxarafe\Arp\Cds\Physical;

/** Optional owner-controlled local filesystem cache, not a distributed coherency mechanism. */
final class FilePhysicalStore
{
    private string $root;
    private array $sources;
    private array $cacheRoots;
    public function __construct(string $directory, array $versionedSourceRoots, array $authorizedCacheRoots = [])
    {
        $this->root = self::directory($directory);
        if (rtrim($directory, '/') !== $this->root) throw new ObservationException();
        $this->cacheRoots = array_map(static function(string $path): string {
            $real = self::directory($path);
            if (rtrim($path, '/') !== $real) throw new ObservationException();
            return $real;
        }, $authorizedCacheRoots);
        $this->sources = array_map(self::directory(...), $versionedSourceRoots);
        if (!$this->sources) throw new \InvalidArgumentException('Declare the host versioned source roots');
        $this->checkRoot();
    }
    public function fileFor(PhysicalStoreKey $key): string { return $this->root.'/'.$key->hash.'.physical.yaml'; }

    public function get(PhysicalStoreKey $key, callable $observe): object
    {
        $file = $this->lock($key);
        try {
            $state = $this->state($file, $key);
            $path = $this->fileFor($key);
            if ($state->valid && is_file($path)) {
                try {
                    self::checkFile($path);
                    if (filesize($path) > 1048576 || ($yaml = @file_get_contents($path)) === false
                        || hash('sha256', $yaml) !== $state->digest) throw new ObservationException();
                    return PhysicalYaml::decode($yaml, $key);
                } catch (ObservationException $e) { self::save($file, self::invalid($key)); throw $e; }
            }
            if ($state->valid) { $state = self::invalid($key); self::save($file, $state); }
            $generation = $state->generation;
        } finally { self::unlock($file); }
        // Native observation occurs without the storage lock, so an explicit invalidation can win this race.
        $yaml = PhysicalYaml::encode($observe(), $key);
        $file = $this->lock($key);
        try {
            if ($this->state($file, $key)->generation !== $generation) throw new ObservationException();
            self::atomicWrite($this->fileFor($key), $yaml);
            self::save($file, (object)['generation'=>$generation,'key'=>$key->hash,
                'digest'=>hash('sha256', $yaml),'valid'=>true]);
        } finally { self::unlock($file); }
        return PhysicalYaml::decode($yaml, $key);
    }

    public function invalidate(PhysicalStoreKey $key): void
    {
        $file = $this->lock($key);
        try {
            self::save($file, self::invalid($key)); // Durable invalid marker precedes removal of the YAML.
            $path = $this->fileFor($key); self::checkFile($path);
            if (is_file($path) && !@unlink($path)) throw new ObservationException();
        } finally { self::unlock($file); }
    }

    private function lock(PhysicalStoreKey $key): mixed
    {
        $this->checkRoot();
        $path = $this->root.'/'.$key->hash.'.lock'; self::checkFile($path);
        $file = @fopen($path, 'c+b');
        if ($file === false) throw new ObservationException();
        $until = microtime(true) + 5;
        do {
            if (flock($file, LOCK_EX | LOCK_NB)) return $file;
            usleep(10000);
        } while (microtime(true) < $until);
        fclose($file); throw new ObservationException();
    }
    private static function unlock(mixed $file): void { flock($file, LOCK_UN); fclose($file); }
    private function state(mixed $file, PhysicalStoreKey $key): object
    {
        rewind($file); $bytes = stream_get_contents($file, 4097);
        if ($bytes === '') { $state = self::invalid($key); self::save($file, $state); return $state; }
        try {
            if ($bytes === false || strlen($bytes) > 4096) throw new \JsonException();
            $state = json_decode($bytes, false, 16, JSON_THROW_ON_ERROR);
            if (!$state instanceof \stdClass || ($state->key ?? null) !== $key->hash
                || !is_string($state->generation ?? null)
                || !preg_match('/\A[0-9a-f]{32}\z/D', $state->generation) || !is_bool($state->valid ?? null)
                || ($state->valid && (!is_string($state->digest ?? null)
                    || !preg_match('/\A[0-9a-f]{64}\z/D', $state->digest)))) throw new \JsonException();
            return $state;
        } catch (\JsonException) { self::save($file, self::invalid($key)); throw new ObservationException(); }
    }
    private static function invalid(PhysicalStoreKey $key): object
    {
        return (object)['generation'=>bin2hex(random_bytes(16)),'key'=>$key->hash,'digest'=>null,'valid'=>false];
    }
    private static function save(mixed $file, object $state): void
    {
        $bytes = json_encode($state, JSON_THROW_ON_ERROR);
        if (!@rewind($file) || !@ftruncate($file, 0) || @fwrite($file, $bytes) !== strlen($bytes)
            || !@fflush($file) || !@fsync($file)) throw new ObservationException();
    }
    private static function atomicWrite(string $path, string $yaml): void
    {
        self::checkFile($path); $temporary = $path.'.'.bin2hex(random_bytes(16)).'.tmp';
        $file = @fopen($temporary, 'x+b');
        if ($file === false) throw new ObservationException();
        try {
            if (!@chmod($temporary, 0600) || @fwrite($file, $yaml) !== strlen($yaml)
                || !@fflush($file) || !@fsync($file)) throw new ObservationException();
            fclose($file); $file = null;
            if (!@rename($temporary, $path)) throw new ObservationException();
        } finally {
            if (is_resource($file)) fclose($file);
            if (is_file($temporary)) @unlink($temporary);
        }
    }
    private static function checkFile(string $path): void
    {
        clearstatcache(true, $path);
        if (is_link($path) || is_dir($path)) throw new ObservationException();
    }
    private static function directory(string $path): string
    {
        $real = realpath($path);
        if (!str_starts_with($path, '/') || $real === false || !is_dir($real)) {
            throw new \InvalidArgumentException('An existing absolute storage/source directory is required');
        }
        return $real;
    }
    private static function within(string $path, string $parent): bool
    {
        return $path === $parent || str_starts_with($path, $parent.'/');
    }
    private static function git(string $repository, array $arguments): int
    {
        $pipes = [];
        $process = @proc_open(['git', '-C', $repository, ...$arguments],
            [['file','/dev/null','r'],['file','/dev/null','w'],['file','/dev/null','w']], $pipes);
        if (!is_resource($process)) throw new ObservationException();
        return proc_close($process);
    }
    private function checkRoot(): void
    {
        clearstatcache(true);
        if (realpath($this->root) !== $this->root) throw new ObservationException();
        $repository = null;
        for ($dir = $this->root; ; $dir = dirname($dir)) {
            if (is_file($dir.'/HEAD') && is_dir($dir.'/objects')) throw new ObservationException();
            if (file_exists($dir.'/.git')) { $repository = $dir; break; }
            if (dirname($dir) === $dir) break;
        }
        $overlap = false;
        foreach ($this->sources as $source) {
            if ($this->root === $source) throw new ObservationException();
            $overlap = $overlap || self::within($this->root, $source);
        }
        $authorized = $this->cacheRoots;
        if (!$authorized && $repository !== null) $authorized = [$repository.'/cache/arp/physical'];
        if ($overlap || $repository !== null || $this->cacheRoots) {
            $allowed = false;
            foreach ($authorized as $cache) $allowed = $allowed || self::within($this->root, $cache);
            if (!$allowed) throw new ObservationException();
        }
        if ($repository !== null && (self::git($repository,
            ['check-ignore','--quiet','--no-index','--',$this->root.'/']) !== 0
            || self::git($repository,
                ['--literal-pathspecs','ls-files','--error-unmatch','--',$this->root.'/']) !== 1)) {
            throw new ObservationException();
        }
    }
}