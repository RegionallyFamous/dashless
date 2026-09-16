import rss from "@astrojs/rss";
import { config, getPosts, plainText } from "../lib/dashless.mjs";

export async function GET() {
  const posts = await getPosts();
  return rss({
    title: config.siteName,
    description: config.siteDescription,
    site: config.publicUrl,
    items: posts.map((post) => ({
      title: post.title,
      pubDate: new Date(post.date),
      description: plainText(post.excerpt || post.content).slice(0, 240),
      link: post.url,
    })),
  });
}
