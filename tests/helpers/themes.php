<?php
namespace Dashless\Site;
define('ABSPATH',__DIR__);
function get_bloginfo($key) { return $key==='name'?'My site':'My description'; }
function sanitize_text_field($s) { return strip_tags($s); }
function plugins_url($path,$file) { return 'https://example.test/wp-content/plugins/dashless-site/hosted/'.$path; }
class Failure extends \RuntimeException { public function __construct(public string $slug,string $message,int $status=400) { parent::__construct($message); } }
class Support { public static function json($path) { return json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR); } }
class Store {
    public array $records=[];
    public function get($kind,$key) { return $this->records[$kind][$key]??null; }
    public function put($kind,$key,$data,$owner) { $this->records[$kind][$key]=['data'=>$data]; }
}
class App { public Store $store; public function __construct() { $this->store=new Store(); } }
require __DIR__.'/../../wordpress/hosted/Content.php';
function check($value) { if (!$value) throw new \RuntimeException('Theme assertion failed'); }
function rejects($fn,$slug) { try { $fn(); } catch(Failure $e) { check($e->slug===$slug);return; }throw new \RuntimeException('Expected '.$slug); }
$app=new App();$content=new Content($app);
$before=$content->design();$before['logo_media_id']=42;$before['navigation']=[['page_id'=>7,'label'=>'About']];$app->store->put('design','current',$before,1);
check(in_array('bulletin',array_column($content->tool('list_themes',[],1)['themes'],'id'),true));
check(str_ends_with($content->tool('get_theme',['theme_id'=>'field-notes'],1)['preview_image'],'field-notes.png'));
$apply=fn($v,$changes)=>$content->tool('update_design',['expected_version'=>$v,'changes'=>$changes],1);
$r=$apply(0,['theme_id'=>'field-notes','theme_version'=>1,'palette'=>'lilac']);
check($r['deployed']===false && $r['design']['version']===1);
check($r['design']['layout']==='minimal' && $r['design']['palette']==='lilac');
foreach(['site_title','description','logo_media_id','navigation'] as $key) check($r['design'][$key]===$before[$key]);
$r=$apply(1,['typography'=>'modern']);check($r['design']['theme_id']==='field-notes' && $r['design']['palette']==='lilac');
rejects(fn()=>$apply(0,['palette'=>'paper']),'stale_design');
rejects(fn()=>$apply(2,['theme_id'=>'missing','theme_version'=>1]),'theme_missing');
rejects(fn()=>$apply(2,['theme_id'=>'after-hours','theme_version'=>999]),'theme_version');
rejects(fn()=>$apply(2,['theme_id'=>'after-hours']),'theme_version');
check($content->design()['version']===2);
$r=$apply(2,['theme_id'=>'after-hours','theme_version'=>1]);check($r['design']['palette']==='night' && $r['design']['layout']==='magazine');
$r=$apply(3,['theme_id'=>'bulletin','theme_version'=>1]);check($r['design']['theme_id']==='bulletin' && $r['design']['palette']==='paper');
echo "Theme selection passed\n";
