<?php
$themes = [
    ['id'=>'hypertext-diary','name'=>'Hypertext Diary','kicker'=>'01 / PLAYFUL WEB','description'=>'A handmade browser-window journal for links, fragments, references, and a mind that refuses to stay in one tab.','best_for'=>'Personal blogs · creative journals · internet culture','image'=>'theme-preview-hypertext-diary.png','class'=>'hyper'],
    ['id'=>'field-notes','name'=>'Field Notes','kicker'=>'02 / QUIET EDITORIAL','description'=>'A literary notebook for walks, places, recipes, and the small observations worth keeping.','best_for'=>'Essays · travel journals · independent writers','image'=>'theme-preview-field-notes.png','class'=>'field'],
    ['id'=>'after-hours','name'=>'After Hours','kicker'=>'03 / NOCTURNAL MAGAZINE','description'=>'A late-night culture desk for city lights, music, photography, and the thoughts that arrive after dark.','best_for'=>'Music · culture · photography · design','image'=>'theme-preview-after-hours.png','class'=>'after'],
    ['id'=>'bulletin','name'=>'Bulletin','kicker'=>'04 / CONSIDERED PRINT','description'=>'A restrained independent publication where dispatches are treated like a small printed journal.','best_for'=>'Independent magazines · essays · cultural reporting','image'=>'theme-preview-bulletin.png','class'=>'bulletin'],
    ['id'=>'sunroom','name'=>'Sunroom','kicker'=>'05 / WARM READING ROOM','description'=>'A sunny reading room where a publication feels collected, human, and close at hand.','best_for'=>'Personal essays · lifestyle journals · small magazines','image'=>'theme-preview-sunroom.png','class'=>'sunroom'],
    ['id'=>'mono-press','name'=>'Mono Press','kicker'=>'06 / DIRECT PRINT DESK','description'=>'A confident black-and-white newspaper grid translated to the open web.','best_for'=>'Newsletters · culture writing · design studios','image'=>'theme-preview-mono-press.png','class'=>'mono'],
];
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class('wp-site-blocks'); ?>>
<?php wp_body_open(); echo do_blocks('<!-- wp:template-part {"slug":"header","tagName":"header"} /-->'); ?>
<main class="dl-themes-page" aria-labelledby="dl-themes-title">
  <section class="dl-themes-intro">
    <div class="dl-themes-intro-top"><p class="dl-eyebrow">THEMES FOR WRITERS</p><span class="dl-theme-count">06 EDITIONS</span></div>
    <div class="dl-themes-intro-main"><h1 id="dl-themes-title">Choose your <em>atmosphere.</em></h1><div class="dl-themes-lede"><p>Six editions, one reader-first foundation.</p><small>Same thoughtful core. Six different moods for the stories you want to tell.</small></div></div>
  </section>
  <section class="dl-theme-showcase" aria-labelledby="dl-theme-showcase-title">
    <div class="dl-theme-showcase-head"><h2 id="dl-theme-showcase-title">Find the one that feels like you.</h2><p>Every theme includes the full publication: home, archive, article, search, taxonomy, RSS, and 404.</p><small>Pick a direction here, then ask ChatGPT to make it yours. Your words always stay in WordPress.</small></div>
    <div class="dl-theme-showcase-grid">
      <?php foreach ($themes as $theme) : ?>
        <article class="dl-theme-showcase-card dl-theme-showcase-card--<?php echo esc_attr($theme['class']); ?>">
          <div class="dl-theme-showcase-copy">
            <p class="dl-theme-showcase-kicker"><?php echo esc_html($theme['kicker']); ?></p>
            <h3><?php echo esc_html($theme['name']); ?></h3>
            <p><?php echo esc_html($theme['description']); ?></p>
            <small><?php echo esc_html($theme['best_for']); ?></small>
            <a class="dl-theme-showcase-link" href="<?php echo esc_url(home_url('/#account')); ?>">Choose this direction in your account <span aria-hidden="true">↗</span></a>
          </div>
          <a class="dl-theme-showcase-image" href="<?php echo esc_url(home_url('/#account')); ?>" aria-label="Choose <?php echo esc_attr($theme['name']); ?>">
            <span class="dl-theme-window-bar" aria-hidden="true"><i></i><i></i><i></i></span><img src="<?php echo esc_url(get_theme_file_uri('assets/'.$theme['image'])); ?>" width="1280" height="1000" loading="lazy" alt="<?php echo esc_attr($theme['name']); ?> sample publication homepage">
          </a>
        </article>
      <?php endforeach; ?>
    </div>
  </section>
  <section class="dl-themes-close" aria-label="Theme selection call to action">
    <p class="dl-eyebrow">SAME STORIES. DIFFERENT ATMOSPHERES.</p>
    <h2>A calmer internet is<br><em>a brighter one.</em></h2>
    <a class="dl-paper-button" href="<?php echo esc_url(home_url('/#account')); ?>">See the collection <span aria-hidden="true">↗</span></a>
  </section>
</main>
<?php echo do_blocks('<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->'); wp_footer(); ?>
</body>
</html>
