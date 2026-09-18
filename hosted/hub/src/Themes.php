<?php
namespace Dashless\Hub;

/** Public presentation metadata; no customer data or provisioning required. */
final class Themes {
    public static function catalog(): array {
        $file=dirname(__DIR__).'/contracts/themes.v1.json';
        if(!is_file($file))$file=dirname(__DIR__,3).'/templates/astro/src/lib/themes.json';
        $themes=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
        return array_map(function($theme){
            $theme['preview_image']=set_url_scheme(plugins_url('assets/theme-previews/'.$theme['preview_image'],dirname(__DIR__).'/dashless-hub.php'),wp_get_environment_type()==='local'?'http':'https');
            $theme['preview_note']='Illustrative sample content. Use create_preview to see your own site before publishing.';
            return $theme;
        },$themes);
    }
    public static function get(string $id): array {
        foreach(self::catalog() as $theme)if($theme['id']===$id)return $theme;
        throw new Failure('theme_missing','That theme is not available. Use list_themes.',404);
    }
}
