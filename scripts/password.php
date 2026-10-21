<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$password=rtrim(stream_get_contents(STDIN),"\r\n");
if(strlen($password)<16){fwrite(STDERR,"请通过标准输入提供至少16个字符的管理员密码\n");exit(1);}
echo password_hash($password,PASSWORD_DEFAULT)."\n";
