<?php
namespace Dashless\Hub;

final class ChatgptUploads {
    public const MAX_BYTES=8388608;
    public static function validateUrl(string $url): void {
        $parts=parse_url($url);$host=$parts['host']??'';
        $origins=array_filter(array_map('trim',explode(',',(string)Config::get('chatgpt_file_origins'))));
        // No blanket *.usercontent allowlist. Operators pin observed official transfer origins after a real connection.
        if(!$parts || ($parts['scheme']??'')!=='https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment']) || !preg_match('/(?:^|\.)oaiusercontent\.com$/D',$host) || !in_array('https://'.$host,$origins,true))throw new Failure('file_origin_invalid','Select an image through ChatGPT. This transfer origin is not enabled.',400);
    }
    public static function inspect(string $bytes,?string $declared=null): array {
        if(strlen($bytes)>self::MAX_BYTES || $bytes==='')throw new Failure('file_size_invalid','Choose an image no larger than 8 MiB.');
        $info=@getimagesizefromstring($bytes);$mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if(!$info || !in_array($mime,['image/jpeg','image/png','image/webp'],true) || ($info['mime']??'')!==$mime || ($declared && $declared!==$mime))throw new Failure('file_type_invalid','Choose a PNG, JPEG, or WebP image that matches its declared type.');
        if($info[0]<1 || $info[1]<1 || $info[0]*$info[1]>40000000)throw new Failure('image_dimensions_invalid','Choose an image with at most 40 million pixels.');
        return ['mime_type'=>$mime,'size_bytes'=>strlen($bytes),'sha256'=>hash('sha256',$bytes)];
    }
    public static function relay(array $a,string $id,string $bytes,string $mime): array {
        Chatgpt::uuid($id);
        if(($a['domain']??'')!==Identity::slug($a['slug']).'.'.Config::domain())throw new Failure('site_invalid','Site routing is invalid.',403);
        $response=wp_remote_request('https://'.$a['domain'].'/wp-json/dashless-hosted/v1/uploads/'.$id.'/content',[
            'method'=>'PUT','timeout'=>12,'redirection'=>0,'body'=>$bytes,'headers'=>[
                'Authorization'=>'Bearer '.Crypto::open($a['site_secret']),'X-Dashless-Contract'=>'1',
                'X-Dashless-Account-Id'=>(string)$a['user_id'],'Content-Type'=>$mime,'Cache-Control'=>'no-store',
            ],
        ]);
        if(is_wp_error($response))throw new Failure('upload_unavailable','The transfer could not be confirmed. Retry the same upload.',503);
        $code=wp_remote_retrieve_response_code($response);$result=json_decode(wp_remote_retrieve_body($response),true);
        if($code<200 || $code>=300 || !is_array($result))throw new Failure('upload_failed','The site could not complete the transfer. Retry the same upload.',503);
        if(($result['contract_version']??null)!==1 || ($result['site_id']??null)!==$a['site_id'])throw new Failure('upload_owner_mismatch','The transfer could not be verified.',403);
        return $result;
    }
    public static function run(Store $store,Agent $agent,array $a,array $args): array {
        Chatgpt::capabilities($agent,$a,['chatgpt_upload_v1']);
        $f=$args['file'];self::validateUrl($f['download_url']);$owner=(int)$a['user_id'];
        $key=$owner.':'.$args['client_key'];
        $binding=hash('sha256',wp_json_encode([$a['site_id'],$f['file_id'],$f['file_name']??'image',$f['mime_type']??null,$args['alt_text']]));
        return $store->locked('chatgpt-upload:'.$key,function()use($store,$agent,$a,$args,$f,$owner,$key,$binding){
            $record=$store->get('consent','upload:'.$key);
            if($record && ($record['owner']!==$owner || $record['data']['binding']!==$binding))throw new Failure('upload_conflict','Use a new upload key for a different image.',409);
            if($record && $record['expires']<=time())throw new Failure('upload_expired','This upload expired. Select the file again with a new upload key.',410);
            if($record && $record['data']['epoch']!==Chatgpt::epoch($store,$owner))throw new Failure('upload_disconnected','Select the file again after reconnecting.',403);
            if($record && $record['status']==='complete')return $record['data']['result'];
            $limit=$record['data']['max_bytes']??self::MAX_BYTES;
            // Safe HTTP rejects private network destinations. Never follow redirects or forward OAuth/site credentials.
            $response=wp_safe_remote_get($f['download_url'],['timeout'=>12,'redirection'=>0,'limit_response_size'=>$limit+1,'headers'=>['Accept'=>'image/png, image/jpeg, image/webp']]);
            if(is_wp_error($response) || wp_remote_retrieve_response_code($response)!==200)throw new Failure('file_unavailable','The temporary file link expired or could not be downloaded. Select the file again.',409);
            $bytes=wp_remote_retrieve_body($response);$info=self::inspect($bytes,$f['mime_type']??null);
            if(!$record) {
                $extension=match($info['mime_type']){'image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'};
                $filename=sanitize_file_name($f['file_name']??('image.'.$extension));
                if(!preg_match('/\.(png|jpe?g|webp)$/iD',$filename))throw new Failure('file_name_invalid','Choose an image with a PNG, JPEG, or WebP filename.');
                $session=$agent->call($a,'POST','/tools/create_media_upload',['actor'=>['account_id'=>$owner],'arguments'=>['filename'=>$filename,'alt_text'=>$args['alt_text'],'client_key'=>$args['client_key']],'idempotency_key'=>$args['client_key']]);
                $id=Chatgpt::uuid((string)($session['upload_id']??''));$expires=$session['expires_at']??0;
                if(is_string($expires))$expires=strtotime($expires);
                if(!is_int($expires) || $expires<=time() || $expires>time()+900 || !is_int($session['max_bytes']??null) || $session['max_bytes']<1 || ($session['transfer']??'')!=='hub_binary_relay')throw new Failure('upload_contract_invalid','The site returned an unsupported upload session.',503);
                $data=['binding'=>$binding,'epoch'=>Chatgpt::epoch($store,$owner),'upload_id'=>$id,'max_bytes'=>min(self::MAX_BYTES,$session['max_bytes'])];
                $store->put('consent','upload:'.$key,$data,$owner,'pending',$expires);$record=$store->get('consent','upload:'.$key);
            }
            if($info['size_bytes']>$record['data']['max_bytes'])throw new Failure('file_size_invalid','The image exceeds this upload session limit.');
            if($record['expires']<=time())throw new Failure('upload_expired','This upload expired. Select the file again.',410);
            $data=$record['data'];
            if(isset($data['sha256']) && $data['sha256']!==$info['sha256'])throw new Failure('upload_conflict','The file changed during transfer. Select it again with a new key.',409);
            $data['sha256']=$info['sha256'];$store->put('consent','upload:'.$key,$data,$owner,'pending',$record['expires']);
            $result=self::relay($a,$data['upload_id'],$bytes,$info['mime_type']);
            if(!is_int($result['media_id']??null) || $result['media_id']<1)throw new Failure('upload_incomplete','The site has not confirmed this upload. Retry with the same key.',503);
            $safe=['media_id'=>$result['media_id'],'uploaded'=>true,'mime_type'=>$info['mime_type'],'size_bytes'=>$info['size_bytes']];
            $data['result']=$safe;$store->put('consent','upload:'.$key,$data,$owner,'complete',$record['expires']);return $safe;
        },90);
    }
}
