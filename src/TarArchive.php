<?php

namespace Barracuda\ArchiveStream;

/**
 * Tar-formatted streaming archive.
 */
class TarArchive extends Archive
{
    
    const REGTYPE = 0;
    const DIRTYPE = 5;
    const XHDTYPE = 'x';
    /**
     * Array of specified options for the archive.
     *
     * @var array
     */
    protected $file_size;
    
    /**
     * Create a new TarArchive object.
     *
     * @see \Barracuda\ArchiveStream\Archive
     *
     * @param null  $name
     * @param array $opt
     * @param null  $basePath
     */
    public function __construct($name = null, array $opt = [], $basePath = null)
    {
        parent::__construct($name, $opt, $basePath);
        $this->opt['content_type'] = 'application/x-tar';
    }
    
    /**
     * Explicitly adds a directory to the tar (necessary for empty directories).
     *
     * @param  string $name Name (path) of the directory.
     * @param  array  $opt  Additional options to set ("type" will be overridden).
     *
     * @return void
     */
    public function addDirectory($name, array $opt = [])
    {
        // calculate header attributes
        $this->methodStr = 'deflate';
        $method          = 0x08;
        
        $opt['type'] = self::DIRTYPE;
        
        // send header
        $this->initFileStreamTransfer($name, $size = 0, $opt, $method);
        
        // complete the file stream
        $this->completeFileStream();
    }
    
    /**
     * Complete the current file stream
     *
     * @return void
     */
    public function completeFileStream()
    {
        // ensure we pad the last block so that it is 512 bytes
        $mod = ($this->file_size % 512);
        if ($mod > 0) {
            $this->send(pack('a' . (512 - $mod), ''));
        }
        
        // flush the data to the output
        flush();
    }
    
    /**
     * Finish an archive
     *
     * @return void
     */
    public function finish()
    {
        // adds an error log file if we've been tracking errors
        $this->addErrorLog();
        
        // tar requires the end of the file have two 512 byte null blocks
        $this->send(pack('a1024', ''));
        
        // flush the data to the output
        flush();
    }
    
    /**
     * Initialize a file stream.
     *
     * @param string $name File path or just name.
     * @param int    $size Size in bytes of the file.
     * @param array  $opt  Array containing time / type (optional).
     * @param int    $meth Method of compression to use (ignored by TarArchive class).
     *
     * @return void
     */
    public function initFileStreamTransfer($name, $size, array $opt = [], $meth = null)
    {
        // try to detect the type if not provided
        $type = self::REGTYPE;
        if (isset($opt['type'])) {
            $type = $opt['type'];
        } elseif (substr($name, -1) == '/') {
            $type = self::DIRTYPE;
        }
        
        $dirname = dirname($name);
        $name    = basename($name);
        
        // Remove '.' from the current directory
        $dirname = ($dirname == '.') ? '' : $dirname;
        
        // if we're using a container directory, prepend it to the filename
        if ($this->useContainerDir) {
            // container directory will end with a '/' so ensure the lower level directory name doesn't start with one
            $dirname = $this->containerDirName . preg_replace('/^\/+/', '', $dirname);
        }
        
        // Remove trailing slash from directory name, because tar implies it.
        if (substr($dirname, -1) == '/') {
            $dirname = substr($dirname, 0, -1);
        }
        
        // handle long file names via PAX
        if (strlen($name) > 99 || strlen($dirname) > 154) {
            $pax = $this->paxGenerate([
                'path' => $dirname . '/' . $name,
            ]);
            
            $this->initFileStreamTransfer('',
                strlen($pax),
                [
                    'type' => self::XHDTYPE,
                ]);
            
            $this->streamFilePart($pax, $single_part = true);
            $this->completeFileStream();
        }
        
        // stash the file size for later use
        $this->file_size = $size;
        
        // process optional arguments
        $time = isset($opt['time']) ? $opt['time'] : time();
        
        // build data descriptor
        $fields = [
            ['a100', substr($name, 0, 100)],
            ['a8', str_pad('777', 7, '0', STR_PAD_LEFT)],
            ['a8', decoct(str_pad('0', 7, '0', STR_PAD_LEFT))],
            ['a8', decoct(str_pad('0', 7, '0', STR_PAD_LEFT))],
            ['a12', decoct(str_pad($size, 11, '0', STR_PAD_LEFT))],
            ['a12', decoct(str_pad($time, 11, '0', STR_PAD_LEFT))],
            ['a8', ''],
            ['a1', $type],
            ['a100', ''],
            ['a6', 'ustar'],
            ['a2', '00'],
            ['a32', ''],
            ['a32', ''],
            ['a8', ''],
            ['a8', ''],
            ['a155', substr($dirname, 0, 155)],
            ['a12', ''],
        ];
        
        // pack fields and calculate "total" length
        $header = $this->packFields($fields);
        
        // Compute header checksum
        $checksum = str_pad(decoct($this->computeUnsignedChecksum($header)), 6, "0", STR_PAD_LEFT);
        for ($i = 0; $i < 6; $i++) {
            $header[(148 + $i)] = substr($checksum, $i, 1);
        }
        
        $header[154] = chr(0);
        $header[155] = chr(32);
        
        // print header
        $this->send($header);
    }
    
    /**
     * Stream the next part of the current file stream.
     *
     * @param string $data        Raw data to send.
     * @param bool   $single_part Used to determin if we can compress (not used in TarArchive class).
     *
     * @return void
     */
    public function streamFilePart($data, $single_part = false)
    {
        // send data
        $this->send($data);
        
        // flush the data to the output
        flush();
    }
    
    /*******************
     * PRIVATE METHODS *
     *******************/
    
    /**
     * Generate unsigned checksum of header
     *
     * @param string $header File header.
     *
     * @return string Unsigned checksum.
     * @access private
     */
    private function computeUnsignedChecksum($header)
    {
        $unsigned_checksum = 0;
        
        for ($i = 0; $i < 512; $i++) {
            $unsigned_checksum += ord($header[$i]);
        }
        
        for ($i = 0; $i < 8; $i++) {
            $unsigned_checksum -= ord($header[148 + $i]);
        }
        
        $unsigned_checksum += ord(" ") * 8;
        
        return $unsigned_checksum;
    }
    
    /**
     * Generate a PAX string
     *
     * @param array $fields Key value mapping.
     *
     * @return string PAX formated string
     * @link http://www.freebsd.org/cgi/man.cgi?query=tar&sektion=5&manpath=FreeBSD+8-current Tar / PAX spec
     */
    private function paxGenerate(array $fields)
    {
        $lines = '';
        foreach ($fields as $name => $value) {
            // build the line and the size
            $line = ' ' . $name . '=' . $value . "\n";
            $size = strlen(strlen($line)) + strlen($line);
            
            // add the line
            $lines .= $size . $line;
        }
        
        return $lines;
    }
}
