<?php

declare(strict_types=1);

/**
 * Simple file-based cache for GitHub contribution stats
 * Caches stats for 24 hours to avoid repeated API calls
 */

// Silenciamos errores para que no rompan el renderizado del SVG en GitHub
error_reporting(0);
ini_set('display_errors', '0');

// Default cache duration: 24 hours (in seconds)
define("CACHE_DURATION", 24 * 60 * 60);

// CAMBIO CLAVE: Usamos /tmp que es la única carpeta con permisos de escritura en Vercel
define("CACHE_DIR", "/tmp/github-streak-cache");

/**
 * Generate a cache key for a user's request
 */
function getCacheKey(string $user, array $options = []): string
{
    ksort($options);
    try {
        $keyData = json_encode(["user" => $user, "options" => $options], JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        $keyData = $user . serialize($options);
    }
    return hash("sha256", $keyData);
}

/**
 * Get the cache file path for a given key
 */
function getCacheFilePath(string $key): string
{
    return CACHE_DIR . "/" . $key . ".json";
}

/**
 * Ensure the cache directory exists
 */
function ensureCacheDir(): bool
{
    if (!is_dir(CACHE_DIR)) {
        // El símbolo @ suprime el Warning si el sistema de archivos se pone terco
        return @mkdir(CACHE_DIR, 0777, true);
    }
    return true;
}

/**
 * Get cached stats if available and not expired
 */
function getCachedStats(string $user, array $options = [], int $maxAge = CACHE_DURATION): ?array
{
    $key = getCacheKey($user, $options);
    $filePath = getCacheFilePath($key);

    if (!file_exists($filePath)) {
        return null;
    }

    $mtime = filemtime($filePath);
    if ($mtime === false) {
        return null;
    }

    $fileAge = time() - $mtime;
    if ($fileAge > $maxAge) {
        @unlink($filePath);
        return null;
    }

    $handle = @fopen($filePath, "r");
    if ($handle === false) {
        return null;
    }

    if (!flock($handle, LOCK_SH)) {
        fclose($handle);
        return null;
    }

    $contents = stream_get_contents($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    if ($contents === false || $contents === "") {
        return null;
    }

    $data = json_decode($contents, true);
    if (!is_array($data)) {
        return null;
    }

    return $data;
}

/**
 * Save stats to cache
 */
function setCachedStats(string $user, array $options, array $stats): bool
{
    if (!ensureCacheDir()) {
        return false;
    }

    $key = getCacheKey($user, $options);
    $filePath = getCacheFilePath($key);

    $data = json_encode($stats);
    if ($data === false) {
        return false;
    }

    // Usamos @ para evitar que cualquier error de permisos ensucie la salida
    $result = @file_put_contents($filePath, $data, LOCK_EX);
    
    return $result !== false;
}

/**
 * Clear all expired cache files
 */
function clearExpiredCache(int $maxAge = CACHE_DURATION): int
{
    if (!is_dir(CACHE_DIR)) {
        return 0;
    }

    $deleted = 0;
    $files = glob(CACHE_DIR . "/*.json");

    if ($files === false) {
        return 0;
    }

    foreach ($files as $file) {
        $mtime = filemtime($file);
        if ($mtime === false) {
            continue;
        }
        $fileAge = time() - $mtime;
        if ($fileAge > $maxAge) {
            if (@unlink($file)) {
                $deleted++;
            }
        }
    }

    return $deleted;
}

/**
 * Clear cache for a specific user
 */
function clearUserCache(string $user): bool
{
    if (!is_dir(CACHE_DIR)) {
        return true;
    }

    $key = getCacheKey($user, []);
    $filePath = getCacheFilePath($key);

    if (file_exists($filePath)) {
        return @unlink($filePath);
    }

    return true;
}
