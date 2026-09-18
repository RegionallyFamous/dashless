export default {
 siteName: "The Department of Small Problems",
 siteDescription: "No concern too minor. No investigation on schedule.",
 wordpressUrl: "https://smallproblems.dashless.blog", publicUrl: process.env.DASHLESS_DEMO_URL || "https://smallproblems.dashless.blog",
 design: { theme_id: process.env.DASHLESS_DEMO_THEME || "bulletin", theme_version: 1, palette: "paper", typography: "editorial", layout: "journal" },
 postsPath:"stories", topicsPath:"topics", tagsPath:"tags", postsPerPage:6,
 mirrorMedia:true, homePageId:0, postsPageId:0,
 navigation:[{page_id:101,label:"About"}],
};
