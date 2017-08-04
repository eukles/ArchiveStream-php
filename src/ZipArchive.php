<?php

namespace Barracuda\ArchiveStream;

/**
 * Zip-formatted streaming archive.
 */
class ZipArchive extends Archive
{
    
    /**
     * Version zip was created by / must be opened by (4.5 for Zip64 support).
     */
    const VERSION = 45;
    /**
     * @var array
     */
    protected $current_file_stream = [];
    /**
     * @var
     */
    protected $hash_ctx;
    /**
     * Length of the CDR.
     *
     * @var int
     */
    private $cdr_len = 0;
    /**
     * Offset of the CDR.
     *
     * @var int
     */
    private $cdr_ofs = 0;
    /**
     * Files added to the archive, tracked for the CDR.
     *
     * @var array
     */
    private $files = [];
    /**
     * Rolling count of the file length being streamed.
     *
     * @var int
     */
    private $len = null;
    /**
     * Rolling count of the compressed file length being streamed.
     *
     * @var int
     */
    private $zlen = null;
    
    /**
     * Create a new ArchiveStream_Zip object.
     *
     * @see    ArchiveStream for documentation
     * @access public
     *
     * @param null  $name
     * @param array $opt
     * @param null  $basePath
     *
     * @throws ArchiveException
     */
    public function __construct($name = null, array $opt = [], $basePath = null)
    {
        if (class_exists("\GMP") === false) {
            throw new ArchiveException("GMP extension not loaded");
        }
        $this->opt['content_type'] = 'application/x-zip';
        parent::__construct($name, $opt, $basePath);
    }
    
    /**
     * Explicitly adds a directory to the tar (necessary for empty directories)
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
        $meth            = 0x08;
        
        if (substr($name, -1) != '/') {
            $name = $name . '/';
        }
        
        // send header
        $this->initFileStreamTransfer($name, $size = 0, $opt, $meth);
        
        // complete the file stream
        $this->completeFileStream();
    }
    
    /**
     * Complete the current file stream (zip64 format).
     *
     * @return void
     */
    public function completeFileStream()
    {
        $crc = hexdec(hash_final($this->hash_ctx));
        
        // convert the 64 bit ints to 2 32bit ints
        list($zlen_low, $zlen_high) = $this->int64Split($this->zlen);
        list($len_low, $len_high) = $this->int64Split($this->len);
        
        // build data descriptor
        $fields = [                // (from V.A of APPNOTE.TXT)
            ['V', 0x08074b50],     // data descriptor
            ['V', $crc],           // crc32 of data
            ['V', $zlen_low],      // compressed data length (low)
            ['V', $zlen_high],     // compressed data length (high)
            ['V', $len_low],       // uncompressed data length (low)
            ['V', $len_high],      // uncompressed data length (high)
        ];
        
        // pack fields and calculate "total" length
        $ret = $this->packFields($fields);
        
        // print header and filename
        $this->send($ret);
        
        // Update cdr for file record
        $this->current_file_stream[3] = $crc;
        $this->current_file_stream[4] = gmp_strval($this->zlen);
        $this->current_file_stream[5] = gmp_strval($this->len);
        $this->current_file_stream[6] += gmp_strval(gmp_add(gmp_init(strlen($ret)), $this->zlen));
        ksort($this->current_file_stream);
        
        // Add to cdr and increment offset - can't call directly because we pass an array of params
        call_user_func_array([$this, 'addToCdr'], $this->current_file_stream);
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
        
        // add trailing cdr record
        $this->addCdr($this->opt);
        $this->clear();
    }
    
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
    public function initFileStreamTransfer($name, $size, array $opt = [], $meth = 0x00)
    {
        // if we're using a container directory, prepend it to the filename
        if ($this->useContainerDir) {
            // the container directory will end with a '/' so ensure the filename doesn't start with one
            $name = $this->containerDirName . preg_replace('/^\\/+/', '', $name);
        }
        
        $algo = 'crc32b';
        
        // calculate header attributes
        $this->len      = gmp_init(0);
        $this->zlen     = gmp_init(0);
        $this->hash_ctx = hash_init($algo);
        
        // Send file header
        $this->addStreamFileHeader($name, $opt, $meth);
    }
    
    /**
     * Stream the next part of the current file stream.
     *
     * @param string $data        Raw data to send.
     * @param bool   $single_part Used to determine if we can compress.
     *
     * @return void
     */
    public function streamFilePart($data, $single_part = false)
    {
        $this->len = gmp_add(gmp_init(strlen($data)), $this->len);
        hash_update($this->hash_ctx, $data);
        
        if ($single_part === true && isset($this->methodStr) && $this->methodStr == 'deflate') {
            $data = gzdeflate($data);
        }
        
        $this->zlen = gmp_add(gmp_init(strlen($data)), $this->zlen);
        
        // send data
        $this->send($data);
        flush();
    }
    
    /*******************
     * PRIVATE METHODS *
     *******************/
    
    /**
     * Add initial headers for file stream
     *
     * @param string $name File path or just name.
     * @param array  $opt  Array containing time.
     * @param int    $meth Method of compression to use.
     *
     * @return void
     */
    protected function addStreamFileHeader($name, array $opt, $meth)
    {
        // strip leading slashes from file name
        // (fixes bug in windows archive viewer)
        $name  = preg_replace('/^\\/+/', '', $name);
        $extra = pack('vVVVV', 1, 0, 0, 0, 0);
        
        // create dos timestamp
        $opt['time'] = isset($opt['time']) ? $opt['time'] : time();
        $dts         = $this->dostime($opt['time']);
        
        // Sets bit 3, which means CRC-32, uncompressed and compresed length
        // are put in the data descriptor following the data. This gives us time
        // to figure out the correct sizes, etc.
        $genb = 0x08;
        
        if (mb_check_encoding($name, "UTF-8") && !mb_check_encoding($name, "ASCII")) {
            // Sets Bit 11: Language encoding flag (EFS).  If this bit is set,
            // the filename and comment fields for this file
            // MUST be encoded using UTF-8. (see APPENDIX D)
            $genb |= 0x0800;
        }
        
        // build file header
        $fields = [                // (from V.A of APPNOTE.TXT)
            ['V', 0x04034b50],     // local file header signature
            ['v', self::VERSION],  // version needed to extract
            ['v', $genb],          // general purpose bit flag
            ['v', $meth],          // compresion method (deflate or store)
            ['V', $dts],           // dos timestamp
            ['V', 0x00],           // crc32 of data (0x00 because bit 3 set in $genb)
            ['V', 0xFFFFFFFF],     // compressed data length
            ['V', 0xFFFFFFFF],     // uncompressed data length
            ['v', strlen($name)],  // filename length
            ['v', strlen($extra)], // extra data len
        ];
        
        // pack fields and calculate "total" length
        $ret = $this->packFields($fields);
        
        // print header and filename
        $this->send($ret . $name . $extra);
        
        // Keep track of data for central directory record
        $this->current_file_stream = [
            $name,
            $opt,
            $meth,
            // 3-5 will be filled in by complete_file_stream()
            6 => (strlen($ret) + strlen($name) + strlen($extra)),
            7 => $genb,
            8 => substr($name, -1) == '/' ? 0x10 : 0x20, // 0x10 for directory, 0x20 for file
        ];
    }
    
    /**
     * Convert a UNIX timestamp to a DOS timestamp.
     *
     * @param int $when Unix timestamp.
     *
     * @return string DOS timestamp
     */
    protected function dostime($when = 0)
    {
        // get date array for timestamp
        $d = getdate($when);
        
        // set lower-bound on dates
        if ($d['year'] < 1980) {
            $d = [
                'year'    => 1980,
                'mon'     => 1,
                'mday'    => 1,
                'hours'   => 0,
                'minutes' => 0,
                'seconds' => 0,
            ];
        }
        
        // remove extra years from 1980
        $d['year'] -= 1980;
        
        // return date string
        return ($d['year'] << 25) | ($d['mon'] << 21) | ($d['mday'] << 16) |
            ($d['hours'] << 11) | ($d['minutes'] << 5) | ($d['seconds'] >> 1);
    }
    
    /**
     * Split a 64-bit integer to two 32-bit integers.
     *
     * @param mixed $value Integer or GMP resource.
     *
     * @return array Containing high and low 32-bit integers.
     */
    protected function int64Split($value)
    {
        // gmp
        if (is_resource($value) || $value instanceof \GMP) {
            $hex = str_pad(gmp_strval($value, 16), 16, '0', STR_PAD_LEFT);
            
            $high = $this->gmpConvert(substr($hex, 0, 8), 16, 10);
            $low  = $this->gmpConvert(substr($hex, 8, 8), 16, 10);
        } // int
        else {
            $left  = 0xffffffff00000000;
            $right = 0x00000000ffffffff;
            
            $high = ($value & $left) >> 32;
            $low  = $value & $right;
        }
        
        return [$low, $high];
    }
    
    /**
     * Add CDR (Central Directory Record) footer.
     *
     * @param array $opt Options array that may contain a comment.
     *
     * @return void
     */
    private function addCdr(array $opt = null)
    {
        foreach ($this->files as $file) {
            $this->addCdrFile($file);
        }
        
        $this->addCdrEofZip64();
        $this->addCdrEofLocatorZip64();
        
        $this->addCdrEof($opt);
    }
    
    /**
     * Send CDR EOF (Central Directory Record End-of-File) record. Most values
     * point to the corresponding values in the ZIP64 CDR. The optional comment
     * still goes in this CDR however.
     *
     * @param array $opt Options array that may contain a comment.
     *
     * @return void
     */
    private function addCdrEof(array $opt = null)
    {
        // grab comment (if specified)
        $comment = '';
        if ($opt && isset($opt['comment'])) {
            $comment = $opt['comment'];
        }
        
        $fields = [                    // (from V,F of APPNOTE.TXT)
            ['V', 0x06054b50],         // end of central file header signature
            ['v', 0xFFFF],             // this disk number (0xFFFF to look in zip64 cdr)
            ['v', 0xFFFF],             // number of disk with cdr (0xFFFF to look in zip64 cdr)
            ['v', 0xFFFF],             // number of entries in the cdr on this disk (0xFFFF to look in zip64 cdr))
            ['v', 0xFFFF],             // number of entries in the cdr (0xFFFF to look in zip64 cdr)
            ['V', 0xFFFFFFFF],         // cdr size (0xFFFFFFFF to look in zip64 cdr)
            ['V', 0xFFFFFFFF],         // cdr offset (0xFFFFFFFF to look in zip64 cdr)
            ['v', strlen($comment)],   // zip file comment length
        ];
        
        $ret = $this->packFields($fields) . $comment;
        $this->send($ret);
    }
    
    /**
     * Add location record for ZIP64 central directory
     *
     * @return void
     */
    private function addCdrEofLocatorZip64()
    {
        list($cdr_ofs_low, $cdr_ofs_high) = $this->int64Split($this->cdr_len + $this->cdr_ofs);
        
        $fields = [                    // (from V,F of APPNOTE.TXT)
            ['V', 0x07064b50],         // zip64 end of central dir locator signature
            ['V', 0],                  // this disk number
            ['V', $cdr_ofs_low],       // cdr ofs (low)
            ['V', $cdr_ofs_high],      // cdr ofs (high)
            ['V', 1],                  // total number of disks
        ];
        
        $ret = $this->packFields($fields);
        $this->send($ret);
    }
    
    /**
     * Adds Zip64 end of central directory record.
     *
     * @return void
     */
    private function addCdrEofZip64()
    {
        $num = count($this->files);
        
        list($num_low, $num_high) = $this->int64Split($num);
        list($cdr_len_low, $cdr_len_high) = $this->int64Split($this->cdr_len);
        list($cdr_ofs_low, $cdr_ofs_high) = $this->int64Split($this->cdr_ofs);
        
        $fields = [                    // (from V,F of APPNOTE.TXT)
            ['V', 0x06064b50],         // zip64 end of central directory signature
            ['V', 44],                 // size of zip64 end of central directory record (low) minus 12 bytes
            ['V', 0],                  // size of zip64 end of central directory record (high)
            ['v', self::VERSION],      // version made by
            ['v', self::VERSION],      // version needed to extract
            ['V', 0x0000],             // this disk number (only one disk)
            ['V', 0x0000],             // number of disk with central dir
            ['V', $num_low],           // number of entries in the cdr for this disk (low)
            ['V', $num_high],          // number of entries in the cdr for this disk (high)
            ['V', $num_low],           // number of entries in the cdr (low)
            ['V', $num_high],          // number of entries in the cdr (high)
            ['V', $cdr_len_low],       // cdr size (low)
            ['V', $cdr_len_high],      // cdr size (high)
            ['V', $cdr_ofs_low],       // cdr ofs (low)
            ['V', $cdr_ofs_high],      // cdr ofs (high)
        ];
        
        $ret = $this->packFields($fields);
        $this->send($ret);
    }
    
    /**
     * Send CDR record for specified file (Zip64 format).
     *
     * @see addToCdr() for options to pass in $args.
     *
     * @param array $args Array of argumentss.
     *
     * @return void
     */
    private function addCdrFile(array $args)
    {
        list($name, $opt, $meth, $crc, $zlen, $len, $ofs, $genb, $file_attribute) = $args;
        
        // convert the 64 bit ints to 2 32bit ints
        list($zlen_low, $zlen_high) = $this->int64Split($zlen);
        list($len_low, $len_high) = $this->int64Split($len);
        list($ofs_low, $ofs_high) = $this->int64Split($ofs);
        
        // ZIP64, necessary for files over 4GB (incl. entire archive size)
        $extra_zip64 = '';
        $extra_zip64 .= pack('VV', $len_low, $len_high);
        $extra_zip64 .= pack('VV', $zlen_low, $zlen_high);
        $extra_zip64 .= pack('VV', $ofs_low, $ofs_high);
        
        $extra = pack('vv', 1, strlen($extra_zip64)) . $extra_zip64;
        
        // get attributes
        $comment = isset($opt['comment']) ? $opt['comment'] : '';
        
        // get dos timestamp
        $dts = $this->dostime($opt['time']);
        
        $fields = [                      // (from V,F of APPNOTE.TXT)
            ['V', 0x02014b50],           // central file header signature
            ['v', self::VERSION],        // version made by
            ['v', self::VERSION],        // version needed to extract
            ['v', $genb],                // general purpose bit flag
            ['v', $meth],                // compresion method (deflate or store)
            ['V', $dts],                 // dos timestamp
            ['V', $crc],                 // crc32 of data
            ['V', 0xFFFFFFFF],           // compressed data length (zip64 - look in extra)
            ['V', 0xFFFFFFFF],           // uncompressed data length (zip64 - look in extra)
            ['v', strlen($name)],        // filename length
            ['v', strlen($extra)],       // extra data len
            ['v', strlen($comment)],     // file comment length
            ['v', 0],                    // disk number start
            ['v', 0],                    // internal file attributes
            ['V', $file_attribute],      // external file attributes, 0x10 for dir, 0x20 for file
            ['V', 0xFFFFFFFF],           // relative offset of local header (zip64 - look in extra)
        ];
        
        // pack fields, then append name and comment
        $ret = $this->packFields($fields) . $name . $extra . $comment;
        
        $this->send($ret);
        
        // increment cdr length
        $this->cdr_len += strlen($ret);
    }
    
    /**
     * Save file attributes for trailing CDR record.
     *
     * @param string $name    Path / name of the file.
     * @param array  $opt     Array containing time.
     * @param int    $meth    Method of compression to use.
     * @param string $crc     Computed checksum of the file.
     * @param int    $zlen    Compressed size.
     * @param int    $len     Uncompressed size.
     * @param int    $rec_len Size of the record.
     * @param int    $genb    General purpose bit flag.
     * @param int    $fattr   File attribute bit flag.
     *
     * @return void
     */
    private function addToCdr($name, array $opt, $meth, $crc, $zlen, $len, $rec_len, $genb = 0, $fattr = 0x20)
    {
        $this->files[] = [$name, $opt, $meth, $crc, $zlen, $len, $this->cdr_ofs, $genb, $fattr];
        $this->cdr_ofs += $rec_len;
    }
    
    /**
     * Clear all internal variables.
     *
     * Note: the archive object is unusable after this.
     *
     * @return void
     */
    private function clear()
    {
        $this->files   = [];
        $this->cdr_ofs = 0;
        $this->cdr_len = 0;
        $this->opt     = [];
    }
    
    /**
     * Convert a number between bases via GMP.
     *
     * @param int $num    Number to convert.
     * @param int $base_a Base to convert from.
     * @param int $base_b Base to convert to.
     *
     * @return string Number in string format.
     * @throws ArchiveException
     */
    private function gmpConvert($num, $base_a, $base_b)
    {
        $gmp_num = gmp_init($num, $base_a);
        
        if (!(is_resource($gmp_num) || $gmp_num instanceof \GMP)) {
            throw new ArchiveException("gmp_convert could not convert [$num] from base [$base_a] to base [$base_b]");
        }
        
        return gmp_strval($gmp_num, $base_b);
    }
}
