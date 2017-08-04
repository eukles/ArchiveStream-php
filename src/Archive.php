<?php

namespace Barracuda\ArchiveStream;

use Barracuda\ArchiveStream\TarArchive as Tar;
use Barracuda\ArchiveStream\ZipArchive as Zip;

/**
 * A streaming archive object.
 */
abstract class Archive implements ArchiveInterface
{
    
    /**
     * Block size to process files in. Defaults to 1M
     *
     * @var int
     */
    protected $blockSize = 1048576;
    /**
     * Base path for files added to the archive.
     *
     * @var string
     */
    protected $containerDirName = '';
    /**
     * @var string
     */
    protected $methodStr;
    /**
     * @var bool
     */
    protected $needHeaders;
    /**
     * Array of specified options for the archive.
     *
     * @var array
     */
    protected $opt = [];
    /**
     * @var null|string
     */
    protected $outputName;
    /**
     * Whether to use the specified base path or not for files in the archive.
     *
     * @var bool
     */
    protected $useContainerDir = false;
    /**
     * Message to place at the top of the error log.
     *
     * @var string
     */
    private $errorHeaderText = 'The following errors were encountered while generating this archive:';
    /**
     * Filename for the error log which will be placed inside the archive.
     *
     * @var string
     */
    private $errorLogFilename = 'archive_errors.log';
    /**
     * List of errors encountered while generating the archive.
     *
     * @var array
     */
    private $errors = [];
    
    /**
     * Create a new ArchiveStream object.
     *
     * @param string $name     The name of the resulting archive (optional).
     * @param array  $opt      Hash of archive options (see archive options in readme).
     * @param string $basePath An optional base path for files to be named under.
     */
    protected function __construct($name = null, array $opt = [], $basePath = null)
    {
        // save options
        $this->opt = $opt;
        
        // if a $base_path was passed set the protected property with that value, otherwise leave it empty
        $this->containerDirName = isset($basePath) ? $basePath . '/' : '';
        
        // set large file defaults: size = 20 megabytes, method = store
        if (!isset($this->opt['large_file_size'])) {
            $this->opt['large_file_size'] = 20 * 1024 * 1024;
        }
        
        if (!isset($this->opt['large_files_only'])) {
            $this->opt['large_files_only'] = false;
        }
        
        $this->outputName = $name;
        if ($name || isset($opt['send_http_headers'])) {
            $this->needHeaders = true;
        }
        
        // turn off output buffering
        while (ob_get_level() > 0) {
            // throw away any output left in the buffer
            ob_end_clean();
        }
    }
    
    /**
     * Complete the current file stream
     *
     * @return void
     */
    abstract public function completeFileStream();
    
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
    
    abstract public function initFileStreamTransfer($name, $size, array $opt = [], $meth = null);
    
    /**
     * Stream the next part of the current file stream.
     *
     * @param string $data        Raw data to send.
     * @param bool   $single_part Used to determine if we can compress.
     *
     * @return void
     */
    abstract public function streamFilePart($data, $single_part = false);
    
    /**
     * Create instance based on userAgent string
     *
     * @param string $baseFilename A name for the resulting archive (without an extension).
     * @param array  $opt          Map of archive options (see above for list).
     *
     * @return ArchiveInterface for either zip or tar
     */
    public static function createByUserAgent($baseFilename = null, array $opt = [])
    {
        $userAgent = (isset($_SERVER['HTTP_USER_AGENT']) ? strtolower($_SERVER['HTTP_USER_AGENT']) : '');
        
        // detect windows and use zip
        if (strpos($userAgent, 'windows') !== false) {
            $filename = (($baseFilename === null) ? null : $baseFilename . '.zip');
            
            return new Zip($filename, $opt, $baseFilename);
        } // fallback to tar
        else {
            $filename = (($baseFilename === null) ? null : $baseFilename . '.tar');
            
            return new Tar($filename, $opt, $baseFilename);
        }
    }
    
    /**
     * If errors were encountered, add an error log file to the end of the archive
     *
     * @return void
     */
    public function addErrorLog()
    {
        if (!empty($this->errors)) {
            $msg = $this->errorHeaderText;
            foreach ($this->errors as $err) {
                $msg .= "\r\n\r\n" . $err;
            }
            
            // stash current value so it can be reset later
            $temp = $this->useContainerDir;
            
            // set to false to put the error log file in the root instead of the container directory, if we're using one
            $this->useContainerDir = false;
            
            $this->addFile($this->errorLogFilename, $msg);
            
            // reset to original value and dump the temp variable
            $this->useContainerDir = $temp;
            unset($temp);
        }
    }
    
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
    public function addFile($name, $data, array $opt = [])
    {
        // calculate header attributes
        $this->methodStr = 'deflate';
        $method          = 0x08;
        
        // send file header
        $this->initFileStreamTransfer($name, strlen($data), $opt, $method);
        
        // send data
        $this->streamFilePart($data, $single_part = true);
        
        // complete the file stream
        $this->completeFileStream();
    }
    
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
    public function addFileFromPath($name, $path, array $opt = [])
    {
        if ($this->opt['large_files_only'] || $this->isLargeFile($path)) {
            // file is too large to be read into memory; add progressively
            $this->addLargeFile($name, $path, $opt);
        } else {
            // file is small enough to read into memory; read file contents and
            // handle with add_file()
            $data = file_get_contents($path);
            $this->addFile($name, $data, $opt);
        }
    }
    
    /**
     * Log an error to be added to the error log in the archive.
     *
     * @param string $message Error text to add to the log file.
     *
     * @return void
     */
    public function pushError($message)
    {
        $this->errors[] = (string)$message;
    }
    
    /**
     * Set the first line of text in the error log file
     *
     * @param string $msg Message to display on the first line of the error log file.
     *
     * @return void
     */
    public function setErrorHeaderText($msg)
    {
        if (isset($msg)) {
            $this->errorHeaderText = (string)$msg;
        }
    }
    
    /**
     * Set the name filename for the error log file when it's added to the archive
     *
     * @param string $name Filename for the error log.
     *
     * @return void
     */
    public function setErrorLogFilename($name)
    {
        if (isset($name)) {
            $this->errorLogFilename = (string)$name;
        }
    }
    
    /**
     * Set whether or not all elements in the archive will be placed within one container directory.
     *
     * @param bool $bool True to use contaner directory, false to prevent using one. Defaults to false.
     *
     * @return void
     */
    public function setUseContainerDir($bool = false)
    {
        $this->useContainerDir = (bool)$bool;
    }
    
    /**
     * Add a large file from the given path
     *
     * @param  string $name Name of file in archive (including directory path).
     * @param  string $path Path to file on disk (note: paths should be encoded using
     *                      UNIX-style forward slashes -- e.g '/path/to/some/file').
     * @param  array  $opt  Map of file options (see above for list).
     *
     * @return void
     */
    protected function addLargeFile($name, $path, array $opt = [])
    {
        // send file header
        $this->initFileStreamTransfer($name, filesize($path), $opt);
        
        // open input file
        $fh = fopen($path, 'rb');
        
        // send file blocks
        while ($data = fread($fh, $this->blockSize)) {
            // send data
            $this->streamFilePart($data);
        }
        
        // close input file
        fclose($fh);
        
        // complete the file stream
        $this->completeFileStream();
    }
    
    /**
     * Is this file larger than large_file_size?
     *
     * @param string $path Path to file on disk.
     *
     * @return bool True if large, false if small.
     */
    protected function isLargeFile($path)
    {
        $st = stat($path);
        
        return ($this->opt['large_file_size'] > 0) && ($st['size'] > $this->opt['large_file_size']);
    }
    
    /**
     * Create a format string and argument list for pack(), then call pack() and return the result.
     *
     * @param array $fields Key is format string and the value is the data to pack.
     *
     * @return string Binary packed data returned from pack().
     */
    protected function packFields(array $fields)
    {
        $fmt  = '';
        $args = [];
        
        // populate format string and argument list
        foreach ($fields as $field) {
            $fmt    .= $field[0];
            $args[] = $field[1];
        }
        
        // prepend format string to argument list
        array_unshift($args, $fmt);
        
        // build output string from header and compressed data
        return call_user_func_array('pack', $args);
    }
    
    /**
     * Send string, sending HTTP headers if necessary.
     *
     * @param string $data Data to send.
     *
     * @return void
     */
    protected function send($data)
    {
        if ($this->needHeaders) {
            $this->sendHttpHeaders();
        }
        
        $this->needHeaders = false;
        
        echo $data;
    }
    
    /**
     * Send HTTP headers for this stream.
     *
     * @return void
     */
    private function sendHttpHeaders()
    {
        // grab options
        $opt = $this->opt;
        
        // grab content type from options
        if (isset($opt['content_type'])) {
            $content_type = $opt['content_type'];
        } else {
            $content_type = 'application/x-zip';
        }
        
        // grab content type encoding from options and append to the content type option
        if (isset($opt['content_type_encoding'])) {
            $content_type .= '; charset=' . $opt['content_type_encoding'];
        }
        
        // grab content disposition
        $disposition = 'attachment';
        if (isset($opt['content_disposition'])) {
            $disposition = $opt['content_disposition'];
        }
        
        if ($this->outputName) {
            $disposition .= "; filename=\"{$this->outputName}\"";
        }
        
        $headers = [
            'Content-Type'              => $content_type,
            'Content-Disposition'       => $disposition,
            'Pragma'                    => 'public',
            'Cache-Control'             => 'public, must-revalidate',
            'Content-Transfer-Encoding' => 'binary',
        ];
        
        foreach ($headers as $key => $val) {
            header("$key: $val");
        }
    }
}
