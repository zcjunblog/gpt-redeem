<?php
declare(strict_types=1);
namespace Recharge;

final class Media {
    public static function video(string $path):void {
        if(!in_array($_SERVER['REQUEST_METHOD'],['GET','HEAD'],true))throw new Fault('只接受GET或HEAD',405);
        if(!is_file($path))throw new Fault('教程视频尚未上传',404);
        $size=filesize($path);$start=0;$end=$size-1;$range=$_SERVER['HTTP_RANGE']??'';
        header('Content-Type: video/mp4');header('Accept-Ranges: bytes');header('Cache-Control: public, max-age=86400');
        if($range!==''){
            if(!preg_match('/^bytes=(\d*)-(\d*)$/D',$range,$m)||($m[1]===''&&$m[2]==='')){self::unsatisfied($size);return;}
            if($m[1]===''){$suffix=(int)$m[2];if($suffix<1){self::unsatisfied($size);return;}$start=max(0,$size-$suffix);}
            else{$start=(int)$m[1];if($m[2]!=='')$end=min($end,(int)$m[2]);}
            if($start>=$size||$start>$end){self::unsatisfied($size);return;}
            http_response_code(206);header("Content-Range: bytes $start-$end/$size");
        }
        $remaining=$end-$start+1;header('Content-Length: '.$remaining);
        if($_SERVER['REQUEST_METHOD']==='HEAD')return;
        $file=fopen($path,'rb');fseek($file,$start);
        while($remaining>0&&!feof($file)){$chunk=fread($file,min(65536,$remaining));echo $chunk;$remaining-=strlen($chunk);}
        fclose($file);
    }
    private static function unsatisfied(int $size):void {http_response_code(416);header('Content-Range: bytes */'.$size);header('Content-Length: 0');}
}
