<?php
/**
 * Created by PhpStorm.
 * User: steve
 * Date: 04/08/17
 * Time: 09:14
 */

namespace Barracuda\ArchiveStream;

interface ArchiveInterface
{
    
    /**
     * Add file to the archive
     *
     * Parameters:
     *
     * @param string $name Path of file in the archive (including directory).
     * @param string $data Contents of the file.
     * @param array  $opt  Map of file options (see above for list).
     *
     * @return void
     */
    public function addFile($name, $data, array $opt = []);
    
    /**
     * Add file by path
     *
     * @param string $name Name of file in archive (including directory path).
     * @param string $path Path to file on disk (note: paths should be encoded using
     *                     UNIX-style forward slashes -- e.g '/path/to/some/file').
     * @param array  $opt  Map of file options (see above for list).
     *
     * @return void
     */
    public function addFileFromPath($name, $path, array $opt = []);
    
    /**
     * Complete the current file stream
     *
     * @return void
     */
    public function completeFileStream();
    
    /**
     * Finish an archive
     *
     * @return void
     */
    
    public function finish();
    
    /**
     * Initialize a file stream
     *
     * @param string $name File path or just name.
     * @param int    $size Size in bytes of the file.
     * @param array  $opt  Array containing time / type (optional).
     * @param int    $meth Method of compression to use (defaults to store).
     *
     * @return void
     */
    
    public function initFileStreamTransfer($name, $size, array $opt = [], $meth = null);
    
    /**
     * Stream the next part of the current file stream.
     *
     * @param string $data        Raw data to send.
     * @param bool   $single_part Used to determine if we can compress.
     *
     * @return void
     */
    public function streamFilePart($data, $single_part = false);
}
