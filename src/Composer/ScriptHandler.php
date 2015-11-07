<?php
/**
 * Based on Sensio\Bundle\DistributionBundle\Composer\ScriptHandler.
 *
 * @see https://github.com/sensio/SensioDistributionBundle/blob/master/Composer/ScriptHandler.php
 */

namespace Bolt\Composer;

use Bolt\Exception\LowlevelException;
use Composer\Script\Event;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

class ScriptHandler
{
    private static $extra;
    private static $dirMode;
    private static $rootPath;

    /**
     * Install basic assets and create needed directories.
     *
     * @param Event $event
     * @param array|bool $extra
     *
     * @throws LowlevelException
     */
    public static function installAssets(Event $event, $extra = false)
    {
        if ($extra === false) {
            /** @type string[] $extra */
            $extra = self::getOptions($event);
        }

        $mkdirMode = $extra['bolt-mkdir-mode'];
        if (is_string($mkdirMode)) {
            if (!is_numeric($mkdirMode)) {
                throw new LowlevelException(
                  'composer.json <code>bolt-mkdir-mode</code>'
                  .' “extra” property must be an integer or octal string.');
            }
            $mkdirMode = intval($mkdirMode, 8);
        }
        umask(0777 - $mkdirMode);

        $rootPath     = realpath($extra['bolt-root-path']);
        $composerPath = realpath(__DIR__.'/../../../..');
        $distPath     = $composerPath.'/bolt/bolt';
        $distAppPath  = $distPath.'/app';
        $distSrcPath  = $distPath.'/src';

        try {
            $filesystem = new Filesystem();

            /**
             * @type callable $pathResolver
             *
             * @param string $key The “extra” key to fetch (bolt-{$key}-{type})
             * @param bool $create (DIR ONLY) Create the directory if it doesn't exist (or recreate after $format)
             * @param bool $format (DIR ONLY) Destroy the directory if it exists
             *
             * @return string
             */
            $pathResolver = function (
              $key,
              $create = false,
              $format = false
            )
            use ($filesystem, $extra, $rootPath, $mkdirMode) {

                $key = 'bolt-'.$key;

                $strategies = [
                  'path' => function ($key, $extra) {
                      // Absolute path
                      return !array_key_exists($key.'-path', $extra) ?: $extra[$key.'-path'];
                  },
                  'relpath' => function ($key, $extra) use ($rootPath) {
                      // Relative path
                      if (!array_key_exists($key.'-path', $extra)) {
                          return false;
                      }

                      return $rootPath.'/'.$extra[$key.'-path'];
                  },
                  '-' => function ($key) {
                      // All strategies failed
                      throw new LowlevelException(
                        sprintf('No path provided for <code>%s</code> in '
                                .'<code>composer.json</code>.', $key));
                  },
                ];
                $path       = null;
                foreach ($strategies as $cb) {
                    $path = $cb($key, $extra);
                    if ($path !== false) {
                        break;
                    }
                }

                if ($format) {
                    $filesystem->remove($path);
                }

                if ($create && !$filesystem->exists($path)) {
                    $filesystem->mkdir($path, $mkdirMode);
                }

                return $path;
            };

            $mirrorRelpathArray = function (
              $array,
              $origin,
              $target,
              $iterator,
              $options
            ) use ($filesystem) {
                $skipExisting = (array_key_exists('skip_existing', $options)
                                 && $options['skip_existing'] === true);

                foreach ($array as $relPath) {
                    if ($skipExisting && $filesystem->exists($target.'/'.$relPath)) {
                        continue;
                    }

                    $filesystem->mirror(
                      $origin.'/'.$relPath, $target.'/'.$relPath, $iterator, $options);
                }
            };

            $public        = $pathResolver('public', true, true);

            $mirrorRelpathArray(['css', 'fonts', 'img', 'js'],
              $distAppPath.'/view/', $public.'/view/');

            $mirrorRelpathArray(['files', 'theme', 'extensions'],
              $distPath, $public, null, ['skip_existing' => true]);

            $mirrorRelpathArray(['cache', 'config', 'database', 'extensions', 'files'],
              $distPath, $public, null, ['skip_existing' => true]);

            $bootstrapFile = $pathResolver('bootstrap_file');

        } catch (IOException $ioException) {
            throw new LowlevelException($ioException->getMessage(),
              $ioException->getCode(), $ioException);
        }
    }

    protected static function resolveDirectory($optionKey)
    {
        $path = static::$extra['bolt-'.$optionKey.'-dir'];

        if ($path === '' || $path[0] !== '/') {
            return static::$rootPath.'/'.$path;
        }

        return $path;
    }

    public static function bootstrap(Event $event)
    {
        $webroot = $event->getIO()
                         ->askConfirmation('<info>Do you want your web directory to be a separate folder to root? [y/n] </info>',
                           false)
        ;

        if ($webroot) {
            $webname  = $event->getIO()
                              ->ask('<info>What do you want your public directory to be named? [default: public] </info>',
                                'public')
            ;
            $webname  = trim($webname, '/');
            $assetDir = './'.$webname;
        } else {
            $webname  = null;
            $assetDir = '.';
        }

        $generator = new BootstrapGenerator($webroot, $webname);
        $generator->create();
        $options = array_merge(self::getOptions($event), ['bolt-web-dir' => $assetDir]);
        self::installAssets($event, $options);
        $event->getIO()->write('<info>Your project has been set up</info>');
    }

    /**
     * Get a default set of options.
     *
     * @param Event $event
     *
     * @return array
     */
    protected static function getOptions(Event $event)
    {
        $options = array_merge(
          [
            'bolt-mkdir-mode' => 0750,
            'bolt-mkdir_public-mode' => 0755,
            'bolt-root-path' => './',
            'bolt-backend_views-relpath' => 'vendor/bolt/bolt/app/view/twig',
            'bolt-cache-relpath' => 'data/bolt/cache',
            'bolt-config-relpath' => 'config/bolt',
            'bolt-database-relpath' => 'data/bolt/database',
            'bolt-extensions-relpath' => 'data/bolt/extensions',
            'bolt-extensions_config-relpath' => 'config/bolt/extensions',
            'bolt-files-relpath' => 'data/bolt/files',
            'bolt-public-relpath' => 'public/bolt',
            'bolt-php_bootstrap-relpath' => 'bolt-bootstrap.php',
          ],
          $event->getComposer()->getPackage()->getExtra()
        );

        return $options;
    }
}
