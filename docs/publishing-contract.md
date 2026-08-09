# Publishing contract

Dashless separates five states that conventional “publish” buttons often blur together:

1. Saved in WordPress
2. Preview built
3. Published in WordPress
4. Production site built and deployed
5. Public page verified

The plugin reports every state independently.

## Content provenance

WordPress is the only production source for Posts, Pages, media, terms, and editorial metadata. A request to design, preview, deploy, or launch the Astro site grants no permission to create or rewrite those objects. Dashless renders an honest empty state when WordPress has no published content; it does not seed starter content.

Codex may create or revise content only after an explicit editorial request. New authored content is saved as a WordPress draft and follows the same preview-lock and publication approval rules. Theme demos, screenshots, test fixtures, and generated sample copy remain local design or test material and are never imported into WordPress or included as deployable fallback editorial content.

## Preview locks

When Dashless previews content, it creates a SHA-256 digest from the stable editorial fields: post type and ID, slug, raw title, raw semantic HTML, raw excerpt, featured media ID, category IDs, and tag IDs. Status and modified timestamps are intentionally excluded so a draft can transition to published without changing the approved payload.

The preview record also stores WordPress's `modified_gmt`. Publishing fails when:

- the preview build did not complete;
- the parent content changed after preview;
- the staged payload no longer matches the approved digest;
- the preview token was already used; or
- the preview token belongs to another connected site.

A successful token is single-use.

## Existing published content

Dashless does not edit a published parent while a change is being prepared. `stage_update` stores a local changeset tied to the parent's current `modified_gmt`. The changeset can be built with the real Astro templates. Only `publish_previewed` applies it to WordPress, which lets WordPress create a normal revision at publication time.

Restoration works the same way. `stage_revision_restore` copies an older revision into a changeset; it never overwrites the parent directly.

## Failure behavior

Static deployment is release-based. Build output is copied into a new directory before the active release changes. Conventional deployments atomically switch a `current` symlink; WP Cloud atomically changes the bridge's active-release option after the complete directory is uploaded. A failed build, copy, or activation leaves the prior site intact.

WordPress publication and static deployment cannot form one distributed transaction. If WordPress accepts publication but the production build or deployment fails, Dashless reports that partial state and leaves the prior static release online. Retrying deployment does not republish or duplicate the WordPress post.
