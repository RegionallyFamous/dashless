<?php
namespace Dashless\Site;
if (!defined('ABSPATH')) { exit; }

final class Content {
    public function __construct(private App $app) {}
    public function generation(): int { return $this->app->store->get('generation','content')['revision']??0; }
    public function changed(): void {
        if (!$this->app->config()) { return; }
        global $wpdb;$key='generation:'.hash('sha256','content');
        if (!$this->app->store->add('generation','content',[])) {
            $wpdb->query($wpdb->prepare("UPDATE {$this->app->store->table()} SET revision=revision+1 WHERE record_key=%s",$key));
        }
    }
    public function design(): array {
        return $this->app->store->get('design','current')['data']??['version'=>0,'palette'=>'paper','typography'=>'editorial','layout'=>'journal','site_title'=>get_bloginfo('name'),'description'=>get_bloginfo('description'),'logo_media_id'=>0,'navigation'=>[]];
    }
    public function themes(): array {
        return Support::json(is_file(__DIR__.'/themes.json') ? __DIR__.'/themes.json' : dirname(__DIR__,2).'/templates/astro/src/lib/themes.json');
    }
    public function theme(string $id): array {
        foreach ($this->themes() as $theme) { if ($theme['id']===$id) { return $theme; } }
        throw new Failure('theme_missing','That theme is not available. Use list_themes.',404);
    }
    public function themePreview(array $theme): array {
        $theme['preview_image']=plugins_url('theme-previews/'.$theme['preview_image'],__FILE__);
        $theme['preview_note']='Illustrative sample content. Use create_preview to see your own site before publishing.';
        return $theme;
    }
    public function post(string $type,int $id): array {
        $p=get_post($id);
        if (!$p || !in_array($type,['post','page'],true) || $p->post_type!==$type || $p->post_status==='trash') { throw new Failure('post_missing','The WordPress item was not found.',404); }
        $out=['id'=>$id,'post_type'=>$type,'status'=>$p->post_status,'modified_gmt'=>$p->post_modified_gmt,'date_gmt'=>$p->post_date_gmt,'slug'=>$p->post_name,'title'=>$p->post_title,'content'=>$p->post_content,'excerpt'=>$p->post_excerpt,'featured_media'=>(int)get_post_thumbnail_id($id),'parent'=>(int)$p->post_parent,'menu_order'=>(int)$p->menu_order,'categories'=>[],'tags'=>[]];
        if (!$out['slug']) { $out['slug']=wp_unique_post_slug(sanitize_title($p->post_title)?:$type.'-'.$id,$id,'publish',$type,$p->post_parent); }
        if ($out['date_gmt']==='0000-00-00 00:00:00') { $out['date_gmt']=get_gmt_from_date($p->post_date); }
        if ($type==='post') { foreach (['categories'=>'category','tags'=>'post_tag'] as $k=>$tax) { $out[$k]=array_map('intval',wp_get_object_terms($id,$tax,['fields'=>'ids']));sort($out[$k]); } }
        $out['digest']=Support::hash($out);return $out;
    }
    private function current(array $a): array {
        $p=$this->post($a['post_type'],(int)$a['id']);
        if (($a['expected_modified_gmt']??$p['modified_gmt'])!==$p['modified_gmt']) { throw new Failure('stale_post','This item changed. Read its current version before editing.'); }
        return $p;
    }
    public function clean(array $a): array {
        $out=[];
        foreach (['title','content','excerpt','slug','featured_media','parent','menu_order','categories','tags'] as $key) {
            if (!array_key_exists($key,$a)) { continue; }
            $v=$a[$key];
            $out[$key]=match($key) {'content','excerpt'=>wp_kses_post($v),'title'=>sanitize_text_field($v),'slug'=>sanitize_title($v),'categories','tags'=>array_values(array_unique(array_map('intval',$v))),default=>(int)$v};
        }
        if (!empty($out['featured_media'])) { $this->media($out['featured_media']); }
        if (!empty($out['parent'])) { $this->post('page',$out['parent']); }
        foreach (['categories'=>'category','tags'=>'post_tag'] as $key=>$tax) { foreach ($out[$key]??[] as $id) { if (!term_exists($id,$tax)) { throw new Failure('term_missing','A selected term does not exist.',400); } } }
        return $out;
    }
    public function save(string $type,array $fields,int $id=0,string $status='draft'): array {
        $p=['post_type'=>$type,'post_status'=>$status,'post_author'=>$this->app->config()['service_user']];
        if ($id) { $p['ID']=$id; }
        foreach (['title'=>'post_title','content'=>'post_content','excerpt'=>'post_excerpt','slug'=>'post_name','parent'=>'post_parent','menu_order'=>'menu_order'] as $key=>$field) { if (array_key_exists($key,$fields)) { $p[$field]=$fields[$key]; } }
        $result=$id?wp_update_post(wp_slash($p),true):wp_insert_post(wp_slash($p),true);
        if (is_wp_error($result)) { throw new Failure('save_failed','WordPress could not save this item.',503); }
        if (array_key_exists('featured_media',$fields)) { $fields['featured_media']?update_post_meta($result,'_thumbnail_id',$fields['featured_media']):delete_post_thumbnail($result); }
        if ($type==='post') { foreach (['categories'=>'category','tags'=>'post_tag'] as $key=>$tax) { if (isset($fields[$key])) { wp_set_object_terms($result,$fields[$key],$tax); } } }
        return $this->post($type,$result);
    }
    public function media(int $id): array {
        $p=get_post($id);
        if (!$p || $p->post_type!=='attachment') { throw new Failure('media_missing','The media item was not found.',404); }
        $m=get_post_meta($id,'_dashless_private_media',true);
        return ['id'=>$id,'mime_type'=>$p->post_mime_type,'title'=>$p->post_title,'caption'=>$p->post_excerpt,'description'=>$p->post_content,'alt_text'=>(string)get_post_meta($id,'_wp_attachment_image_alt',true),'media_details'=>wp_get_attachment_metadata($id)?:[],'private'=>!!$m];
    }
    public function tool(string $name,array $a,int $owner): array {
        switch ($name) {
            case 'inspect_site': return ['content'=>['posts'=>(int)wp_count_posts('post')->publish,'pages'=>(int)wp_count_posts('page')->publish],'generation'=>$this->generation(),'design'=>$this->design(),'site_url'=>home_url('/')];
            case 'get_post': return $this->post($a['post_type'],$a['id']);
            case 'list_posts':
                $statuses=isset($a['status'])?explode(',',$a['status']):['publish','draft','pending','future','private'];
                if (array_diff($statuses,['publish','draft','pending','future','private'])) { throw new Failure('invalid_status','Unsupported post status.',400); }
                $q=new \WP_Query(['post_type'=>$a['post_type'],'post_status'=>$statuses,'s'=>$a['search']??'','paged'=>$a['page']??1,'posts_per_page'=>$a['per_page']??20,'orderby'=>'modified','order'=>'DESC']);
                return ['items'=>array_map(fn($p)=>$this->post($a['post_type'],$p->ID),$q->posts),'total'=>(int)$q->found_posts];
            case 'create_draft': return ['saved'=>true,'post'=>$this->save($a['post_type'],$this->clean($a))];
            case 'update_draft':
                $p=$this->current($a);
                if (!in_array($p['status'],['draft','pending','future'],true)) { throw new Failure('stage_required','Stage published content before changing it.'); }
                // Future posts become drafts; hosted publication always needs a human approval.
                return ['saved'=>true,'post'=>$this->save($a['post_type'],$this->clean($a),$a['id'],'draft')];
            case 'stage_update': case 'stage_revision_restore':
                $p=$this->current($a);$changes=$a['changes']??[];
                if ($name==='stage_revision_restore') {
                    $r=wp_get_post_revision($a['revision_id']);
                    if (!$r || (int)$r->post_parent!==$a['id']) { throw new Failure('revision_missing','The revision does not belong to this item.',404); }
                    $changes=['title'=>$r->post_title,'content'=>$r->post_content,'excerpt'=>$r->post_excerpt];
                }
                $id=wp_generate_uuid4();$record=['change_id'=>$id,'post_type'=>$a['post_type'],'post_id'=>$a['id'],'base_digest'=>$p['digest'],'payload'=>array_replace($p,$this->clean($changes))];
                $record['hash']=Support::hash($record);$this->app->store->put('change',$id,$record,$owner);
                return ['saved'=>true,'change_id'=>$id,'post_id'=>$a['id'],'digest'=>$record['hash']];
            case 'list_revisions':
                $this->current($a);$items=[];
                foreach (wp_get_post_revisions($a['id'],['posts_per_page'=>$a['per_page']??20]) as $r) { $items[]=['id'=>$r->ID,'date_gmt'=>$r->post_date_gmt,'title'=>$r->post_title,'content'=>$r->post_content,'excerpt'=>$r->post_excerpt]; }
                return ['items'=>$items];
            case 'list_terms':
                $tax=$a['taxonomy']==='tag'?'post_tag':'category';$n=$a['per_page']??20;
                $terms=get_terms(['taxonomy'=>$tax,'hide_empty'=>false,'search'=>$a['search']??'','number'=>$n,'offset'=>(($a['page']??1)-1)*$n]);
                return ['items'=>is_wp_error($terms)?[]:array_map(fn($t)=>['id'=>$t->term_id,'name'=>$t->name,'slug'=>$t->slug,'parent'=>$t->parent],$terms)];
            case 'ensure_terms':
                $tax=$a['taxonomy']==='tag'?'post_tag':'category';$items=[];
                foreach ($a['names'] as $name) { $name=sanitize_text_field($name);$term=term_exists($name,$tax)?:wp_insert_term($name,$tax);if (is_wp_error($term)) { throw new Failure('term_failed','WordPress could not save a term.',400); }$items[]=['id'=>(int)(is_array($term)?$term['term_id']:$term),'name'=>$name]; }
                return ['items'=>$items];
            case 'list_media':
                $q=new \WP_Query(['post_type'=>'attachment','post_status'=>'inherit','s'=>$a['search']??'','post_mime_type'=>$a['media_type']??'','paged'=>$a['page']??1,'posts_per_page'=>$a['per_page']??20]);
                return ['items'=>array_map(fn($p)=>$this->media($p->ID),$q->posts),'total'=>(int)$q->found_posts];
            case 'update_media':
                $this->media($a['id']);$p=['ID'=>$a['id']];
                foreach (['title'=>'post_title','caption'=>'post_excerpt','description'=>'post_content'] as $key=>$field) { if (isset($a[$key])) { $p[$field]=wp_kses_post($a[$key]); } }
                if (is_wp_error(wp_update_post(wp_slash($p),true))) { throw new Failure('save_failed','WordPress could not save the media details.',503); }
                if (isset($a['alt_text'])) { update_post_meta($a['id'],'_wp_attachment_image_alt',sanitize_text_field($a['alt_text'])); }
                return ['saved'=>true,'media'=>$this->media($a['id'])];
            case 'list_themes': return ['themes'=>array_map(fn($theme)=>$this->themePreview($theme),$this->themes())];
            case 'get_theme': return $this->themePreview($this->theme($a['theme_id']));
            case 'get_design': return $this->design();
            case 'update_design':
                $design=$this->design();
                if ($design['version']!==$a['expected_version']) { throw new Failure('stale_design','The design changed. Read the current version before editing.'); }
                $changes=$a['changes'];
                if (isset($changes['theme_id']) || isset($changes['theme_version'])) {
                    $theme=$this->theme($changes['theme_id']??'');
                    if (($changes['theme_version']??null)!==$theme['version']) { throw new Failure('theme_version','Read get_theme and use its current version.',400); }
                    $changes=array_replace($theme['defaults'],$changes);
                }
                foreach (['site_title','description'] as $key) { if (isset($changes[$key])) { $changes[$key]=sanitize_text_field($changes[$key]); } }
                if (!empty($changes['logo_media_id'])) { $this->media($changes['logo_media_id']); }
                foreach ($changes['navigation']??[] as &$link) { $this->post('page',$link['page_id']);$link['label']=sanitize_text_field($link['label']); }unset($link);
                $design=array_replace($design,$changes,['version'=>$design['version']+1]);$this->app->store->put('design','current',$design,$owner);
                return ['saved'=>true,'design'=>$design,'deployed'=>false];
        }
        throw new Failure('tool_missing','Unknown editorial tool.',404);
    }
    public function target(array $args,int $owner): ?array {
        if (!empty($args['change_id'])) {
            $c=$this->app->store->owned('change',$args['change_id'],$owner);$p=$this->post($c['post_type'],$c['post_id']);
            if (!hash_equals($c['base_digest'],$p['digest']) || (isset($args['id']) && $args['id']!==$p['id']) || (isset($args['post_type']) && $args['post_type']!==$p['post_type'])) { throw new Failure('stale_change','The staged edit no longer matches its WordPress item.'); }
            return ['before'=>$p,'after'=>$c['payload'],'change_id'=>$c['change_id'],'change_hash'=>$c['hash']];
        }
        if (isset($args['id']) || isset($args['post_type'])) {
            if (!isset($args['id'],$args['post_type'])) { throw new Failure('target_missing','Supply both post type and item ID.',400); }
            $p=$this->post($args['post_type'],$args['id']);return ['before'=>$p,'after'=>$p];
        }
        return null;
    }
    /** Expensive extraction is CLI-only. The before/after generation and asset hashes bind the snapshot. */
    public function snapshot(array $args,int $owner,string $workspace,bool $export=false,bool $lazy=false): array {
        $generation=$this->generation();$design=$this->design();$target=$export?null:$this->target($args,$owner);
        if (isset($args['design_version']) && $design['version']!==$args['design_version']) { throw new Failure('stale_design','The requested design changed.'); }
        $items=[];$baseline=[];
        foreach (['post','page'] as $type) {
            foreach (get_posts(['post_type'=>$type,'post_status'=>$export?['publish','draft','pending','private','future']:['publish'],'posts_per_page'=>-1,'orderby'=>'ID','order'=>'ASC']) as $p) { $post=$this->post($type,$p->ID);$baseline[]=$post;$items[$type.':'.$p->ID]=$post; }
        }
        if ($target) { $items[$target['after']['post_type'].':'.$target['after']['id']]=$target['after']; }
        $terms=[];foreach (['category','post_tag'] as $tax) { $ts=get_terms(['taxonomy'=>$tax,'hide_empty'=>false]);$terms[$tax]=is_wp_error($ts)?[]:array_map(fn($t)=>['id'=>$t->term_id,'slug'=>$t->slug,'name'=>$t->name,'description'=>$t->description,'parent'=>$t->parent],$ts); }
        $media=[];$assets=[];
        $uploads=wp_upload_dir(null,false);$uploadRoot=realpath($uploads['basedir']);
        if (!is_dir($workspace.'/media')) { wp_mkdir_p($workspace.'/media'); }
        foreach (get_posts(['post_type'=>'attachment','post_status'=>'inherit','posts_per_page'=>-1,'orderby'=>'ID','order'=>'ASC']) as $p) {
            $m=$this->media($p->ID);$private=get_post_meta($p->ID,'_dashless_private_media',true);$sources=[];
            if ($private) { $name=$private['filename'];$dest=$workspace.'/media/'.$p->ID.'-'.$name;if (!$lazy) { $this->app->vault()->materialize($private['key'],$dest); }$sources[]=['path'=>$dest,'url'=>rest_url('dashless-hosted/v1/media/'.$p->ID),'name'=>$name,'private'=>$private]; }
            else {
                $file=get_attached_file($p->ID);$real=$file?realpath($file):false;
                if (!$real || !$uploadRoot || !str_starts_with($real,$uploadRoot.'/') || is_link($file) || !is_file($real)) { throw new Failure('media_source_invalid','A media file is missing or outside the local library.'); }
                $sources[]=['path'=>$real,'url'=>wp_get_attachment_url($p->ID),'name'=>basename($real)];
                foreach (($m['media_details']['sizes']??[]) as $size) { $f=realpath(dirname($real).'/'.basename($size['file']));if (!$f || !str_starts_with($f,$uploadRoot.'/')) { throw new Failure('media_source_invalid','A media size is missing.'); }$sources[]=['path'=>$f,'url'=>dirname(wp_get_attachment_url($p->ID)).'/'.basename($size['file']),'name'=>basename($f)]; }
            }
            foreach ($sources as $source) {
                $rel='media/'.$p->ID.'-'.sanitize_file_name($source['name']);$dest=$workspace.'/'.$rel;
                if ($lazy) {
                    $assets[$rel]=['bytes'=>$source['private']['bytes']??filesize($source['path']),'url'=>$source['url'],'media_id'=>$p->ID];
                    $assets[$rel]['_source']=isset($source['private'])?['key'=>$source['private']['key']]:['path'=>$source['path'],'mtime'=>filemtime($source['path'])];$m['files'][]=$rel;continue;
                }
                if ($source['path']!==$dest && !copy($source['path'],$dest)) { throw new Failure('media_copy_failed','A local media file could not be copied.',503); }
                $assets[$rel]=['sha256'=>hash_file('sha256',$dest),'bytes'=>filesize($dest),'url'=>$source['url'],'media_id'=>$p->ID];
                $m['files'][]=$rel;
            }
            $media[]=$m;
        }
        // Render in the staged post context without writing it to WordPress.
        $previousPost=$GLOBALS['post']??null;
        try {
            foreach ($items as &$item) {
                $context=clone get_post($item['id']);
                foreach (['title'=>'post_title','content'=>'post_content','excerpt'=>'post_excerpt'] as $field=>$property) { $context->$property=$item[$field]; }
                $GLOBALS['post']=$context;setup_postdata($context);
                $item['rendered']=['title'=>get_the_title($context),'content'=>wp_kses_post(apply_filters('the_content',$item['content'])),'excerpt'=>wp_kses_post(apply_filters('the_excerpt',$item['excerpt']))];
            }unset($item);
        } finally { $GLOBALS['post']=$previousPost;if ($previousPost) { setup_postdata($previousPost); } else { wp_reset_postdata(); } }
        $settings=['homePageId'=>get_option('show_on_front')==='page'?(int)get_option('page_on_front'):0,'postsPageId'=>(int)get_option('page_for_posts'),'language'=>get_bloginfo('language')];
        ksort($assets);$items=array_values($items);
        $result=['frontend_contract'=>1,'settings'=>$settings,'generation'=>$generation,'design'=>$design,'items'=>$items,'terms'=>$terms,'media'=>$media,'assets'=>$assets,'target'=>$target,'site_url'=>home_url('/'),'account_id'=>$owner,'site_id'=>$this->app->siteId(),'content_hash'=>Support::hash(['posts'=>$baseline,'terms'=>$terms,'target'=>$target,'rendered'=>$items,'settings'=>$settings]),'design_hash'=>Support::hash($design),'assets_hash'=>Support::hash(['assets'=>$assets,'media'=>$media])];
        if ($generation!==$this->generation() || $design!==$this->design()) { throw new Failure('snapshot_changed','Content changed during the snapshot. Create a new preview.'); }
        return $result;
    }
    public function binding(array $snapshot,string $candidate): array {
        return ['site_id'=>$this->app->siteId(),'account_id'=>$snapshot['account_id'],'generation'=>$snapshot['generation'],'content_hash'=>$snapshot['content_hash'],'design_version'=>$snapshot['design']['version'],'design_hash'=>$snapshot['design_hash'],'assets_hash'=>$snapshot['assets_hash'],'candidate'=>$candidate];
    }
    public function assertCurrent(array $preview,bool $full=false,?string $workspace=null): void {
        $b=$preview['binding'];
        if ($b['site_id']!==$this->app->siteId() || $b['generation']!==$this->generation() || $b['design_hash']!==Support::hash($this->design())) { throw new Failure('stale_approval','The preview changed. Create and approve a new preview.'); }
        $this->target($preview['arguments'],$b['account_id']);
        if ($full) { $current=$this->snapshot($preview['arguments'],$b['account_id'],$workspace);if ($this->binding($current,$b['candidate'])!==$b) { throw new Failure('stale_approval','The content or media changed after this preview.'); } }
    }
}
