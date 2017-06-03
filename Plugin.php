<?php

namespace xutl\composer;

use Composer\Script;
use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Script\ScriptEvents;
use Composer\Plugin\PluginInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\DependencyResolver\Operation\UpdateOperation;

/**
 * Class Plugin
 * @package xutl\composer
 */
class Plugin implements PluginInterface, EventSubscriberInterface
{
    /**
     * @var array noted package updates.
     */
    private $_packageUpdates = [];

    /**
     * @var string path to the vendor directory.
     */
    private $_vendorDir;

    /**
     * @inheritdoc
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $installer = new Installer($io, $composer);
        $composer->getInstallationManager()->addInstaller($installer);
        $this->_vendorDir = rtrim($composer->getConfig()->get('vendor-dir'), '/');
        $file = $this->_vendorDir . '/overtrue/extensions.php';
        if (!is_file($file)) {
            @mkdir(dirname($file), 0777, true);
            file_put_contents($file, "<?php\n\nreturn [];\n");
        }
    }

    /**
     * 获取要监听的事件名称
     * @return array The event names to listen to.
     */
    public static function getSubscribedEvents()
    {
        return [
            PackageEvents::POST_PACKAGE_UPDATE => 'checkPackageUpdates',//检查包升级
            ScriptEvents::POST_UPDATE_CMD => 'showUpgradeNotes',//显示升级日志
        ];
    }


    /**
     * 监听 POST_PACKAGE_UPDATE 事件并记录包更新
     * @param PackageEvent $event
     */
    public function checkPackageUpdates(PackageEvent $event)
    {
        $operation = $event->getOperation();
        if ($operation instanceof UpdateOperation) {
            $this->_packageUpdates[$operation->getInitialPackage()->getName()] = [
                'from' => $operation->getInitialPackage()->getVersion(),
                'fromPretty' => $operation->getInitialPackage()->getPrettyVersion(),
                'to' => $operation->getTargetPackage()->getVersion(),
                'toPretty' => $operation->getTargetPackage()->getPrettyVersion(),
                'direction' => $event->getPolicy()->versionCompare(
                    $operation->getInitialPackage(),
                    $operation->getTargetPackage(),
                    '<'
                ) ? 'up' : 'down',
            ];
        }
    }

    /**
     * 监听 POST_UPDATE_CMD 事件以显示包升级信息。
     * @param Script\Event $event
     */
    public function showUpgradeNotes(Script\Event $event)
    {
        $packageName = 'overtrue/wechat';
        if (!isset($this->_packageUpdates[$packageName])) {
            return;
        }

        $package = $this->_packageUpdates['overtrue/wechat'];

        // do not show a notice on up/downgrades between dev versions
        // avoid messages like from version dev-master to dev-master
        if ($package['fromPretty'] == $package['toPretty']) {
            return;
        }

        $io = $event->getIO();

        // print the relevant upgrade notes for the upgrade
        // - only on upgrade, not on downgrade
        // - only if the "from" version is non-dev, otherwise we have no idea which notes to show
        if ($package['direction'] === 'up' && $this->isNumericVersion($package['fromPretty'])) {
            //查找升级信息
            $notes = $this->findUpgradeNotes($packageName, $package['fromPretty']);
            if ($notes !== false && empty($notes)) {
                // no relevent upgrade notes, do not show anything.
                return;
            }

            $this->printUpgradeIntro($io, $package);

            if ($notes) {
                // safety check: do not display notes if they are too many
                if (count($notes) > 250) {
                    $io->write("\n  <fg=yellow;options=bold>The relevant notes for your upgrade are too long to be displayed here.</>");
                } else {
                    $io->write("\n  " . trim(implode("\n  ", $notes)));
                }
            }

            $io->write("\n  You can find the upgrade notes for all versions online at:");
        } else {
            $this->printUpgradeIntro($io, $package);
            $io->write("\n  You can find the upgrade notes online at:");
        }
        $this->printUpgradeLink($io, $package);
    }

    /**
     * 打印升级说明链接
     * @param IOInterface $io
     * @param array $package
     */
    private function printUpgradeLink($io, $package)
    {
        $maxVersion = $package['direction'] === 'up' ? $package['toPretty'] : $package['fromPretty'];
        // make sure to always show a valid link, even if $maxVersion is something like dev-master
        if (!$this->isNumericVersion($maxVersion)) {
            $maxVersion = 'master';
        }
        $io->write("  https://github.com/overtrue/wechat/blob/$maxVersion/README.md\n");
    }

    /**
     * 打印升级信息
     * @param IOInterface $io
     * @param array $package
     */
    private function printUpgradeIntro($io, $package)
    {
        $io->write("\n  <fg=yellow;options=bold>Seems you have "
            . ($package['direction'] === 'up' ? 'upgraded' : 'downgraded')
            . ' Yii Framework from version '
            . $package['fromPretty'] . ' to ' . $package['toPretty'] . '.</>'
        );
        $io->write("\n  <options=bold>Please check the upgrade notes for possible incompatible changes");
        $io->write('  and adjust your application code accordingly.</>');
    }

    /**
     * 从文件读取升级说明并返回一行数组
     * @param string $packageName
     * @param string $fromVersion until which version to read the notes
     * @return array|false
     */
    private function findUpgradeNotes($packageName, $fromVersion)
    {
        return [];
        $upgradeFile = $this->_vendorDir . '/' . $packageName . '/README.md';
        if (!is_file($upgradeFile) || !is_readable($upgradeFile)) {
            return false;
        }
        $lines = preg_split('~\R~', file_get_contents($upgradeFile));
        $relevantLines = [];
        $consuming = false;
        foreach($lines as $line) {
            if (preg_match('/^Upgrade from overtrue/wechat ([0-9]\.[0-9]+\.?[0-9]*)/i', $line, $matches)) {
                if (version_compare($matches[1], $fromVersion, '<')) {
                    break;
                }
                $consuming = true;
            }
            if ($consuming) {
                $relevantLines[] = $line;
            }
        }
        return $relevantLines;
    }

    /**
     * Check whether a version is numeric, e.g. 3.0.0.
     * @param string $version
     * @return int|false
     */
    private function isNumericVersion($version)
    {
        return preg_match('~^([0-9]\.[0-9]+\.?[0-9]*)~', $version);
    }
}
