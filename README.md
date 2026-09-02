# Dashless

[![Dashless 1.0 release gate](https://github.com/RegionallyFamous/dashless/actions/workflows/ci.yml/badge.svg)](https://github.com/RegionallyFamous/dashless/actions/workflows/ci.yml)
[![Version 1.0.0](https://img.shields.io/badge/version-1.0.0-E14B32)](CHANGELOG.md)
[![MIT licensed](https://img.shields.io/badge/license-MIT-221C2B)](LICENSE)

## WordPress without the dashboard.

**Talk to your publication. Preview the real site. Publish exactly what you approved. Own every layer.**

Dashless is for people who still believe in WordPress—and are finished living inside WordPress. Local Codex becomes the editorial room. WordPress keeps the canonical content, media, taxonomy, and revisions. Astro becomes the fast, joyful public site.

The dashboard does not disappear. It becomes what it should have been all along: a break-glass administration surface, not your daily workplace.

![A teddy editor joyfully turns WordPress content into a bright personal website through a conversational computer](assets/readme/dashless-hero.png)

> No Dashless cloud. No remote app. No proprietary CMS. No analytics. No theme account. Just your WordPress, your Astro code, and a careful local publishing workflow.

## The dashboard is not mandatory. It is obsolete.

Headless WordPress solved only half the problem. It gave readers a new frontend, then sent writers right back to the same dashboard.

Dashless changes the editorial side too. Ask for a draft. Shape the structure. Find an old revision. Repair an image description. Move a page. Open the actual Astro route. Keep revising until it feels right. Then approve the version you saw.

`wp-admin` stays available for accounts, plugins, and emergencies. It simply stops being the place where publishing has to happen.

![A teddy editor walks away from an enormous dusty dashboard console toward one simple conversation and a clean website](assets/readme/dashboard-obsolete.png)

## This is what publishing feels like now

> **You:** Draft a post about why the dashboard is obsolete. Make the argument exciting, but keep it in my voice.

> **Codex:** It is saved as a WordPress draft. I also found two images in the media library that fit the piece. Want me to use one or make something new?

> **You:** Make something new. Give it the strange retro energy of the site, add useful alt text, and show me the post where it will actually live.

> **Codex:** The complete Astro preview is open. Nothing is public yet.

> **You:** Tighten the ending. Then publish exactly this version.

That is the product. The conversation is the interface, WordPress is the memory, and the website you review is the website Dashless is prepared to ship.

## Publish what you saw. Nothing else.

“AI-powered publishing” should not mean “hope the agent changed the right thing.” Dashless makes approval concrete.

Every editorial preview is locked to the exact WordPress revision and content digest that produced it. If the post changes, another editor changes the site, the build sees two different content generations, or the preview token has already been used, publication stops.

Production is just as careful: a new static release is built away from the live site, every file is verified, and only then does the active pointer move. A failed WP Cloud public check automatically restores the previous verified release.

![A teddy editor compares an exact visual fingerprint before sealing a website preview into a protected release](assets/readme/exact-preview.png)

Fast is good. Reversible is better. Dashless is both.

## Your theme does not get to write your blog

Dashless never invents posts to make a design look populated. A request to design, preview, deploy, or launch is permission to work on the site—not permission to manufacture your voice.

If WordPress is empty, the public site has an intentional empty state. New writing appears only after an explicit editorial request, and it begins as a WordPress draft.

The Astro project contains presentation code. **WordPress remains the only production source of editorial content.**

## What ships in 1.0

- **A conversational WordPress newsroom.** Work with posts, nested pages, categories, tags, media, accessible metadata, and revisions without making the dashboard your default environment.
- **A genuinely owned Astro publication.** Pages, story archives, topics, tags, search, mirrored media, social cards, RSS, sitemap, structured metadata, responsive layouts, dark mode, and a wonderfully odd retro theme.
- **Publication you can trust.** Draft-only creation, stale-write protection, staged edits to live content, exact previews, single-use approval, content-generation locks, atomic releases, public verification, and rollback.
- **Deployment that meets you where you are.** Build to a local release directory, an SSH/rsync server, or an existing WP Cloud site.
- **Small, human reader signals.** Optional private notes, double-opt-in story notifications, count-free reactions, and moderated Webmentions—without turning your personal site into a growth dashboard.
- **A system other agents can learn.** Packaged skills teach the editorial contract, Astro design workflow, visual QA passes, and release criteria.

Everything is local-first. The generated Astro project is normal source code. Restyle it, extend it, move it, or replace the whole visual layer. There is no Dashless runtime holding it hostage.

## Yes, this works on WP Cloud

It sounds like Astro should need a second server. It does not.

Dashless runs Astro locally as a static compiler, seals the build into an immutable release, uploads it to the existing WP Cloud site, and installs a tiny WordPress companion as a must-use plugin. Readers receive the Astro publication while WordPress REST, login, cron, and emergency administration continue working on the same host.

Each candidate release is verified before activation. The old one stays beside it, ready for rollback.

![A teddy editor sends a sealed stack of static pages into a cloud station that switches safely between immutable website releases](assets/readme/wpcloud-release.png)

For an existing WP Cloud site, you need a dedicated WordPress Application Password and key-enabled SSH/SFTP access. You do **not** need a WP Cloud API key. Site purchasing, billing, provisioning, and DNS registration are outside Dashless 1.0.

The complete setup, routing model, preflight, domain guidance, and recovery flow live in the [WP Cloud deployment guide](https://github.com/RegionallyFamous/dashless/wiki/Deployment).

## Try the weird future

Dashless 1.0 supports macOS and Linux, Node.js 22.12 or newer, and HTTPS single-site WordPress installations with Application Passwords.

```sh
git clone https://github.com/RegionallyFamous/dashless.git
cd dashless
npm run release
```

Add the checkout or `dist/dashless-1.0.0.zip` to a local Codex marketplace. Then open a local task and say:

> Connect my WordPress site to Dashless.

That is the last time setup should feel like setup. From there, ask for the publication you want.

## Technical documentation

The README is the invitation. The [Dashless wiki](https://github.com/RegionallyFamous/dashless/wiki) is the complete operating manual:

- [Getting started](https://github.com/RegionallyFamous/dashless/wiki/Getting-Started)
- [Architecture](https://github.com/RegionallyFamous/dashless/wiki/Architecture)
- [Editorial workflow](https://github.com/RegionallyFamous/dashless/wiki/Editorial-Workflow)
- [Publishing safety](https://github.com/RegionallyFamous/dashless/wiki/Publishing-Safety)
- [Deployment](https://github.com/RegionallyFamous/dashless/wiki/Deployment)
- [Astro frontend](https://github.com/RegionallyFamous/dashless/wiki/Astro-Frontend)
- [Optional reader signals](https://github.com/RegionallyFamous/dashless/wiki/Optional-Reader-Signals)
- [Privacy and security](https://github.com/RegionallyFamous/dashless/wiki/Privacy-and-Security)
- [Support and scope](https://github.com/RegionallyFamous/dashless/wiki/Support-and-Scope)
- [Development and releases](https://github.com/RegionallyFamous/dashless/wiki/Development-and-Releases)

OpenAI documents local marketplaces as the development path for installable plugins in [Build plugins](https://learn.chatgpt.com/docs/build-plugins).

Security issues should follow the [private reporting policy](SECURITY.md). Dashless is [MIT licensed](LICENSE).
