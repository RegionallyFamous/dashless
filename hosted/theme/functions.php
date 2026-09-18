<?php
add_action('after_setup_theme',function(){add_theme_support('editor-styles');add_editor_style('assets/site.css');});
add_action('wp_enqueue_scripts',function(){wp_enqueue_style('dashless-site',get_theme_file_uri('assets/site.css'),[],filemtime(get_theme_file_path('assets/site.css')));});
add_action('wp_enqueue_scripts',function(){if(is_front_page())wp_enqueue_script('dashless-editorial',get_theme_file_uri('assets/editorial.js'),[],filemtime(get_theme_file_path('assets/editorial.js')),['strategy'=>'defer','in_footer'=>true]);});
add_action('init',function(){register_block_pattern_category('dashless',['label'=>'Dashless']);});

// Give the theme collection a real, shareable public route instead of hiding it
// inside the marketing homepage anchor. The route remains a static theme view;
// it does not read or mutate customer publication content.
add_action('init',function(){add_rewrite_rule('^themes/?$','index.php?dashless_themes=1','top');});
add_filter('query_vars',function($vars){$vars[]='dashless_themes';return $vars;});
add_filter('request',function($query_vars){$path=trim((string)parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH),'/');if($path==='themes'){$query_vars['dashless_themes']=1;}return $query_vars;});
add_filter('template_include',function($template){if(get_query_var('dashless_themes')){global $wp_query;$wp_query->is_404=false;status_header(200);nocache_headers();return get_theme_file_path('templates/themes.php');}return $template;});
add_action('after_switch_theme',function(){flush_rewrite_rules();});

add_filter('render_block_core/site-title',function($html,$block){
    if(str_contains($block['attrs']['className']??'','dl-wordmark'))return '<p class="wp-block-site-title dl-wordmark"><a href="'.esc_url(home_url('/')).'" rel="home"><img class="dl-rip" src="'.esc_url(get_theme_file_uri('assets/rip.svg')).'" alt="" width="56" height="56"><span class="dl-hot-type" role="img" aria-label="dashless"></span></a></p>';
    return $html;
},10,2);
add_action('wp_head',fn()=>print('<link rel="icon" type="image/svg+xml" href="'.esc_url(get_theme_file_uri('assets/favicon.svg')).'">'));

// Template parts are static HTML, so resolve the footer artwork at render time.
// This keeps the theme safe when WordPress installs it under a renamed directory
// or on a subdirectory site instead of relying on a guessed /wp-content path.
add_filter('render_block_core/html',function($html){
    if(!str_contains($html,'dl-footer'))return $html;
    $legacy='src="/wp-content/themes/dashless/assets/studio-minimal-night-hd.png"';
    $replacement='src="'.esc_url(get_theme_file_uri('assets/webp/studio-minimal-night-hd.webp')).'"';
    if(str_contains($html,$legacy))$html=str_replace($legacy,$replacement,$html);
    foreach(['/#account','/#support','/#privacy','/#terms'] as $path)$html=str_replace('href="'.$path.'"','href="'.esc_url(home_url($path)).'"',$html);
    return $html;
});

// Version every theme image URL emitted by block content. WordPress can keep
// rendered block HTML at the edge longer than the filesystem mtime, so an
// image replacement must carry the same deterministic cache key as CSS/JS.
add_filter('render_block',function($html,$block){
    $base=trailingslashit(get_theme_file_uri('assets/'));
    $root=trailingslashit(get_theme_file_path('assets/'));
    return preg_replace_callback('#'.preg_quote($base,'#').'([^"\'\s>,]+)#',function($m)use($root){
        $relative=rawurldecode($m[1]);
        if (str_contains($relative,'?') || str_contains($relative,'#')) return $m[0];
        $file=realpath($root.$relative);
        if (!$file || !is_file($file) || !str_starts_with($file,realpath($root))) return $m[0];
        return $m[0].'?ver='.rawurlencode((string)filemtime($file));
    },$html);
},20,2);

// Header links are stored in a block template part, so make them work on
// subdirectory installs as well as the production root domain.
add_filter('render_block_core/group',function($html,$block){
    if(!str_contains((string)($block['attrs']['className']??''),'dl-masthead'))return $html;
    foreach(['/#how-it-works','/themes/','/#account'] as $path)$html=str_replace('href="'.$path.'"','href="'.esc_url(home_url($path)).'"',$html);
    return $html;
},10,2);

// Give the homepage invitation a voice while preserving the Hub's sign-in destination.
add_filter('render_block_dashless/login-button', function($html) {
    if (!is_front_page()) return $html;
    return str_replace(
        ['<span>Continue with email</span>', 'Your email. Your blog. No WordPress account needed.'],
        ['<span>Make room for your ideas</span>', 'Start with your email. No WordPress account needed.'],
        $html
    );
});
