<?php
declare(strict_types=1);
umask(0077);
require __DIR__.'/app/bootstrap.php';
try{
    security_headers();$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
    if($path==='/health'){header('Content-Type: application/json');$c=local_config();app()->db->query('SELECT 1');echo json_encode(['ok'=>true,'provider'=>$c['provider'],'live_enabled'=>live_ready($c),'php'=>PHP_VERSION,'database'=>app()->db->getAttribute(PDO::ATTR_DRIVER_NAME)]);return;}
    if($path==='/media/tutorial.mp4'){require_once __DIR__.'/app/Media.php';Recharge\Media::video(__DIR__.'/public/media/tutorial.mp4');return;}
    if($path==='/media/tutorial-poster.jpg'){header('Content-Type: image/jpeg');readfile(__DIR__.'/public/media/tutorial-poster.jpg');return;}
    $routes=['/'=>'index.php','/index.php'=>'index.php','/api.php'=>'api.php','/admin'=>'admin.php','/admin.php'=>'admin.php'];
    if(isset($routes[$path])){require __DIR__.'/public/'.$routes[$path];return;}
    if(preg_match('~^/assets/([a-z0-9-]+\.(css|js|svg))$~D',$path,$m)&&is_file(__DIR__.'/public/assets/'.$m[1])){
        header('Content-Type: '.['css'=>'text/css; charset=utf-8','js'=>'text/javascript; charset=utf-8','svg'=>'image/svg+xml'][$m[2]]);readfile(__DIR__.'/public/assets/'.$m[1]);return;
    }
    http_response_code(404);echo '页面不存在';
}catch(\Recharge\Fault $e){http_response_code($e->status);header('Content-Type: application/json; charset=utf-8');echo json_encode(['error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
catch(Throwable){http_response_code(500);header('Content-Type: application/json; charset=utf-8');echo json_encode(['error'=>'服务暂时异常，请查询原订单'],JSON_UNESCAPED_UNICODE);}
