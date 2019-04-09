<?php

if(!$argv[1]) {
    echo "
Using: php fw_reader.php <rom file/unpacked rom folder> [option]

Options:
compact: using for compact packing rom file, ignoring block size in headers.dat file. 
	 In headers.dat need write real size of firmware file.\n
";

    die;
}

$file = $argv[1];
if(!file_exists($file)) die("File/directory not found\n");

if(in_array($argv[2], ['compact'])) $option = $argv[2];


$fwtools = new fwtools($file, $option);

class fwtools {

    private $fhandle;
    private $mode;
    private $dir;
    private $error;
    private $option;

    public function __construct($file, $option='') {
	if($option) $this->option = $option;
	if(is_file($file)) {
	    $this->fhandle = fopen($file, 'r');
	    $this->mode = 'unpack';

	    //Create dir (if not exists)
	    $this->dir = '_'.$file;
	    if(!is_dir($this->dir)) {
	        if(!mkdir($this->dir)) die("Error creating directory ".$this->dir."\n");
	    }
	    $this->rom_unpack();
	    if($this->error) echo $this->error;

	}
	elseif(is_dir($file)) {
	    $this->mode = 'pack';
	    $this->dir = $file;
	    $this->rom_pack();
	    if($this->error) echo $this->error;
	}
	else return false;
    }

    //read headers
    private function read_rom_headers() {
	fseek($this->fhandle, 0);
	$rom_name = trim(fread($this->fhandle, 8));
	$addr = unpack('l', fread($this->fhandle, 4))[1];
	$size = unpack('l', fread($this->fhandle, 4))[1];
	$fstat = fstat($this->fhandle);
	$headers['info'] = ['rom_name'=>$rom_name, 'addr'=>$addr, 'size'=>$size, 'rom_size'=>$fstat['size']];

	do {
	    fread($this->fhandle, 4);
	    $addr = unpack('l', fread($this->fhandle, 4))[1];
	    $size = unpack('l', fread($this->fhandle, 4))[1];
	    $name = trim(fread($this->fhandle, 8));
	    if($name) $headers['rom'][] = ['name'=>$name,'addr'=>$addr, 'size'=>$size];
	} while($name);

	fseek($this->fhandle, 220);
	$head_end = base64_encode(fread($this->fhandle, 36));
	$headers['info']['head_end'] = $head_end;
	return $headers;
    }

    private function rom_unpack() {
	$headers = $this->read_rom_headers();
	foreach($headers['rom'] as $item) {
	    $fout = fopen($this->dir.'/'.$item['name'].'.bin', 'w') or die('Error write in '.$this->dir.'/'.$item['name'].".bin\n");

	    //Search real block size
	    $length = 100;
	    $seek = $item['addr']+$item['size']-$length;
	    fseek($this->fhandle, $seek);
	    while(true) {
		fseek($this->fhandle, $seek);
		$str = fread($this->fhandle, $length);
		if($str!=str_pad('', $length, hex2bin('ff'))) {
		    if($length>1) {$seek += $length; $length=1;}
		    else break;
		}
		$seek -= $length;
	    }
	    $real_size = ftell($this->fhandle)-$item['addr'];

	    fseek($this->fhandle, $item['addr']);
	    $count = 0;
	    while($count<$real_size) {
		if($real_size-$count<4096) $rcount = $real_size-$count;
		else $rcount = 4096;

		fwrite($fout, fread($this->fhandle, $rcount));
		$count += $rcount;
	    }
	    fclose($fout);
	}

	//extract SPI? boot block
	$fout = fopen($this->dir.'/SPI.bin', 'w');
	fseek($this->fhandle, 4096);
	$pattern = str_pad($pattern, 100, hex2bin('ff'));
	do {
    	    $str = fread($this->fhandle, 100);
	    if($str!=$pattern) fwrite($fout, $str);
	} while($str!=$pattern);
	fclose($fout);

	//Save headers
	$fout = fopen($this->dir.'/headers.dat', 'w');

	fwrite($fout, $headers['info']['rom_name'].' '.$headers['info']['rom_size'].' '.$headers['info']['head_end']."\n");
	foreach($headers['rom'] as $item) {
	    fwrite($fout, $item['name'].' '.$item['addr'].' '.$item['size']."\n");
	}
	fclose($fout);
    }

    private function read_file_headers($compact=0) {
	$file = file_get_contents($this->dir.'/headers.dat');
	$ex = explode("\n", $file);
	$str = array_shift($ex);
	$ainfo = explode(' ', $str);
	$headers['info'] = ['rom_name'=>$ainfo[0], 'rom_size'=>$ainfo[1], 'head_end'=>$ainfo[2]];

	$addr = 65536; //start address for compact method;
	foreach($ex as $item) {
	    if(!trim($item)) continue;
	    $arom = explode(' ', $item);
	    if($compact) {
		$fsize = filesize($this->dir.'/'.$arom[0].'.bin');
		if($addr+$fsize>$headers['info']['rom_size']) {$this->error = "Not enough allocated memory for ".$arom[0]." block\n"; return false;}
		$next_addr = ceil(($addr+$fsize)/65536)*65536;

		$headers['rom'][] = ['name'=>$arom[0], 'addr'=> $addr, 'size'=>($next_addr-$addr)];
		$addr = $next_addr;
	    }
	    else $headers['rom'][] = ['name'=>$arom[0], 'addr'=> $arom[1], 'size'=>$arom[2]];
	}
	return $headers;
    }

    private function rom_pack() {
	$compact = ($this->option=='compact')?1:0;
	$headers = $this->read_file_headers($compact);

	$fout = fopen('pack'.$this->dir, 'w');

	//write headers
	$rom_name = str_pad($headers['info']['rom_name'], 8, hex2bin('00'));
	fwrite($fout, $rom_name);

	fwrite($fout, pack('l', $headers['rom'][0]['addr']).pack('l', $headers['rom'][0]['size']).pack('l', 0));
	foreach($headers['rom'] as $item) {
	    $str = pack('l', $item['addr']).pack('l', $item['size']).str_pad($item['name'], 8, hex2bin('00')).pack('l', 0);
	    fwrite($fout, $str);
	}

	fseek($fout, 220);
	fwrite($fout, base64_decode($headers['info']['head_end']));

	//write SPI? boot block
	$str = file_get_contents($this->dir.'/SPI.bin');
	$fill = str_pad('', 4096-ftell($fout), hex2bin('ff'));
	fwrite($fout, $fill);
	fwrite($fout, $str);

	foreach($headers['rom'] as $key=>$item) {
	    $file = $this->dir.'/'.$item['name'].'.bin';

	    //Check if block bigger than allocated memory
	    $next_addr = $headers['rom'][$key+1]['addr']?$headers['rom'][$key+1]['addr']:$headers['info']['rom_size'];
	    if($item['size'] > $next_addr-$item['addr']) {
		$this->error = 'Not enough memory for block '.$item['name'].". Try using 'compact' option.\n";
		return false;
	    }

	    $f = fopen($file, 'r') or die('error open file '.$file);
	    $fill = str_pad('', $item['addr']-ftell($fout), hex2bin('ff'));
	    fwrite($fout, $fill);
	    while(!feof($f)) {
		$str = fread($f, 4096);
		fwrite($fout, $str);
	    }
	    fclose($f);
	}
	$fill = str_pad('', $headers['info']['rom_size']-ftell($fout), hex2bin('ff'));
	fwrite($fout, $fill);
	fclose($fout);
    }

}
