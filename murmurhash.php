<?php

function murmurhash($key,$seed = 0) {
	$m = 0x5bd1e995;
	$r = 24;
	$len = strlen($key);
	$h = $seed ^ $len;
	$o = 0;
		
	while($len >= 4) {
		$k = ord($key[$o]) | (ord($key[$o+1]) << 8) | (ord($key[$o+2]) << 16) | (ord($key[$o+3]) << 24);
		$k = ($k * $m) & 4294967295;
		$k = ($k ^ ($k >> $r)) & 4294967295;
		$k = ($k * $m) & 4294967295;

		$h = ($h * $m) & 4294967295;
		$h = ($h ^ $k) & 4294967295;

		$o += 4;
		$len -= 4;
	}
 
	$data = substr($key,0 - $len,$len);
	
	switch($len) {
		case 3: $h = ($h ^ (ord($data[2]) << 16)) & 4294967295;
		case 2: $h = ($h ^ (ord($data[1]) << 8)) & 4294967295;
		case 1: $h = ($h ^ (ord($data[0]))) & 4294967295;
		$h = ($h * $m) & 4294967295;
	};
	$h = ($h ^ ($h >> 13)) & 4294967295;
	$h = ($h * $m) & 4294967295;
	$h = ($h ^ ($h >> 15)) & 4294967295;

	return $h;
}

?>
