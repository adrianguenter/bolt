<?php
namespace Bolt\Filesystem;

use Bolt\Exception\LowlevelException;
use League\Flysystem\Adapter\Local as LocalBase;
use League\Flysystem\Config;
use League\Flysystem\Util;

class Local extends LocalBase
{
    const VISIBILITY_READONLY = 'readonly';

    const MODE_IFSOCK = 0140000; // socket
    const MODE_IFLNK  = 0120000; // symbolic link
    const MODE_IFREG  = 0100000; // regular file
    const MODE_IFBLK  = 0060000; // block device
    const MODE_IFDIR  = 0040000; // directory
    const MODE_IFCHR  = 0020000; // character device
    const MODE_IFIFO  = 0010000; // fifo

    const MODE_ALL       = 7;
    const MODE_READWRITE = 6;
    const MODE_READEXEC  = 5;
    const MODE_READ      = 4;
    const MODE_WRITE     = 2;
    const MODE_EXEC      = 1;

    const WHOM_SOMEONE = 0b1111; // U|G|O
    const WHOM_ALL     = 0b0111; // UGO
    const WHOM_EITHER  = 0b1110; // U|G
    const WHOM_BOTH    = 0b0110; // UG
    const WHOM_OWNER   = 0b0100; // U
    const WHOM_GROUP   = 0b0010; // G
    const WHOM_OTHERS  = 0b0001; // O

    protected static $permissions
        = array(
            'public'   => 0755,
            'readonly' => 0744,
            'private'  => 0700,
        );

    /**
     * @deprecated
     * @type int $processUserId
     */
    private static $processUserId;

    /**
     * @deprecated Re-home me ASAP!
     * @return int The user ID of the process
     */
    private static function getProcessUserId()
    {
        if (is_numeric(static::$processUserId)) {
            return static::$processUserId;
        }

        try {
            // In order of priority/preference, descending
            foreach (array('posix_getuid', 'getmyuid') as $function) {
                if (function_exists($function)) {
                    return static::$processUserId = (int) call_user_func($function);
                }
            }

            throw new LowlevelException('Unable to resolve a suitable user ID provider function.');
        } catch (\Exception $e) {
            fprintf(STDERR, '%s', $e->getMessage());
            exit(1);
        }
    }

    /**
     * @deprecated
     * @type int[] $processGroupIds
     */
    private static $processGroupIds;

    /**
     * @deprecated Re-home me ASAP!
     * @return int[] The group ID(s) of the process
     */
    private static function getProcessGroupIds()
    {
        if (is_array(static::$processGroupIds)) {
            return static::$processGroupIds;
        }
        try {
            // In order of priority/preference, descending
            foreach (array('posix_getgroups', 'posix_getgid', 'getmygid') as $function) {
                if (function_exists($function)) {
                    return static::$processGroupIds = (array) call_user_func($function);
                }
            }

            throw new LowlevelException('Unable to resolve a suitable group ID provider function.');
        } catch (\Exception $e) {
            fprintf(STDERR, '%s', $e->getMessage());
            exit(1);
        }
    }

    public function __construct($root)
    {
        $realRoot = $this->ensureDirectory($root);
        $this->setPathPrefix($realRoot);
    }

    protected function ensureDirectory($root)
    {
        if (!is_dir($root) && !@mkdir($root, 0755, true)) {
            return false;
        }

        return realpath($root);
    }

    public function write($path, $contents, Config $config)
    {
        $location = $this->applyPathPrefix($path);
        if (!$this->ensureDirectory(dirname($location))) {
            return false;
        }

        return parent::write($path, $contents, $config);
    }

    public function writeStream($path, $resource, Config $config)
    {
        $location = $this->applyPathPrefix($path);
        if (!$this->ensureDirectory(dirname($location))) {
            return false;
        }

        return parent::writeStream($path, $resource, $config);
    }

    public function update($path, $contents, Config $config)
    {
        $location = $this->applyPathPrefix($path);
        $mimetype = Util::guessMimeType($path, $contents);

        if (!is_writable($location)) {
            return false;
        }

        if (($size = file_put_contents($location, $contents, LOCK_EX)) === false) {
            return false;
        }

        return compact('path', 'size', 'contents', 'mimetype');
    }

    public function rename($path, $newpath)
    {
        $location        = $this->applyPathPrefix($path);
        $destination     = $this->applyPathPrefix($newpath);
        $parentDirectory = $this->applyPathPrefix(Util::dirname($newpath));
        if (!$this->ensureDirectory($parentDirectory)) {
            return false;
        }

        return rename($location, $destination);
    }

    public function copy($path, $newpath)
    {
        $location    = $this->applyPathPrefix($path);
        $destination = $this->applyPathPrefix($newpath);
        if (!$this->ensureDirectory(dirname($destination))) {
            return false;
        }

        return copy($location, $destination);
    }

    public function delete($path)
    {
        $location = $this->applyPathPrefix($path);

        if (!is_writable($location)) {
            return false;
        }

        return unlink($location);
    }

    public function createDir($dirname, Config $config)
    {
        $location = $this->applyPathPrefix($dirname);

        // mkdir recursively creates directories.
        // It's easier to ignore errors and check result
        // than try to recursively check for file permissions
        if (!is_dir($location) && !@mkdir($location, 0777, true)) {
            return false;
        }

        return array('path' => $dirname, 'type' => 'dir');
    }

    public function deleteDir($dirname)
    {
        $location = $this->applyPathPrefix($dirname);
        if (!is_dir($location) || !is_writable($location)) {
            return false;
        }

        return parent::deleteDir($dirname);
    }

    /**
     * Get the normalized path from a SplFileInfo object.
     *
     * @param \SplFileInfo $file
     *
     * @return string
     */
    protected function getFilePath(\SplFileInfo $file)
    {
        $path = parent::getFilePath($file);
        if ($this->pathSeparator === '\\') {
            return str_replace($this->pathSeparator, '/', $path);
        } else {
            return $path;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getVisibility($path)
    {
        $location = $this->applyPathPrefix($path);
        clearstatcache(false, $location);
        if ($this->isAccessibleToProcess($location)) {
            $visibility = self::VISIBILITY_PUBLIC;
        } elseif ($this->isReadableToProcess($location)) {
            $visibility = self::VISIBILITY_READONLY;
        } else {
            $visibility = self::VISIBILITY_PRIVATE;
        }

        return compact('visibility');
    }

    public function isDirectory($location, $useCache = true)
    {
        return self::testModeMask($this->getMode($location, $useCache), static::MODE_IFDIR);
    }

    /**
     * Determines if the owner of the current process also owns a specified
     * filesystem object.
     *
     * @param string $location Path to the filesystem object
     * @param bool $useCache If false, `clearstatcache` first
     *
     * @return bool The test result
     * @throws \Bolt\Exception\LowlevelException If the UID cannot be determined
     */
    public function isOwnedByProcess($location, $useCache = true)
    {
        $useCache || clearstatcache(false, $location);

        return fileowner($location) === static::getProcessUserId();
    }

    /**
     * Determines if the owner of the current process is a member of the
     * group assigned to a specified filesystem object.
     *
     * @param string $location Path to the filesystem object
     * @param bool $useCache If false, `clearstatcache` first
     *
     * @return bool The test result
     * @throws \Bolt\Exception\LowlevelException If the GID(s) cannot be determined
     */
    public function isProcessInGroup($location, $useCache = true)
    {
        $useCache || clearstatcache(false, $location);

        return in_array(filegroup($location), static::getProcessGroupIds(), false);
    }

    /**
     * Determines whether or not the current process has read access
     * (read + execute for directories) to a specified filesystem object.
     *
     * @param string $location Path to the filesystem object
     * @param bool|true $useCache If false, `clearstatcache` first
     *
     * @return bool The test result
     */
    public function isReadableToProcess($location, $useCache = true)
    {
        $userOrGroup = $this->isOwnedByProcess($location, $useCache)
                       || $this->isProcessInGroup($location, $useCache);

        return $this->isReadableTo($location,
            ($userOrGroup ? self::WHOM_EITHER : self::WHOM_OTHERS)
        );
    }

    /**
     * Determines whether or not the current process has write access
     * (write + execute for directories) to a specified filesystem object.
     *
     * @param string $location Path to the filesystem object
     * @param bool|true $useCache If false, `clearstatcache` first
     *
     * @return bool The test result
     * @throws \Bolt\Exception\LowlevelException
     */
    public function isWritableToProcess($location, $useCache = true)
    {
        $userOrGroup = $this->isOwnedByProcess($location, $useCache)
                       || $this->isProcessInGroup($location, $useCache);

        return $this->isWritableTo($location,
            ($userOrGroup ? self::WHOM_EITHER : self::WHOM_OTHERS)
        );
    }

    /**
     * Determine whether or not the current process has read-write access
     * (read + write + execute for directories) to a specified filesystem
     * object.
     *
     * @param string $location Path to the filesystem object
     * @param bool|true $useCache If false, `clearstatcache` first
     *
     * @return bool The test result
     * @throws \Bolt\Exception\LowlevelException
     */
    public function isAccessibleToProcess($location, $useCache = true)
    {
        $userOrGroup = $this->isOwnedByProcess($location, $useCache)
                       || $this->isProcessInGroup($location, $useCache);

        return $this->isAccessibleTo($location,
            ($userOrGroup ? self::WHOM_EITHER : self::WHOM_OTHERS)
        );
    }

    /**
     * Determine whether or not a class has read access (read + execute
     * for directories) to a specified filesystem object.
     *
     * @param string $location Path to the filesystem object
     * @param int $whom The access class to test for
     * @param bool|true $useCache If false, `clearstatcache` first
     *
     * @return bool The test result
     */
    public function isReadableTo($location, $whom, $useCache = true)
    {
        return $this->checkAccessFor($location, $whom, self::MODE_READ, $useCache);
    }

    /**
     * Determine whether or not a class has write access (write + execute
     * for directories) to a specified filesystem object.
     *
     * @param string $location Path to the filesystem object
     * @param int $whom The access class to test for
     * @param bool|true $useCache If false, `clearstatcache` first
     *
     * @return bool The test result
     */
    public function isWritableTo($location, $whom, $useCache = true)
    {
        return $this->checkAccessFor($location, $whom, self::MODE_WRITE, $useCache);
    }

    /**
     * Determine whether or not a class has read-write access (read + write +
     * execute for directories) to a specified filesystem object.
     *
     * @param string $location Path to the filesystem object
     * @param int $whom The access class to test for
     * @param bool|true $useCache If false, `clearstatcache` first
     *
     * @return bool The test result
     */
    public function isAccessibleTo($location, $whom, $useCache = true)
    {
        return $this->checkAccessFor($location, $whom, self::MODE_READWRITE, $useCache);
    }

    /**
     * Test a (minimum) mode level for a filesystem access class against
     * a specified filesystem object.
     *
     * @param string $location Path to the filesystem object
     * @param int $whom The access class to test for
     * @param int $accessLevel The access mode (single octal) to test for
     * @param bool|true $useCache If false, `clearstatcache` first
     *
     * @return bool The test result
     */
    protected function checkAccessFor(
        $location,
        $whom,
        $accessLevel = self::MODE_READWRITE,
        $useCache = true
    ) {
        $strict = true;
        if ($whom > self::WHOM_ALL) {
            // Disable strict so any of the set bits will match.
            $strict = false;
            // Binary AND $whom with WHOM_ALL (0b0111) to disable the high (left) bit.
            // EITHER  (1110) & ALL (111) = BOTH (110)
            // SOMEONE (1111) & ALL (111) = ALL  (111)
            $whom &= self::WHOM_ALL;
        }

        if ($this->isDirectory($location, $useCache)) {
            // Add an implicit execute bit for directories.
            $accessLevel &= self::MODE_EXEC;
        }

        try {
            // Multiply the binary representation of $whom by the $access mode
            //  to get an octal string, then parse it.
            //  Example:
            //      decbin($whom = WHOM_BOTH = 0b110) : "110"
            //      "110" * ($accessLevel = MODE_READWRITE = 6) : 660
            //      intval(660, 8) : 0660
            //      $modeMask = 0660
            return $this->checkMode($location, intval(decbin($whom) * $accessLevel, 8), $strict);
        } catch (\RuntimeException $e) {
            // TODO This is a bug…should this emit a warning or something?
            return false;
        }
    }

    /**
     * Resolves the access mode (permissions and attributes) of a file.
     *
     * @param string $location Path to the filesystem object
     * @param bool|true $useCache If false, `clearstatcache` first
     *
     * @return int The filesystem object's mode
     */
    protected function getMode($location, $useCache = true)
    {
        $useCache || clearstatcache(false, $location);

        // fileperms returns FALSE if the file's parent cannot be reached (missing x-bit).
        return (int) fileperms($location);
    }

    /**
     * Tests a bitmask against a file's access mode.
     *
     * @param string $location Path to the filesystem object
     * @param int $modeMask The mode mask to test against
     * @param bool $strict Whether or not all bits must match
     * @param bool|true $useCache If false, `clearstatcache` first
     *
     * @return bool The test result
     * @throws \RuntimeException If the value of $modeMask is invalid
     *
     */
    protected function checkMode($location, $modeMask, $strict = true, $useCache = true)
    {
        return self::testModeMask($this->getMode($location, $useCache), $modeMask, $strict);
    }

    /**
     * Tests a file access mode bitmask.
     *
     * @param int $mode The filesystem object mode being tested
     * @param int $modeMask The mode mask to test against
     * @param bool|true $strict Whether or not all bits must match
     *
     * @return bool The test result
     * @throws \RuntimeException If the value of $modeMask is invalid
     */
    protected static function testModeMask($mode, $modeMask, $strict = true)
    {
        // Protect against false positives
        if ((int) $modeMask <= 0) {
            throw new \RuntimeException('$modeMask must have an integer value greater than zero.');
        }

        return $strict ? ($mode & $modeMask) === $modeMask : ($mode & $modeMask) > 0;
    }
}
