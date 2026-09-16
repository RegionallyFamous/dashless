<?php
add_action('after_setup_theme',function(){add_theme_support('editor-styles');add_editor_style('assets/site.css');});
add_action('wp_enqueue_scripts',function(){wp_enqueue_style('dashless-site',get_theme_file_uri('assets/site.css'),[],filemtime(get_theme_file_path('assets/site.css')));});
add_action('wp_enqueue_scripts',function(){if(is_front_page())wp_enqueue_script('dashless-walkthrough',get_theme_file_uri('assets/walkthrough.js'),[],filemtime(get_theme_file_path('assets/walkthrough.js')),['strategy'=>'defer','in_footer'=>true]);});
add_action('init',function(){register_block_pattern_category('dashless',['label'=>'Dashless']);});

add_filter('render_block_core/site-title',function($html,$block){
    if(str_contains($block['attrs']['className']??'','dl-wordmark'))return '<p class="wp-block-site-title dl-wordmark"><a href="'.esc_url(home_url('/')).'" rel="home"><img class="dl-rip" src="'.esc_url(get_theme_file_uri('assets/rip.svg')).'" alt="" width="56" height="56"><span class="dl-hot-type" role="img" aria-label="dashless"></span></a></p>';
    return $html;
},10,2);
add_action('wp_head',fn()=>print('<link rel="icon" type="image/svg+xml" href="'.esc_url(get_theme_file_uri('assets/favicon.svg')).'">'));
