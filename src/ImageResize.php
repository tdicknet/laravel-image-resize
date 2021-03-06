<?php
namespace Mxmm\ImageResize;

use Intervention\Image\Facades\Image;
use League\Flysystem\AwsS3v3\AwsS3Adapter;
use League\Flysystem\Adapter\Local as LocalAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Request;
use Exception;

class ImageResize
{
    protected $config;
    /**
     * @var String $path Image source file path
     */
    private $path;
    /**
     * Intervention Image method. Currently only supports 'fit' and 'resize' method
     * @var String $action|fit
     */
    private $action;

    private $width;
    private $height;
    private $basename;
    private $adapter;
    private $targetPath;
    private $targetMetaData = [];
    private $targetTimestamp;
    private $sourceTimestamp;

    public function __construct(array $config, string $path = null)
    {
        $this->config       = $config;
        $this->path         = $path;
        $this->basename     = pathinfo($this->path)['basename'];
    }

    /**
     * @param string|null $path
     * @param int|null $width
     * @param int|null $height
     * @param string $action
     * @return string
     */
    public static function url(string $path = null, int $width = null, int $height = null, string $action = 'fit'): string
    {
        return (new ImageResize(config('image-resize'), $path))->getResizedImage($path, $width, $height, $action);
    }

    public static function path(string $path = null, int $width = null, int $height = null, string $action = 'fit'): string
    {
        return (new ImageResize(config('image-resize'), $path))->getResizedImage($path, $width, $height, $action, false);
    }

    private function getResizedImage(string $path = null, int $width = null, int $height = null, string $action = 'fit', $url = true): string
    {
        if (!$path || $width < 1 && $height < 1) {
            return '';
        }

        $this->settings($width, $height, $action);

        if (!$this->setTargetMetaData()) {
            return '';
        }

        if (!in_array(strtolower(pathinfo($path)['extension']), ['jpg', 'jpeg', 'png', 'gif'])) {
            return $this->filePlaceholder(pathinfo($path), $path);
        }

        $this->resize();

        return $url === true ? $this->getUrl() : $this->targetPath;
    }

    private function settings(int $width = null, int $height = null, $action = 'fit'): ImageResize
    {
        $this->width    = $width;
        $this->height   = $height;
        $this->action   = $action;
        $this->adapter  = Storage::getAdapter();
        $this->setTargetPath();

        if (Cache::has($this->targetPath)) {
            $this->targetTimestamp = Cache::get($this->targetPath);
        }

        if (Cache::has($this->path)) {
            $this->sourceTimestamp = Cache::get($this->path);
        }

        return $this;
    }

    private function setTargetPath(): ImageResize
    {
        $dirName = dirname($this->path);

        $targetDirName       = $this->config['dir'];
        $targetDirName      .= $dirName !== '.' && $dirName !== '/' ? ltrim($dirName, '/') . '/' : '';
        $targetDirName      .= $this->action . '/' . $this->width . 'x' . $this->height . '/';
        $this->targetPath    = $targetDirName . $this->basename;

        return $this;
    }

    private function setTargetMetaData(): bool
    {
        if ($this->targetTimestamp) {
            return true;
        }

        try {
            $this->targetMetaData  = Storage::getMetadata($this->targetPath);
            $this->targetTimestamp = $this->setTimestamp($this->targetPath, $this->targetMetaData);
        } catch (Exception $e) {
            if (!$this->adapter instanceof LocalAdapter && !Storage::exists($this->path)) {
                if (!Storage::disk('public')->exists($this->path)) {
                    return false;
                }
                // File exists in local public disk but not in cloud
                $this->upload(
                    $this->path,
                    Storage::disk('public')->get($this->path),
                    Storage::disk('public')->mimeType($this->path)
                );
            }
        }

        return true;
    }

    private function setTimestamp($key, $metadata)
    {
        if (array_key_exists('timestamp', $metadata)) {
            $value = $metadata['timestamp'];
        } elseif (array_key_exists('info', $metadata)) {
            $value = $metadata['info']['filetime'];
        } else {
            return '';
        }

        Cache::put($key, $value, $this->config['cache-expiry']);
        return $value;
    }

    private function setSourceTimestamp(): bool
    {
        try {
            $sourceMetaData = Storage::getMetadata($this->path);
        } catch (Exception $e) {
            return false;
        }

        $this->sourceTimestamp = $this->setTimestamp($this->path, $sourceMetaData);
        return true;
    }

    private function getUrl(): string
    {
        if (method_exists($this->adapter, 'getUrl')) {
            $url = $this->adapter->getUrl($this->targetPath);
        } elseif ($this->adapter instanceof AwsS3Adapter) {
            $url = $this->getAwsUrl();
        } elseif ($this->adapter instanceof LocalAdapter) {
            $url = Storage::url($this->targetPath);
        } else {
            $url = '';
        }

        if (Request::secure() == true) {
            $url = str_replace('http:', 'https:', $url);
        }

        return $url;
    }

    private function getAwsUrl(): string
    {
        $endpoint = $this->adapter->getClient()->getEndpoint();
        $path     =  '/' . ltrim($this->adapter->getPathPrefix() . $this->targetPath, '/');

        if (!is_null($domain = Storage::getConfig()->get('url'))) {
            $url = rtrim($domain, '/') . $path;
        } else {
            $url  = $endpoint->getScheme() . '://' . $this->adapter->getBucket() . '.' . $endpoint->getHost() . $path;
        }

        return $url;
    }

    private function replace_extension($filename, $new_extension) {
        $info = pathinfo($filename);
        return $info['dirname']."/".$info['filename'] . '.' . $new_extension;
    }

    private function resize(): bool
    {
        if (!$this->sourceTimestamp) {
            $this->setSourceTimestamp();
        }

        if (!$this->sourceTimestamp || $this->targetTimestamp > $this->sourceTimestamp) {
            // source file doesn't exist or older that target file
            return false;
        }

        switch ($this->action) {
            case 'fit':
            case 'resize':
                try {
                    $image = Image::make(Storage::get($this->path))
                        ->setFileInfoFromPath(storage_path('app/' . $this->path))
                        ->orientate()
                        ->{$this->action}($this->width, $this->height, function ($constraint) {
                            $constraint->aspectRatio();
                            $constraint->upsize();
                        })->encode('jpeg', 75);

                    $this->targetPath = $this->replace_extension($this->targetPath, 'jpeg');
                    
                    $this->basename = pathinfo($this->targetPath)['basename'];

                    $this->upload($this->targetPath, (string) $image, 'jpeg');

                    $image2 = Image::make(Storage::get($this->path))
                        ->setFileInfoFromPath(storage_path('app/' . $this->path))
                        ->orientate()
                        ->{$this->action}($this->width, $this->height, function ($constraint) {
                            $constraint->aspectRatio();
                            $constraint->upsize();
                        })->encode('webp', 75);
                    
                    $this->targetPath = $this->replace_extension($this->targetPath, 'webp');
                    $this->basename = pathinfo($this->targetPath)['basename'];

                    $this->upload($this->targetPath, (string) $image2, 'webp');
                } catch (Exception $e) {
                    return false;
                }
                break;
            default:
                return false;
        }

        return true;
    }

    private function upload($path, $image, $contentType)
    {
        Storage::getDriver()->put($path, $image, [
            'visibility'         => 'public',
            'Expires'            => gmdate('D, d M Y H:i:s', time() + $this->config['browser-cache']) . ' GMT',
            'CacheControl'       => 'public, max-age=' . $this->config['browser-cache'],
            'ContentType'        => $contentType,
            'ContentDisposition' => 'inline; filename="' . $this->basename . '"',
        ]);
    }

    private function filePlaceholder(array $info, string $path): string
    {
        if (in_array($info['extension'], ['mp4', 'webm'])) {
            $url = asset('/vendor/laravel-image-resize/images/placeholders/video.svg');
        } elseif (in_array($info['extension'], ['svg'])) {
            $url = Storage::url($path);
        } else {
            $url = asset('/vendor/laravel-image-resize/images/placeholders/file.svg');
        }

        return $url;
    }
}