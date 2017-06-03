<?php

namespace xutl\composer;

use Composer\Script\Event;
use Composer\Util\Filesystem;
use Composer\Script\CommandEvent;
use Composer\Package\PackageInterface;
use Composer\Installer\LibraryInstaller;
use Composer\Repository\InstalledRepositoryInterface;

/**
 * Class Installer
 * @package xutl\composer
 */
class Installer extends LibraryInstaller
{
    const EXTRA_BOOTSTRAP = 'bootstrap';
    const EXTENSION_FILE = 'overtrue/extensions.php';

    /**
     * 是否支持指定的包类型
     * @return bool
     */
    public function supports($packageType)
    {
        return $packageType === 'wechat-extension';
    }

    /**
     * @inheritdoc
     */
    public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        // install the package the normal composer way
        parent::install($repo, $package);
        // add the package to overtrue/extensions.php
        $this->addPackage($package);
    }

    /**
     * @inheritdoc
     */
    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
    {
        parent::update($repo, $initial, $target);
        $this->removePackage($initial);
        $this->addPackage($target);
    }

    /**
     * @inheritdoc
     */
    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        // uninstall the package the normal composer way
        parent::uninstall($repo, $package);
        // remove the package from overtrue/extensions.php
        $this->removePackage($package);
    }

    protected function addPackage(PackageInterface $package)
    {
        $extension = [
            'name' => $package->getName(),
            'version' => $package->getVersion(),
        ];

        $extra = $package->getExtra();
        if (isset($extra[self::EXTRA_BOOTSTRAP])) {
            $extension['bootstrap'] = $extra[self::EXTRA_BOOTSTRAP];
        }

        $extensions = $this->loadExtensions();
        $extensions[$package->getName()] = $extension;
        $this->saveExtensions($extensions);
    }

    protected function removePackage(PackageInterface $package)
    {
        $packages = $this->loadExtensions();
        unset($packages[$package->getName()]);
        $this->saveExtensions($packages);
    }

    protected function loadExtensions()
    {
        $file = $this->vendorDir . '/' . static::EXTENSION_FILE;
        if (!is_file($file)) {
            return [];
        }
        // invalidate opcache of extensions.php if exists
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($file, true);
        }
        $extensions = require($file);

        return $extensions;
    }

    protected function saveExtensions(array $extensions)
    {
        $file = $this->vendorDir . '/' . static::EXTENSION_FILE;
        if (!file_exists(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        $array = str_replace("'<vendor-dir>", '$vendorDir . \'', var_export($extensions, true));
        file_put_contents($file, "<?php\n\n\$vendorDir = dirname(__DIR__);\n\nreturn $array;\n");
        // invalidate opcache of extensions.php if exists
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($file, true);
        }
    }

    /**
     * Special method to run tasks defined in `[extra][xutl\composer\Installer::postCreateProject]` key in `composer.json`
     *
     * @param Event $event
     */
    public static function postCreateProject($event)
    {
        static::runCommands($event, __METHOD__);
    }

    /**
     * Special method to run tasks defined in `[extra][xutl\composer\Installer::postInstall]` key in `composer.json`
     *
     * @param Event $event
     */
    public static function postInstall($event)
    {
        static::runCommands($event, __METHOD__);
    }

    /**
     * Special method to run tasks defined in `[extra][$extraKey]` key in `composer.json`
     *
     * @param Event $event
     * @param string $extraKey
     */
    protected static function runCommands($event, $extraKey)
    {
        $params = $event->getComposer()->getPackage()->getExtra();
        if (isset($params[$extraKey]) && is_array($params[$extraKey])) {
            foreach ($params[$extraKey] as $method => $args) {
                call_user_func_array([__CLASS__, $method], (array)$args);
            }
        }
    }

    /**
     * Sets the correct permission for the files and directories listed in the extra section.
     * @param array $paths the paths (keys) and the corresponding permission octal strings (values)
     */
    public static function setPermission(array $paths)
    {
        foreach ($paths as $path => $permission) {
            echo "chmod('$path', $permission)...";
            if (is_dir($path) || is_file($path)) {
                try {
                    if (chmod($path, octdec($permission))) {
                        echo "done.\n";
                    };
                } catch (\Exception $e) {
                    echo $e->getMessage() . "\n";
                }
            } else {
                echo "file not found.\n";
            }
        }
    }

    protected static function generateRandomString()
    {
        if (!extension_loaded('openssl')) {
            throw new \Exception('The OpenSSL PHP extension is required by overtrue/wechat.');
        }
        $length = 32;
        $bytes = openssl_random_pseudo_bytes($length);
        return strtr(substr(base64_encode($bytes), 0, $length), '+/=', '_-.');
    }

    /**
     * Copy files to specified locations.
     * @param array $paths The source files paths (keys) and the corresponding target locations
     * for copied files (values). Location can be specified as an array - first element is target
     * location, second defines whether file can be overwritten (by default method don't overwrite
     * existing files).
     */
    public static function copyFiles(array $paths)
    {
        foreach ($paths as $source => $target) {
            // handle file target as array [path, overwrite]
            $target = (array)$target;
            echo "Copying file $source to $target[0] - ";

            if (!is_file($source)) {
                echo "source file not found.\n";
                continue;
            }

            if (is_file($target[0]) && empty($target[1])) {
                echo "target file exists - skip.\n";
                continue;
            } elseif (is_file($target[0]) && !empty($target[1])) {
                echo "target file exists - overwrite - ";
            }

            try {
                if (!is_dir(dirname($target[0]))) {
                    mkdir(dirname($target[0]), 0777, true);
                }
                if (copy($source, $target[0])) {
                    echo "done.\n";
                }
            } catch (\Exception $e) {
                echo $e->getMessage() . "\n";
            }
        }
    }
}
